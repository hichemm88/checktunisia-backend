<?php

namespace Tests\Feature;

use App\Services\Backup\BackupEncryptor;
use App\Services\Backup\BackupKeyring;
use App\Services\Backup\BackupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sauvegarde de production.
 *
 * Railway ne fournit ni sauvegarde native ni PITR : c'est la seule protection
 * du registre. Le mode de défaillance redouté n'est pas « ça plante » — cela se
 * voit — mais « ça ne tourne pas, ou ça produit une archive inutilisable, et
 * personne ne le sait ».
 */
class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');

        config([
            'filesystems.disks.backups.bucket' => 'test-bucket',
            'backup.encryption.key_id' => 'k1',
            'backup.encryption.key' => base64_encode(str_repeat('A', 32)),
            'backup.encryption.previous_keys' => null,
        ]);

        app(BackupState::class)->forget();
    }

    private function pgDumpAvailable(): bool
    {
        exec('which pg_dump', $out, $code);

        return $code === 0;
    }

    /**
     * Invoque la purge isolément, avec une sortie branchée : la commande écrit
     * via $this->line(), qui exige un OutputStyle.
     */
    private function invokePrune(): void
    {
        $command = app(\App\Console\Commands\BackupDatabase::class);
        $command->setLaravel(app());
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput()
        ));

        $method = new \ReflectionMethod(\App\Console\Commands\BackupDatabase::class, 'pruneOldBackups');
        $method->setAccessible(true);
        $method->invoke($command, app(BackupState::class));
    }

    private function requirePgDump(): void
    {
        if (! $this->pgDumpAvailable()) {
            $this->markTestSkipped('pg_dump absent de cette image.');
        }
    }

    // ── Chemin nominal ───────────────────────────────────────────────────────

    public function test_successful_backup_uploads_an_encrypted_archive(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $files = Storage::disk('backups')->files('daily');
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.sql.gz.enc', $files[0]);

        // Le contenu déposé porte l'en-tête de chiffrement Qayed, pas du gzip nu.
        $content = Storage::disk('backups')->get($files[0]);
        $this->assertSame('QYDBKP01', substr($content, 0, 8), 'Le fichier envoyé n\'est pas chiffré.');
        $this->assertNotSame("\x1f\x8b", substr($content, 0, 2), 'Un gzip en clair a été envoyé.');
    }

    public function test_no_plaintext_sql_is_ever_uploaded(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $content = Storage::disk('backups')->get(Storage::disk('backups')->files('daily')[0]);

        // Des marqueurs que tout dump PostgreSQL contient.
        foreach (['CREATE TABLE', 'COPY ', 'guests', 'PostgreSQL database dump'] as $marker) {
            $this->assertStringNotContainsString($marker, $content, "« {$marker} » lisible en clair dans l'archive.");
        }
    }

    public function test_uploaded_backup_can_be_decrypted_and_is_a_valid_gzip(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $encrypted = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($encrypted, Storage::disk('backups')->get(Storage::disk('backups')->files('daily')[0]));

        $decrypted = $encrypted.'.gz';
        app(BackupEncryptor::class)->decryptFile($encrypted, $decrypted);

        // Gzip valide, et son contenu est bien un dump PostgreSQL.
        $this->assertSame("\x1f\x8b", substr((string) file_get_contents($decrypted), 0, 2));
        $sql = (string) gzdecode((string) file_get_contents($decrypted));
        $this->assertStringContainsString('PostgreSQL database dump', $sql);
        $this->assertStringContainsString('CREATE TABLE', $sql);

        @unlink($encrypted);
        @unlink($decrypted);
    }

    public function test_state_records_operational_metadata_after_success(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $state = app(BackupState::class)->all();

        $this->assertSame('success', $state['last_result']);
        $this->assertNotNull($state['last_success_at']);
        $this->assertSame('k1', $state['last_key_id']);
        $this->assertGreaterThan(0, $state['last_size_bytes']);
        $this->assertFalse($state['running']);
    }

    // ── Fenêtre de fraîcheur (robustesse de la planification) ────────────────

    public function test_backup_is_skipped_when_a_recent_one_exists(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        // Deuxième passage horaire : rien à faire, et surtout pas d'échec.
        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('Sauvegarde récente déjà en place')
            ->assertExitCode(0);

        $this->assertCount(1, Storage::disk('backups')->files('daily'));
    }

    public function test_force_bypasses_the_freshness_window(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);
        sleep(1); // horodatage distinct dans le nom de fichier
        $this->artisan('qayed:db-backup', ['--force' => true])->assertExitCode(0);

        $this->assertCount(2, Storage::disk('backups')->files('daily'));
    }

    public function test_a_stale_backup_is_due_again(): void
    {
        $state = app(BackupState::class);
        $this->assertTrue($state->isDue(), 'Sans historique, une sauvegarde est due.');

        $state->markSucceeded(['file' => 'x', 'key_id' => 'k1', 'uploaded_bytes' => 1, 'duration_seconds' => 1]);
        $this->assertFalse($state->isDue());

        // Au-delà de la fenêtre, le passage horaire suivant rattrape.
        $this->travel(25)->hours();
        $this->assertTrue($state->isDue());
    }

    // ── Refus explicites ─────────────────────────────────────────────────────

    public function test_backup_fails_loudly_without_a_bucket(): void
    {
        config(['filesystems.disks.backups.bucket' => null]);

        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('BACKUP_S3_BUCKET absent')
            ->assertExitCode(1);
    }

    public function test_backup_refuses_to_run_without_an_encryption_key(): void
    {
        config(['backup.encryption.key' => null]);

        // Point capital : mieux vaut PAS de sauvegarde qu'une sauvegarde en
        // clair du registre voyageurs chez un tiers.
        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('refus de produire une sauvegarde non chiffrée')
            ->assertExitCode(1);

        $this->assertEmpty(Storage::disk('backups')->files('daily'));
    }

    public function test_backup_refuses_non_postgres_connections(): void
    {
        $original = config('database.default');
        config(['database.default' => 'sqlite']);

        try {
            $this->artisan('qayed:db-backup')
                ->expectsOutputToContain('non supportée')
                ->assertExitCode(1);
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_backup_refuses_when_disk_space_is_insufficient(): void
    {
        // Exigence absurde : aucun disque ne peut la satisfaire.
        config(['backup.min_free_disk_mb' => PHP_INT_MAX >> 30]);

        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('Espace disque insuffisant')
            ->assertExitCode(1);

        $this->assertEmpty(Storage::disk('backups')->files('daily'));
    }

    public function test_backup_refuses_a_suspiciously_small_dump(): void
    {
        $this->requirePgDump();

        // Seuil déraisonnable : le dump réel sera jugé trop petit.
        config(['backup.min_dump_bytes' => 500_000_000]);

        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('Dump suspect')
            ->assertExitCode(1);

        $this->assertEmpty(Storage::disk('backups')->files('daily'));
    }

    public function test_pg_dump_failure_is_reported_and_nothing_is_uploaded(): void
    {
        // Base inexistante : pg_dump échoue.
        config(['database.connections.pgsql.database' => 'base_qui_nexiste_pas']);

        $this->artisan('qayed:db-backup')->assertExitCode(1);

        $this->assertEmpty(
            Storage::disk('backups')->files('daily'),
            'Un dump raté ne doit jamais être archivé : on le découvrirait le jour de la restauration.'
        );
    }

    // ── Échec visible ────────────────────────────────────────────────────────

    public function test_failure_is_logged_with_context(): void
    {
        Log::spy();
        config(['filesystems.disks.backups.bucket' => null]);

        $this->artisan('qayed:db-backup')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains($msg, '[backup]'))
            ->once();
    }

    public function test_failure_is_recorded_in_state(): void
    {
        config(['filesystems.disks.backups.bucket' => null]);

        $this->artisan('qayed:db-backup')->assertExitCode(1);

        $state = app(BackupState::class)->all();

        $this->assertSame('failure', $state['last_result']);
        $this->assertNotNull($state['last_failure_at']);
        $this->assertNotEmpty($state['last_error']);
        $this->assertFalse($state['running'], 'Un échec doit lever le drapeau « en cours ».');
    }

    public function test_error_messages_never_contain_secrets(): void
    {
        config([
            'database.connections.pgsql.password' => 'MotDePasseTresSecret123',
            'database.connections.pgsql.database' => 'base_qui_nexiste_pas',
        ]);

        $this->artisan('qayed:db-backup')->assertExitCode(1);

        $recorded = json_encode(app(BackupState::class)->all());

        $this->assertStringNotContainsString('MotDePasseTresSecret123', (string) $recorded);
    }

    // ── Nettoyage ────────────────────────────────────────────────────────────

    public function test_no_temporary_files_survive_a_failure(): void
    {
        config(['database.connections.pgsql.database' => 'base_qui_nexiste_pas']);

        $this->artisan('qayed:db-backup')->assertExitCode(1);

        // Un dump en clair oublié sur le disque du conteneur annulerait tout
        // le bénéfice du chiffrement.
        // Chemin corrigé le 2026-08-07 : les temporaires vivent désormais dans
        // app/backup-tmp/. Scruter l'ancien emplacement rendait ces tests verts
        // à vide — ils ne prouvaient plus rien.
        $this->assertEmpty(glob(storage_path('app/backup-tmp/*')) ?: []);
    }

    public function test_no_temporary_files_survive_a_success(): void
    {
        $this->requirePgDump();

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $this->assertEmpty(glob(storage_path('app/backup-tmp/*')) ?: []);
    }

    // ── Conteneur neuf : répertoire temporaire absent ────────────────────────

    /**
     * Régression du 2026-08-07, constatée au PREMIER test réel en production.
     *
     * Sur un conteneur Railway neuf, `storage/app` n'existe pas : git ne
     * versionne pas les répertoires vides et le Dockerfile ne le créait pas.
     * La redirection shell `> "$OUTFILE"` du dump ne crée aucun répertoire
     * parent, d'où « cannot create ... : Directory nonexistent ». Le
     * « Broken pipe » de pg_dump n'en était que la conséquence.
     */
    public function test_backup_creates_its_temp_directory_when_missing(): void
    {
        $this->requirePgDump();

        $tempDir = storage_path('app/backup-tmp');
        \Illuminate\Support\Facades\File::deleteDirectory($tempDir);
        $this->assertDirectoryDoesNotExist($tempDir, 'Préalable du test : le répertoire doit être absent.');

        $this->artisan('qayed:db-backup', ['--force' => true])->assertExitCode(0);

        $this->assertDirectoryExists($tempDir, 'La commande doit créer son répertoire temporaire.');
        $this->assertCount(1, Storage::disk('backups')->files('daily'));
    }

    /**
     * Symptôme secondaire du même bug : disk_free_space() renvoie false sur un
     * répertoire inexistant, donc le contrôle d'espace était silencieusement
     * ignoré (« Espace disque libre indéterminable » en production).
     */
    public function test_disk_space_check_works_on_a_fresh_container(): void
    {
        $this->requirePgDump();

        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/backup-tmp'));

        $this->artisan('qayed:db-backup', ['--force' => true])
            ->doesntExpectOutputToContain('Espace disque libre indéterminable')
            ->assertExitCode(0);
    }

    // ── Rétention ────────────────────────────────────────────────────────────

    public function test_retention_deletes_only_files_beyond_the_window(): void
    {
        $this->requirePgDump();

        Storage::disk('backups')->put('daily/qayed-2020-01-01_000000.sql.gz.enc', 'vieux');
        Storage::disk('backups')->put('daily/qayed-2020-01-02_000000.sql.gz.enc', 'vieux');

        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $remaining = Storage::disk('backups')->files('daily');

        $this->assertCount(1, $remaining, 'Les anciennes doivent être purgées.');
        $this->assertStringNotContainsString('2020-01-01', $remaining[0]);
    }

    public function test_retention_never_deletes_the_newest_backup(): void
    {
        // Toutes hors rétention. Sans protection, la purge viderait le bucket.
        foreach (['2020-01-01', '2020-01-02', '2020-01-03'] as $d) {
            Storage::disk('backups')->put("daily/qayed-{$d}_000000.sql.gz.enc", 'x');
        }

        $this->invokePrune();

        $remaining = Storage::disk('backups')->files('daily');

        $this->assertCount(1, $remaining, 'La plus récente doit survivre, même hors rétention.');
        $this->assertStringContainsString('2020-01-03', $remaining[0]);
    }

    public function test_retention_never_empties_a_bucket_with_a_single_backup(): void
    {
        Storage::disk('backups')->put('daily/qayed-2020-01-01_000000.sql.gz.enc', 'unique');

        $this->invokePrune();

        $this->assertCount(1, Storage::disk('backups')->files('daily'), 'La seule sauvegarde ne doit jamais être supprimée.');
    }

    public function test_retention_state_is_recorded(): void
    {
        $this->requirePgDump();

        Storage::disk('backups')->put('daily/qayed-2020-01-01_000000.sql.gz.enc', 'x');
        $this->artisan('qayed:db-backup')->assertExitCode(0);

        $retention = app(BackupState::class)->all()['retention'] ?? null;

        $this->assertNotNull($retention);
        $this->assertSame(1, $retention['deleted']);
        $this->assertNull($retention['error']);
    }

    // ── Compatibilité de version ─────────────────────────────────────────────

    /**
     * Production Railway = PostgreSQL 18 (confirmé le 2026-08-07).
     * PG_MAJOR est donc épinglé à 18 dans les deux images.
     *
     * @return array<string, array{0:int, 1:int, 2:bool}>
     */
    public static function versionPairs(): array
    {
        return [
            'pg_dump 18 · serveur 18 (production)' => [18, 18, true],
            'pg_dump 18 · serveur 17'              => [18, 17, false],
            'pg_dump 18 · serveur 16'              => [18, 16, false],
            'pg_dump 17 · serveur 18 (image périmée)' => [17, 18, false],
            'pg_dump 16 · serveur 18'              => [16, 18, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('versionPairs')]
    public function test_version_guard_accepts_only_matching_majors(int $client, int $server, bool $accepted): void
    {
        if ($accepted) {
            \App\Console\Commands\BackupDatabase::assertVersionsMatch($client, $server);
            $this->assertTrue(true, 'Versions identiques : acceptées.');

            return;
        }

        try {
            \App\Console\Commands\BackupDatabase::assertVersionsMatch($client, $server);
            $this->fail("pg_dump {$client} contre serveur {$server} aurait dû être refusé.");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('désaccordées', $e->getMessage());
            $this->assertStringContainsString((string) $client, $e->getMessage());
            $this->assertStringContainsString((string) $server, $e->getMessage());
        }
    }

    public function test_a_newer_pg_dump_is_rejected_for_the_right_reason(): void
    {
        // Le cas sournois : l'archive serait produite sans erreur mais ne
        // pourrait pas être restaurée dans le serveur d'origine.
        try {
            \App\Console\Commands\BackupDatabase::assertVersionsMatch(18, 16);
            $this->fail('Aurait dû être refusé.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NON restaurable', $e->getMessage());
        }
    }

    public function test_an_older_pg_dump_is_rejected_for_the_right_reason(): void
    {
        try {
            \App\Console\Commands\BackupDatabase::assertVersionsMatch(16, 18);
            $this->fail('Aurait dû être refusé.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ne peut pas dumper', $e->getMessage());
        }
    }

    public function test_test_environment_matches_production_major_version(): void
    {
        $this->requirePgDump();

        // Garde-fou de parité : si la base de test cesse de refléter la
        // production (PostgreSQL 18), les tests valideraient un couple de
        // versions qui n'existe nulle part.
        exec('pg_dump --version', $out);
        preg_match('/(\d+)/', $out[0] ?? '', $m);

        $serverMajor = (int) preg_replace('/\D.*$/', '', (string) \Illuminate\Support\Facades\DB::selectOne('SHOW server_version')->server_version);

        $this->assertSame(18, (int) $m[1], "pg_dump de l'image de test doit être en 18 (production Railway).");
        $this->assertSame(18, $serverMajor, 'La base de test doit être en PostgreSQL 18 (production Railway).');
    }

    public function test_version_check_passes_against_the_test_server(): void
    {
        $this->requirePgDump();

        // pg_dump doit être au moins aussi récent que le serveur, sinon la
        // commande refuse — le test le prouve en passant sans erreur de version.
        $this->artisan('qayed:db-backup')
            ->expectsOutputToContain('serveur PostgreSQL')
            ->assertExitCode(0);
    }

    public function test_keyring_is_reported_as_configured(): void
    {
        $this->assertTrue(app(BackupKeyring::class)->isConfigured());
    }
}
