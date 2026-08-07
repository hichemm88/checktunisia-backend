<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupEncryptor;
use Illuminate\Console\Command;
use Throwable;

/**
 * Déchiffre une sauvegarde téléchargée — outil d'OPÉRATEUR.
 *
 * Travaille sur un fichier LOCAL, jamais sur le bucket : l'application de
 * production ne dispose délibérément pas du droit de lecture sur les
 * sauvegardes (voir docs/sauvegardes.md). L'opérateur télécharge l'archive
 * avec SES propres identifiants de restauration, puis la déchiffre ici.
 */
class DecryptBackup extends Command
{
    protected $signature = 'qayed:backup-decrypt
                            {source : chemin de l\'archive chiffrée (.sql.gz.enc)}
                            {--out= : chemin de sortie (défaut : source sans .enc)}';

    protected $description = 'Déchiffre une sauvegarde Qayed vers un fichier .sql.gz';

    public function handle(BackupEncryptor $encryptor): int
    {
        $source = $this->argument('source');

        if (! is_file($source)) {
            $this->error("Fichier introuvable : {$source}");

            return self::FAILURE;
        }

        if (! $encryptor->looksEncrypted($source)) {
            $this->error('Ce fichier ne porte pas l\'en-tête d\'une sauvegarde Qayed chiffrée.');

            return self::FAILURE;
        }

        $keyId = $encryptor->keyIdOf($source);
        $this->line("Archive chiffrée avec la clé « {$keyId} ».");

        $destination = $this->option('out') ?: preg_replace('/\.enc$/', '', $source);

        try {
            $encryptor->decryptFile($source, $destination);
        } catch (Throwable $e) {
            // Le message distingue « clé absente du trousseau » de « clé
            // incorrecte ou archive altérée » — sans jamais afficher de clé.
            $this->error('Déchiffrement impossible : '.$e->getMessage());
            @unlink($destination);

            return self::FAILURE;
        }

        $this->info("Déchiffré vers {$destination}");
        $this->line('Restauration : gunzip -c '.basename((string) $destination).' | psql -d <base_isolée> -v ON_ERROR_STOP=1');

        return self::SUCCESS;
    }
}
