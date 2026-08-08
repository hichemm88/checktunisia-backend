<?php

namespace App\Services\Whatsapp;

use App\Services\Backup\BackupEncryptor;
use App\Services\Backup\BackupKeyring;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Coffre de la session WhatsApp Web.
 *
 * PROBLÈME RÉSOLU — la session (profil Chromium appairé) ne vivait que sur le
 * volume Railway du worker. Un volume est attaché à UNE instance : il survit à
 * un redéploiement ordinaire, mais pas à une recréation du service, à une
 * migration, ni à une fausse manœuvre. Le canal de transmission légal du
 * produit reposait donc sur un exemplaire unique, non sauvegardé, d'un secret
 * qu'on ne peut reconstituer qu'en re-scannant un QR sur place.
 *
 * Ce coffre en garde une copie chiffrée dans le stockage objet DÉJÀ utilisé
 * pour les sauvegardes de base (disque « backups », clé du BackupKeyring).
 * Aucun nouveau fournisseur, aucun nouveau secret à gérer.
 *
 * RÈGLES DE SÛRETÉ, dans l'ordre d'importance :
 *
 *  1. Une session valide n'est JAMAIS écrasée par une archive suspecte. Le
 *     plancher de taille est le garde-fou : un worker qui démarre sans volume
 *     produirait une archive minuscule, et c'est précisément le scénario qui
 *     détruirait les credentials.
 *  2. Le contenu de l'archive n'est JAMAIS journalisé, ni renvoyé dans une
 *     réponse d'erreur, ni écrit ailleurs que dans le coffre chiffré. Les
 *     journaux ne portent que des tailles et des empreintes.
 *  3. Le remplacement conserve la version précédente : une archive corrompue
 *     déposée par erreur reste rattrapable.
 */
class WhatsappSessionVault
{
    /** Signature gzip — une archive qui ne commence pas par ces octets n'est pas un tar.gz. */
    private const GZIP_MAGIC = "\x1f\x8b";

    public function __construct(
        private BackupEncryptor $encryptor,
        private BackupKeyring $keyring,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('whatsapp.session_vault.enabled', true);
    }

    /**
     * Le coffre est-il utilisable ? Exige le stockage objet ET la clé de
     * chiffrement : sans l'un des deux on préfère ne rien stocker plutôt que
     * de déposer une session en clair.
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && filled(config('filesystems.disks.backups.bucket'))
            && $this->keyring->isConfigured();
    }

    private function path(): string
    {
        return (string) config('whatsapp.session_vault.path', 'whatsapp-session/session.tar.gz.enc');
    }

    private function previousPath(): string
    {
        return $this->path().'.previous';
    }

    public function exists(): bool
    {
        return $this->isConfigured() && Storage::disk('backups')->exists($this->path());
    }

    /**
     * Métadonnées non sensibles, pour la supervision.
     *
     * @return array{exists:bool, bytes:int|null, stored_at:string|null}
     */
    public function metadata(): array
    {
        if (! $this->exists()) {
            return ['exists' => false, 'bytes' => null, 'stored_at' => null];
        }

        $disk = Storage::disk('backups');

        return [
            'exists' => true,
            'bytes' => $disk->size($this->path()),
            'stored_at' => date(DATE_ATOM, $disk->lastModified($this->path())),
        ];
    }

    /**
     * Dépose une archive de session.
     *
     * @param  string  $archivePath  chemin local du tar.gz EN CLAIR (fichier temporaire de l'upload)
     * @param  string|null  $expectedSha256  empreinte annoncée par le worker, vérifiée avant tout stockage
     * @return array{bytes:int, sha256:string, key_id:string, replaced:bool}
     *
     * @throws RuntimeException si l'archive est refusée — la session déjà en coffre reste intacte
     */
    public function store(string $archivePath, ?string $expectedSha256 = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Coffre de session non configuré (stockage objet ou clé de chiffrement absents).');
        }

        $bytes = @filesize($archivePath);

