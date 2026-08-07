<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptor;
use App\Services\Backup\BackupKeyring;
use Tests\TestCase;

/**
 * Chiffrement des sauvegardes.
 *
 * Le dump contient le registre complet : noms, dates de naissance, numéros de
 * documents. Ces tests verrouillent la garantie centrale — rien d'exploitable
 * ne quitte le conteneur sans la clé.
 */
class BackupEncryptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/qayed-enc-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        config([
            'backup.encryption.key_id' => 'k1',
            'backup.encryption.key' => base64_encode(str_repeat('A', 32)),
            'backup.encryption.previous_keys' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function encryptor(): BackupEncryptor
    {
        return new BackupEncryptor(new BackupKeyring());
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    // ── La garantie principale ───────────────────────────────────────────────

    public function test_encrypted_file_contains_no_plaintext(): void
    {
        // Contenu représentatif d'un vrai dump : c'est cela qu'il ne faut pas
        // retrouver en clair.
        $sql = "COPY guests (first_name, last_name, document_number) FROM stdin;\n"
            ."Mohamed\tMathlouthi\t12345678\n";

        $plain = $this->write('dump.sql', $sql);
        $encrypted = $this->dir.'/dump.sql.enc';

        $this->encryptor()->encryptFile($plain, $encrypted);

        $onDisk = file_get_contents($encrypted);

        $this->assertStringNotContainsString('Mathlouthi', $onDisk, 'Un nom de voyageur est lisible en clair.');
        $this->assertStringNotContainsString('12345678', $onDisk, 'Un numéro de document est lisible en clair.');
        $this->assertStringNotContainsString('COPY guests', $onDisk, 'Du SQL est lisible en clair.');
    }

    public function test_round_trip_restores_the_exact_bytes(): void
    {
        // Plus gros qu'un bloc de chiffrement (1 Mio) pour exercer le mode flux.
        $content = random_bytes(1024 * 1024 + 4096);
        $plain = $this->write('big.bin', $content);

        $this->encryptor()->encryptFile($plain, $this->dir.'/big.enc');
        $this->encryptor()->decryptFile($this->dir.'/big.enc', $this->dir.'/back.bin');

        $this->assertSame(
            hash('sha256', $content),
            hash_file('sha256', $this->dir.'/back.bin'),
            'Le contenu restauré diffère de l\'original.'
        );
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $plain = $this->write('dump.sql', str_repeat('donnees sensibles', 100));
        $this->encryptor()->encryptFile($plain, $this->dir.'/dump.enc');

        // Même identifiant de clé, clé différente : le déchiffrement doit
        // échouer à l'authentification, pas produire du charabia.
        config(['backup.encryption.key' => base64_encode(str_repeat('B', 32))]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/clé incorrecte ou archive altérée/');

        $this->encryptor()->decryptFile($this->dir.'/dump.enc', $this->dir.'/out.sql');
    }

    public function test_tampered_archive_is_detected(): void
    {
        $plain = $this->write('dump.sql', str_repeat('x', 5000));
        $this->encryptor()->encryptFile($plain, $this->dir.'/dump.enc');

        // Un octet retourné au milieu du corps chiffré.
        $bytes = file_get_contents($this->dir.'/dump.enc');
        $pos = intdiv(strlen($bytes), 2);
        $bytes[$pos] = chr(ord($bytes[$pos]) ^ 0xFF);
        file_put_contents($this->dir.'/dump.enc', $bytes);

        // Le chiffrement authentifié doit refuser — c'est précisément ce qui
        // protège d'une corruption silencieuse sur une archive de plusieurs mois.
        $this->expectException(\RuntimeException::class);

        $this->encryptor()->decryptFile($this->dir.'/dump.enc', $this->dir.'/out.sql');
    }

    public function test_truncated_archive_is_detected(): void
    {
        $plain = $this->write('dump.sql', str_repeat('y', 300000));
        $this->encryptor()->encryptFile($plain, $this->dir.'/dump.enc');

        $bytes = file_get_contents($this->dir.'/dump.enc');
        file_put_contents($this->dir.'/dump.enc', substr($bytes, 0, (int) (strlen($bytes) * 0.6)));

        // Sans le marqueur final, une restauration partielle passerait pour un
        // succès. C'est le pire scénario possible sur une sauvegarde.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/incomplète|tronquée/');

        $this->encryptor()->decryptFile($this->dir.'/dump.enc', $this->dir.'/out.sql');
    }

    // ── Rotation de clé ──────────────────────────────────────────────────────

    public function test_key_rotation_does_not_invalidate_older_backups(): void
    {
        $oldKey = base64_encode(str_repeat('A', 32));
        $newKey = base64_encode(str_repeat('C', 32));

        // Sauvegarde prise avec la clé k1.
        $plain = $this->write('old.sql', 'contenu historique');
        $this->encryptor()->encryptFile($plain, $this->dir.'/old.enc');

        // Rotation : k2 devient courante, k1 reste déclarée pour l'historique.
        config([
            'backup.encryption.key_id' => 'k2',
            'backup.encryption.key' => $newKey,
            'backup.encryption.previous_keys' => 'k1:'.$oldKey,
        ]);

        // La nouvelle sauvegarde utilise k2…
        $this->encryptor()->encryptFile($this->write('new.sql', 'contenu récent'), $this->dir.'/new.enc');
        $this->assertSame('k2', $this->encryptor()->keyIdOf($this->dir.'/new.enc'));

        // … et l'ancienne reste déchiffrable. C'est l'exigence : une rotation
        // ne doit jamais rendre l'historique irrécupérable.
        $this->encryptor()->decryptFile($this->dir.'/old.enc', $this->dir.'/old.out');
        $this->assertSame('contenu historique', file_get_contents($this->dir.'/old.out'));
    }

    public function test_missing_key_for_an_archive_is_reported_clearly(): void
    {
        $this->encryptor()->encryptFile($this->write('a.sql', 'x'), $this->dir.'/a.enc');

        // Rotation SANS déclarer l'ancienne clé : le piège à éviter.
        config([
            'backup.encryption.key_id' => 'k9',
            'backup.encryption.key' => base64_encode(str_repeat('Z', 32)),
            'backup.encryption.previous_keys' => null,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BACKUP_ENCRYPTION_PREVIOUS_KEYS/');

        $this->encryptor()->decryptFile($this->dir.'/a.enc', $this->dir.'/a.out');
    }

    public function test_key_id_is_stored_but_never_the_key(): void
    {
        $this->encryptor()->encryptFile($this->write('a.sql', 'x'), $this->dir.'/a.enc');

        $onDisk = file_get_contents($this->dir.'/a.enc');
        $rawKey = base64_decode((string) config('backup.encryption.key'), true);

        $this->assertSame('k1', $this->encryptor()->keyIdOf($this->dir.'/a.enc'));
        $this->assertStringNotContainsString($rawKey, $onDisk, 'La clé se retrouve dans le fichier !');
        $this->assertStringNotContainsString((string) config('backup.encryption.key'), $onDisk);
    }

    // ── Trousseau ────────────────────────────────────────────────────────────

    public function test_keyring_rejects_a_malformed_key_without_echoing_it(): void
    {
        config(['backup.encryption.key' => 'pas-du-base64-valide!!']);

        try {
            (new BackupKeyring())->currentKey();
            $this->fail('Une clé invalide doit être refusée.');
        } catch (\RuntimeException $e) {
            // Le message ne doit jamais contenir la valeur fautive.
            $this->assertStringNotContainsString('pas-du-base64-valide', $e->getMessage());
            $this->assertStringContainsString('BACKUP_ENCRYPTION_KEY', $e->getMessage());
        }
    }

    public function test_keyring_rejects_a_key_of_wrong_length(): void
    {
        config(['backup.encryption.key' => base64_encode('trop-court')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/octets/');

        (new BackupKeyring())->currentKey();
    }

    public function test_generated_key_is_usable(): void
    {
        $keyring = new BackupKeyring();
        config(['backup.encryption.key' => $keyring->generateKey()]);

        $this->assertTrue($keyring->isConfigured());
        $this->assertSame(32, strlen($keyring->currentKey()));
    }

    public function test_non_qayed_file_is_not_mistaken_for_a_backup(): void
    {
        $path = $this->write('random.gz', gzencode('pas une sauvegarde'));

        $this->assertFalse($this->encryptor()->looksEncrypted($path));
    }
}
