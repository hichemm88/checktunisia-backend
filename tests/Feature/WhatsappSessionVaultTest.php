<?php

namespace Tests\Feature;

use App\Services\Whatsapp\WhatsappSessionVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coffre de la session WhatsApp (INF-04).
 *
 * Ce qui est protégé ici n'est pas une fonctionnalité mais un secret
 * irremplaçable : l'appairage WhatsApp Web. Le perdre impose d'aller
 * re-scanner un QR sur le téléphone émetteur, donc de couper le canal de
 * transmission légal du produit.
 *
 * ⚠️ Aucune session WhatsApp réelle n'apparaît ici : les archives sont des
 * tar.gz factices remplis d'octets aléatoires.
 */
class WhatsappSessionVaultTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-worker-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');

        config([
            'whatsapp.worker_secret' => self::SECRET,
            'whatsapp.session_vault.enabled' => true,
            'whatsapp.session_vault.min_bytes' => 1024,
            // Le coffre exige un bucket ET une clé : sans l'un des deux il
            // refuse de stocker plutôt que de déposer une session en clair.
            'filesystems.disks.backups.bucket' => 'qayed-backups',
            'backup.encryption.key_id' => 'k1',
            'backup.encryption.key' => base64_encode(random_bytes(32)),
        ]);
    }

    /** @return array<string,string> */
    private function workerHeaders(): array
    {
        return ['X-Whatsapp-Worker-Secret' => self::SECRET];
    }

    /** tar.gz factice : en-tête gzip réel, contenu aléatoire, taille voulue. */
    private function fakeArchive(int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-archive-');
        // gzencode produit un vrai flux gzip : c'est le format que le coffre exige.
        file_put_contents($path, gzencode(random_bytes(max($bytes, 32)), 1));

        // Complète jusqu'à la taille demandée (les octets aléatoires se
        // compressent mal, mais on ne veut pas dépendre du taux exact).
        while (filesize($path) < $bytes) {
            file_put_contents($path, random_bytes(1024), FILE_APPEND);
            clearstatcache(true, $path);
        }

        return new UploadedFile($path, 'session.tar.gz', 'application/gzip', null, true);
    }

    // ── Authentification ─────────────────────────────────────────────────────

    public function test_the_vault_is_closed_to_anyone_without_the_worker_secret(): void
    {
        // La session est un secret : ces routes ne doivent pas être ouvertes.
        $this->getJson('/api/v1/internal/whatsapp/session-archive')->assertUnauthorized();
        $this->getJson('/api/v1/internal/whatsapp/session-archive/meta')->assertUnauthorized();
        $this->postJson('/api/v1/internal/whatsapp/session-archive')->assertUnauthorized();

        $this->withHeaders(['X-Whatsapp-Worker-Secret' => 'mauvais'])
            ->getJson('/api/v1/internal/whatsapp/session-archive')
            ->assertUnauthorized();
    }

    // ── Aller-retour ─────────────────────────────────────────────────────────

    public function test_a_stored_session_is_returned_byte_for_byte(): void
    {
        $archive = $this->fakeArchive(4096);
        $original = file_get_contents($archive->getRealPath());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', [
                'archive' => $archive,
                'sha256' => hash('sha256', $original),
            ])
            ->assertOk()
            ->assertJsonPath('data.replaced', false);

        // Une restitution qui différerait d'un octet donnerait un profil
        // Chromium corrompu, donc un QR à re-scanner.
        $returned = $this->withHeaders($this->workerHeaders())
            ->get('/api/v1/internal/whatsapp/session-archive')
            ->assertOk()
            ->streamedContent();

        $this->assertSame($original, $returned);
    }

    public function test_the_session_is_never_stored_in_clear_text(): void
    {
        $archive = $this->fakeArchive(4096);
        $original = file_get_contents($archive->getRealPath());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $archive])
            ->assertOk();

        $disk = Storage::disk('backups');
        $stored = $disk->get(config('whatsapp.session_vault.path'));

        $this->assertNotSame($original, $stored, 'La session a été déposée en clair dans le stockage objet.');
        $this->assertStringStartsWith('QYDBKP01', $stored, 'La session doit être chiffrée avec le format des sauvegardes.');
    }

    public function test_an_empty_vault_answers_404_not_an_error(): void
    {
        // Cas normal du tout premier appairage : le worker enchaîne sur le QR.
        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/session-archive')
            ->assertNotFound();
    }

    // ── Le refus qui compte : ne jamais écraser des credentials valides ──────

    public function test_a_tiny_archive_cannot_overwrite_a_valid_session(): void
    {
        $valid = $this->fakeArchive(4096);
        $validBytes = file_get_contents($valid->getRealPath());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $valid])
            ->assertOk();

        // Scénario réel : un worker redémarre sans son volume et produit une
        // archive quasi vide. Elle ne doit surtout pas remplacer la vraie.
        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $this->fakeArchive(64)])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'VAULT_REJECTED');

        $returned = $this->withHeaders($this->workerHeaders())
            ->get('/api/v1/internal/whatsapp/session-archive')
            ->assertOk()
            ->streamedContent();

        $this->assertSame($validBytes, $returned, 'Une session valide a été écrasée par une archive vide.');
    }

    public function test_a_corrupted_transfer_is_rejected_and_leaves_the_vault_untouched(): void
    {
        $valid = $this->fakeArchive(4096);
        $validBytes = file_get_contents($valid->getRealPath());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $valid])
            ->assertOk();

        // Empreinte annoncée ≠ octets reçus : le transfert a été tronqué.
        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', [
                'archive' => $this->fakeArchive(8192),
                'sha256' => str_repeat('a', 64),
            ])
            ->assertStatus(422);

        $this->assertSame(
            $validBytes,
            $this->withHeaders($this->workerHeaders())
                ->get('/api/v1/internal/whatsapp/session-archive')
                ->streamedContent()
        );
    }

    public function test_a_non_gzip_payload_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wa-not-gz-');
        file_put_contents($path, str_repeat('pas du gzip', 500));

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', [
                'archive' => new UploadedFile($path, 'session.tar.gz', 'application/gzip', null, true),
            ])
            ->assertStatus(422);
    }

    public function test_the_previous_session_is_kept_when_replaced(): void
    {
        $first = $this->fakeArchive(4096);
        $firstBytes = file_get_contents($first->getRealPath());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $first])
            ->assertOk();

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $this->fakeArchive(8192)])
            ->assertOk()
            ->assertJsonPath('data.replaced', true);

        // Filet : une archive valide mais inutilisable (profil pris au mauvais
        // moment) doit rester rattrapable.
        $previous = config('whatsapp.session_vault.path').'.previous';
        Storage::disk('backups')->assertExists($previous);
        $this->assertNotSame($firstBytes, Storage::disk('backups')->get($previous), 'La copie de sûreté doit être chiffrée elle aussi.');
    }

    // ── Aucun secret dans les journaux ni dans les réponses ─────────────────

    public function test_the_session_content_never_reaches_the_logs(): void
    {
        $marker = 'MARQUEUR-DE-SESSION-CONFIDENTIEL';
        $path = tempnam(sys_get_temp_dir(), 'wa-marked-');
        // Texte répété + bourrage aléatoire : le marqueur doit être présent
        // dans les octets déposés, et l'archive dépasser le plancher de taille.
        file_put_contents($path, gzencode(str_repeat($marker, 500), 1).random_bytes(2048));

        Log::spy();

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', [
                'archive' => new UploadedFile($path, 'session.tar.gz', 'application/gzip', null, true),
            ])
            ->assertOk();

        // Le journal trace un dépôt (taille, empreinte, identifiant de clé) —
        // jamais un octet de la session.
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) use ($marker) {
                $this->assertStringNotContainsString($marker, $message.json_encode($context));

                return str_contains($message, 'wa-session');
            })
            ->once();
    }

    public function test_metadata_exposes_size_and_date_but_no_content(): void
    {
        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $this->fakeArchive(4096)])
            ->assertOk();

        $meta = $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/session-archive/meta')
            ->assertOk()
            ->json('data');

        $this->assertTrue($meta['exists']);
        $this->assertTrue($meta['configured']);
        $this->assertGreaterThan(0, $meta['bytes']);
        $this->assertNotNull($meta['stored_at']);
        // `revoked_at` accompagne les métadonnées depuis l'incident du
        // 2026-08-08 : le worker compare cette date à celle de l'archive pour
        // savoir si la restaurer a encore un sens. C'est un horodatage, pas un
        // secret.
        $this->assertNull($meta['revoked_at'], 'aucune révocation ici');
        // Rien d'autre : pas d'extrait, pas de chemin de bucket, pas de clé.
        $this->assertSame(['configured', 'revoked_at', 'exists', 'bytes', 'stored_at'], array_keys($meta));
    }

    // ── Dégradation propre ───────────────────────────────────────────────────

    public function test_an_unconfigured_vault_refuses_rather_than_storing_in_clear(): void
    {
        // Sans clé de chiffrement, déposer la session serait pire que ne rien
        // faire : elle finirait en clair dans le stockage objet.
        config(['backup.encryption.key' => null]);

        $this->assertFalse(app(WhatsappSessionVault::class)->isConfigured());

        $this->withHeaders($this->workerHeaders())
            ->post('/api/v1/internal/whatsapp/session-archive', ['archive' => $this->fakeArchive(4096)])
            ->assertStatus(422);

        $this->withHeaders($this->workerHeaders())
            ->getJson('/api/v1/internal/whatsapp/session-archive')
            ->assertStatus(503);

        Storage::disk('backups')->assertMissing(config('whatsapp.session_vault.path'));
    }
}