        if ($bytes === false) {
            throw new RuntimeException('Archive de session illisible.');
        }

        $min = (int) config('whatsapp.session_vault.min_bytes', 65536);
        $max = (int) config('whatsapp.session_vault.max_bytes', 64 * 1024 * 1024);

        // LE garde-fou : c'est ici qu'on refuse d'écraser des credentials
        // valides par une session vide.
        if ($bytes < $min) {
            throw new RuntimeException("Archive de session trop petite ({$bytes} o < {$min} o) : refusée pour ne pas écraser une session valide.");
        }

        if ($bytes > $max) {
            throw new RuntimeException("Archive de session trop volumineuse ({$bytes} o > {$max} o).");
        }

        if (@file_get_contents($archivePath, false, null, 0, 2) !== self::GZIP_MAGIC) {
            throw new RuntimeException("L'archive de session n'est pas un tar.gz.");
        }

        $sha256 = hash_file('sha256', $archivePath);

        if ($expectedSha256 !== null && ! hash_equals($sha256, strtolower($expectedSha256))) {
            // Transfert tronqué : mieux vaut garder l'ancienne session que
            // déposer une archive qu'on ne pourra pas restaurer.
            throw new RuntimeException('Empreinte de l\'archive de session non conforme : transfert corrompu.');
        }

        $encrypted = $this->tempFile('wa-session-enc-');

        try {
            $keyId = $this->encryptor->encryptFile($archivePath, $encrypted);

            $disk = Storage::disk('backups');
            $replaced = $disk->exists($this->path());

            // Copie de sûreté AVANT remplacement : une archive valide mais
            // inutilisable (profil corrompu au moment du tar) reste rattrapable.
            if ($replaced) {
                try {
                    $disk->put($this->previousPath(), $disk->readStream($this->path()));
                } catch (\Throwable $e) {
                    Log::warning('[wa-session] copie de sûreté impossible : '.$e->getMessage());
                }
            }

            $stream = @fopen($encrypted, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Archive chiffrée illisible avant envoi.');
            }

            try {
                $disk->put($this->path(), $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Jamais le contenu : taille, empreinte et identifiant de clé
            // suffisent à tracer un dépôt et à en vérifier l'intégrité.
            Log::info('[wa-session] session archivée dans le coffre', [
                'bytes' => $bytes,
                'sha256' => $sha256,
                'key_id' => $keyId,
                'replaced' => $replaced,
            ]);

            return ['bytes' => $bytes, 'sha256' => $sha256, 'key_id' => $keyId, 'replaced' => $replaced];
        } finally {
            @unlink($encrypted);
        }
    }

    /**
     * Restitue l'archive en clair dans un fichier temporaire.
     *
     * @return string|null chemin du tar.gz déchiffré, ou null si le coffre est vide
     */
    public function retrieve(): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $ciphertext = $this->tempFile('wa-session-cipher-');
        $plaintext = $this->tempFile('wa-session-plain-');

        try {
            $source = Storage::disk('backups')->readStream($this->path());

            if ($source === null) {
                return null;
            }

            $out = fopen($ciphertext, 'wb');
            stream_copy_to_stream($source, $out);
            fclose($out);
            fclose($source);

            // Le déchiffrement échoue bruyamment si l'archive a été altérée :
            // le chiffrement est authentifié et le dernier bloc porte le
            // marqueur FINAL, donc une archive tronquée est détectée ici.
            $this->encryptor->decryptFile($ciphertext, $plaintext);

            return $plaintext;
        } catch (\Throwable $e) {
            @unlink($plaintext);
            Log::error('[wa-session] restitution impossible : '.$e->getMessage());

            throw new RuntimeException('Archive de session illisible : '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($ciphertext);
        }
    }

    private function tempFile(string $prefix): string
    {
        $dir = storage_path('app/tmp');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = tempnam($dir, $prefix);

        if ($path === false) {
            throw new RuntimeException('Impossible de créer un fichier temporaire pour la session.');
        }

        return $path;
    }
}
