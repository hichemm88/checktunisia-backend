<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupEncryptor;
use App\Services\Backup\BackupKeyring;
use App\Services\Backup\BackupState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sauvegarde chiffrée de la base de production, vers un stockage hors Railway.
 *
 * Railway ne fournit ni sauvegarde native ni PITR sur le plan actuel : ceci
 * n'est pas un filet secondaire, c'est la SEULE protection du registre des
 * déclarations de voyageurs.
 *
 * Chaîne : pg_dump → gzip → chiffrement authentifié → stockage objet.
 * Le fichier envoyé est TOUJOURS chiffré ; aucun SQL en clair ne quitte le
 * conteneur.
 *
 * La commande est IDEMPOTENTE dans sa fenêtre de fraîcheur : lancée toutes les
 * heures, elle ne travaille que si la dernière sauvegarde réussie date de plus
 * de `backup.interval_hours`. C'est ce qui rend le dispositif insensible à une
 * exécution manquée — on rattrape à l'heure suivante au lieu de perdre un jour.
 */
class BackupDatabase extends Command
{
    protected $signature = 'qayed:db-backup
                            {--force : ignorer la fenêtre de fraîcheur et sauvegarder maintenant}
                            {--keep-local : conserver le fichier chiffré local (débogage)}';

    protected $description = 'Sauvegarde PostgreSQL chiffrée vers le stockage objet hors fournisseur';

    /** @var array<int,string> fichiers temporaires à supprimer quoi qu'il arrive */
    private array $tempFiles = [];

    public function handle(BackupKeyring $keyring, BackupEncryptor $encryptor, BackupState $state): int
    {
        $startedAt = microtime(true);
        $state->markStarted();

        try {
            $this->guardConfiguration($keyring);

            if (! $this->option('force') && ! $state->isDue()) {
                $this->info('Sauvegarde récente déjà en place — rien à faire.');
                $state->clearStarted();

                return self::SUCCESS;
            }

            $this->guardVersions();
            $this->guardDiskSpace();

            $timestamp = now()->format('Y-m-d_His');
            $dumpPath = $this->temp("qayed-{$timestamp}.sql.gz");
            $encryptedPath = $this->temp("qayed-{$timestamp}.sql.gz.enc");
            $remoteName = "daily/qayed-{$timestamp}.sql.gz.enc";

            $this->runDump($dumpPath);
            $dumpSize = $this->guardDumpSize($dumpPath);

            $keyId = $encryptor->encryptFile($dumpPath, $encryptedPath);

            // Le SQL en clair disparaît AVANT tout envoi réseau.
            $this->forget($dumpPath);

            $this->guardEncrypted($encryptor, $encryptedPath);

            $uploadedSize = filesize($encryptedPath) ?: 0;
            $this->upload($encryptedPath, $remoteName);

            $duration = round(microtime(true) - $startedAt, 1);

            $state->markSucceeded([
                'file' => $remoteName,
                'key_id' => $keyId,
                'dump_bytes' => $dumpSize,
                'uploaded_bytes' => $uploadedSize,
                'duration_seconds' => $duration,
            ]);

            Log::info('[backup] sauvegarde réussie', [
                'file' => $remoteName,
                'key_id' => $keyId,
                'uploaded_bytes' => $uploadedSize,
                'duration_seconds' => $duration,
            ]);

            $this->info(sprintf(
                'Sauvegarde chiffrée envoyée : %s (%s, %ss, clé %s)',
                $remoteName,
                $this->humanSize($uploadedSize),
                $duration,
                $keyId
            ));

            // La purge vient APRÈS le succès, et son échec n'annule pas la
            // sauvegarde qui vient d'aboutir.
            $this->pruneOldBackups($state);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->reportFailure($e, $state, microtime(true) - $startedAt);

            return self::FAILURE;
        } finally {
            $this->cleanup();
        }
    }

    // ── Garde-fous ───────────────────────────────────────────────────────────

    private function guardConfiguration(BackupKeyring $keyring): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new \RuntimeException('Connexion « '.config('database.default').' » non supportée : PostgreSQL attendu.');
        }

        if (blank(config('filesystems.disks.backups.bucket'))) {
            throw new \RuntimeException('BACKUP_S3_BUCKET absent : aucune destination de sauvegarde configurée.');
        }

        // Sans clé, on refuse de produire quoi que ce soit : une sauvegarde en
        // clair du registre voyageurs chez un tiers serait pire que pas de
        // sauvegarde du tout.
        if (! $keyring->isConfigured()) {
            throw new \RuntimeException(
                'BACKUP_ENCRYPTION_KEY absent ou invalide : refus de produire une sauvegarde non chiffrée.'
            );
        }
    }

    /**
     * pg_dump doit être au moins aussi récent que le serveur.
     *
     * pg_dump refuse de dumper un serveur plus récent que lui ; sans ce
     * contrôle, l'échec surviendrait au milieu du processus avec un message
     * obscur. L'image tourne aujourd'hui avec pg_dump 17 (Debian 13) — une
     * montée de Railway vers PostgreSQL 18 casserait les sauvegardes, et il
     * faut que cela se voie immédiatement.
     */
    private function guardVersions(): void
    {
        $process = Process::fromShellCommandline('pg_dump --version');
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('pg_dump introuvable dans l\'image : sauvegarde impossible.');
        }

        if (! preg_match('/(\d+)/', $process->getOutput(), $m)) {
            throw new \RuntimeException('Version de pg_dump illisible : '.trim($process->getOutput()));
        }

        $clientMajor = (int) $m[1];

        $serverVersion = (string) DB::selectOne('SHOW server_version')->server_version;
        preg_match('/(\d+)/', $serverVersion, $sm);
        $serverMajor = (int) ($sm[1] ?? 0);

        self::assertVersionsMatch($clientMajor, $serverMajor);

        $this->line("pg_dump {$clientMajor} · serveur PostgreSQL {$serverMajor} — versions accordées");
    }

    /**
     * Exige l'ÉGALITÉ des versions majeures entre pg_dump et le serveur.
     *
     * Les deux sens de l'écart sont dangereux, et le second est sournois :
     *
     *  pg_dump PLUS ANCIEN que le serveur → refuse de dumper. Échec franc,
     *  donc visible.
     *
     *  pg_dump PLUS RÉCENT que le serveur → dumpe sans broncher, mais émet des
     *  directives que le serveur ne connaît pas (« transaction_timeout » depuis
     *  PostgreSQL 17). L'archive paraît saine et n'est PAS restaurable dans un
     *  serveur de la version d'origine. C'est le pire cas : on croit avoir une
     *  sauvegarde. Constaté pour de vrai le 2026-08-07 avec pg_dump 18 contre
     *  un serveur 16.
     *
     * Méthode statique et publique pour être testable sans lancer de processus.
     */
    public static function assertVersionsMatch(int $clientMajor, int $serverMajor): void
    {
        if ($clientMajor === $serverMajor) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Versions PostgreSQL désaccordées : pg_dump %d contre serveur %d. %s '
            .'Alignez PG_MAJOR (Dockerfile) sur la version du serveur.',
            $clientMajor,
            $serverMajor,
            $clientMajor > $serverMajor
                ? "Un pg_dump plus récent produit une archive NON restaurable dans un serveur {$serverMajor}."
                : 'Un pg_dump plus ancien ne peut pas dumper ce serveur.'
        ));
    }

    private function guardDiskSpace(): void
    {
        $required = config('backup.min_free_disk_mb') * 1024 * 1024;

        // tempDir() crée le répertoire au besoin : sans lui, disk_free_space()
        // renvoyait false sur un répertoire inexistant et le contrôle était
        // silencieusement ignoré — c'était le premier symptôme visible du bug
        // de conteneur neuf.
        $free = @disk_free_space($this->tempDir());

        if ($free === false) {
            // Ne pas bloquer sur une information indisponible, mais le dire.
            $this->warn('Espace disque libre indéterminable — contrôle ignoré.');

            return;
        }

        if ($free < $required) {
            throw new \RuntimeException(sprintf(
                'Espace disque insuffisant : %s libres, %s requis. Sauvegarde refusée pour ne pas saturer le conteneur.',
                $this->humanSize((int) $free),
                $this->humanSize($required)
            ));
        }
    }

    private function guardDumpSize(string $path): int
    {
        $size = filesize($path) ?: 0;
        $min = (int) config('backup.min_dump_bytes');

        if ($size < $min) {
            throw new \RuntimeException(sprintf(
                'Dump suspect : %d octets (minimum %d). Refus d\'archiver un dump probablement vide.',
                $size,
                $min
            ));
        }

        return $size;
    }

    /** Ultime vérification avant l'envoi : ce qui part est bien chiffré. */
    private function guardEncrypted(BackupEncryptor $encryptor, string $path): void
    {
        if (! $encryptor->looksEncrypted($path)) {
            throw new \RuntimeException('Le fichier à envoyer n\'est pas chiffré : envoi annulé.');
        }
    }

    // ── Étapes ───────────────────────────────────────────────────────────────

    /**
     * Produit l'archive gzip du dump — SANS shell, et sans tube.
     *
     * L'implémentation précédente enchaînait `pg_dump | gzip` derrière un
     * `set -o pipefail`, seul moyen d'empêcher que l'échec de pg_dump ne soit
     * masqué par le succès de gzip (un tube rend le code de sortie du DERNIER
     * maillon). Cette garantie reposait donc sur une fonctionnalité du shell
     * de l'hôte — et `pipefail` n'est pas POSIX :
     *
     *   Alpine (busybox ash), Debian 13 (dash ≥ 0.5.12), bash  → supporté
     *   Debian 12, Ubuntu (dash 0.5.11)                        → REFUSÉ
     *
     * Et le refus est brutal : `set` est un utilitaire spécial, un shell non
     * interactif SORT immédiatement (code 2) sans exécuter le dump. La
     * sauvegarde ne dépendait donc pas de sa propre logique mais de la version
     * de dash de l'image sous-jacente — sur la seule protection du registre,
     * chez un hébergeur qui n'offre ni sauvegarde native ni PITR.
     *
     * `pg_dump --compress=9 --file=…` supprime le problème à la racine : un
     * seul processus, qui compresse lui-même, dont le code de sortie est le
     * sien. Il n'y a plus de maillon capable d'en masquer un autre — la
     * propriété que `pipefail` cherchait à obtenir devient structurelle. On
     * passe en outre un tableau d'arguments plutôt qu'une ligne de commande :
     * plus aucun shell n'est invoqué, donc plus aucune question de citation.
     */
    private function runDump(string $outfile): void
    {
        $db = config('database.connections.pgsql');

        // --no-owner / --no-privileges : la restauration doit pouvoir viser un
        // rôle différent de celui de production (bac à sable, poste local).
        $process = new Process([
            'pg_dump',
            '--no-owner',
            '--no-privileges',
            '--clean',
            '--if-exists',
            '--compress=9',
            '--file='.$outfile,
            '-h', (string) $db['host'],
            '-p', (string) $db['port'],
            '-U', (string) $db['username'],
            '-d', (string) $db['database'],
        ]);

        $process->setTimeout((int) config('backup.timeout_seconds'));
        // Jamais en argument de ligne de commande : le mot de passe serait
        // visible dans la liste des processus.
        $process->setEnv(['PGPASSWORD' => $db['password']]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('pg_dump a échoué : '.$this->scrub(trim($process->getErrorOutput())));
        }
    }

    private function upload(string $localPath, string $remoteName): void
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Archive chiffrée illisible avant envoi.');
        }

        try {
            Storage::disk('backups')->put($remoteName, $stream);
        } catch (Throwable $e) {
            throw new \RuntimeException('Envoi vers le stockage objet échoué : '.$this->scrub($e->getMessage()), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Purge des sauvegardes hors rétention.
     *
     * Trois protections contre l'effacement du dernier filet :
     *  - jamais la sauvegarde la plus récente, quel que soit son âge ;
     *  - jamais s'il ne reste qu'une seule sauvegarde ;
     *  - un échec de suppression est signalé mais n'échoue PAS la commande :
     *    la sauvegarde du jour, elle, a réussi.
     */
    private function pruneOldBackups(BackupState $state): void
    {
        try {
            $files = Storage::disk('backups')->files('daily');

            if (count($files) <= 1) {
                $state->markRetention(['deleted' => 0, 'kept' => count($files), 'error' => null]);

                return;
            }

            // Le nom porte l'horodatage : le tri lexicographique est
            // chronologique. Le dernier élément est la sauvegarde la plus
            // récente — intouchable.
            sort($files);
            $newest = array_pop($files);

            $cutoff = now()->subDays((int) config('backup.retention_days'));
            $deleted = 0;
            $errors = 0;

            foreach ($files as $file) {
                if (! preg_match('/qayed-(\d{4}-\d{2}-\d{2})_/', $file, $m)) {
                    continue;
                }

                if (Carbon::parse($m[1])->gte($cutoff)) {
                    continue;
                }

                try {
                    Storage::disk('backups')->delete($file);
                    $deleted++;
                } catch (Throwable $e) {
                    $errors++;
                    Log::warning('[backup] suppression impossible', [
                        'file' => $file,
                        'error' => $this->scrub($e->getMessage()),
                    ]);
                }
            }

            $state->markRetention([
                'deleted' => $deleted,
                'kept' => count($files) - $deleted + 1,
                'newest_protected' => $newest,
                'error' => $errors > 0 ? "{$errors} suppression(s) en échec" : null,
            ]);

            if ($deleted > 0) {
                $this->line("Purge : {$deleted} sauvegarde(s) hors rétention.");
            }

            if ($errors > 0) {
                $this->warn("Purge : {$errors} suppression(s) en échec (voir les journaux).");
            }
        } catch (Throwable $e) {
            // Une purge en échec ne doit jamais faire passer pour ratée une
            // sauvegarde qui a réussi — mais elle doit se voir.
            $message = $this->scrub($e->getMessage());
            Log::warning('[backup] purge de rétention en échec', ['error' => $message]);
            $state->markRetention(['deleted' => 0, 'kept' => null, 'error' => $message]);
            $this->warn('Purge de rétention en échec : '.$message);
        }
    }

    // ── Échec ────────────────────────────────────────────────────────────────

    private function reportFailure(Throwable $e, BackupState $state, float $elapsed): void
    {
        $message = $this->scrub($e->getMessage());

        $context = [
            'stage' => 'db-backup',
            'error' => $message,
            'duration_seconds' => round($elapsed, 1),
            'destination' => config('filesystems.disks.backups.bucket') ? 'configurée' : 'absente',
        ];

        $state->markFailed($message);

        Log::error('[backup] ÉCHEC de la sauvegarde', $context);

        // Remontée explicite : sans cela, l'échec resterait dans les journaux
        // du conteneur, que personne ne lit.
        if (config('sentry.dsn')) {
            \Sentry\captureException($e);
        }

        $this->error('ÉCHEC de la sauvegarde : '.$message);
    }

    /**
     * Retire d'un message d'erreur ce qui ne doit jamais être journalisé :
     * mot de passe de base et clés. Les messages de pg_dump ou du SDK S3
     * peuvent contenir une URL avec identifiants.
     */
    private function scrub(string $message): string
    {
        $secrets = array_filter([
            (string) config('database.connections.pgsql.password'),
            (string) config('filesystems.disks.backups.key'),
            (string) config('filesystems.disks.backups.secret'),
            (string) config('backup.encryption.key'),
        ], fn ($v) => $v !== '' && strlen($v) > 3);

        return str_replace($secrets, '[masqué]', $message);
    }

    // ── Fichiers temporaires ─────────────────────────────────────────────────

    /**
     * Répertoire de travail des fichiers temporaires, CRÉÉ SI ABSENT.
     *
     * Sur un conteneur Railway neuf, `storage/app` n'existe pas : le
     * Dockerfile ne créait que `storage/framework/*` et `storage/logs`, et
     * aucun fichier de `storage/app` n'est suivi par git (git ne versionne pas
     * les répertoires vides).
     *
     * Le reste de l'application ne s'en apercevait pas : Flysystem crée les
     * répertoires à la volée lors d'un `Storage::put()`. Mais la redirection
     * shell `> "$OUTFILE"` du dump, elle, ne crée aucun répertoire parent —
     * d'où « cannot create ... : Directory nonexistent », et le « Broken pipe »
     * de pg_dump qui n'en était que la conséquence (gzip mort, tube fermé).
     *
     * On ne suppose donc plus l'existence du répertoire : on la garantit,
     * avant toute redirection shell. Sous-répertoire dédié pour que ces
     * artefacts transitoires ne se mélangent jamais aux disques Flysystem
     * (`storage/app/private` est la racine du disque « local »).
     */
    private function tempDir(): string
    {
        $dir = storage_path('app/backup-tmp');

        if (! is_dir($dir)) {
            // Récursif : storage/app peut lui-même être absent.
            File::ensureDirectoryExists($dir, 0775, true);
        }

        if (! is_writable($dir)) {
            throw new \RuntimeException("Répertoire temporaire non inscriptible : {$dir}");
        }

        return $dir;
    }

    private function temp(string $name): string
    {
        $path = $this->tempDir().'/'.$name;
        $this->tempFiles[] = $path;

        return $path;
    }

    private function forget(string $path): void
    {
        @unlink($path);
        $this->tempFiles = array_values(array_filter($this->tempFiles, fn ($p) => $p !== $path));
    }

    /**
     * Nettoyage en succès COMME en échec : un dump en clair oublié sur le
     * disque du conteneur serait exactement le risque que le chiffrement
     * cherche à supprimer.
     */
    private function cleanup(): void
    {
        foreach ($this->tempFiles as $path) {
            if ($path && file_exists($path) && ! $this->option('keep-local')) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];
    }

    private function humanSize(int $bytes): string
    {
        foreach (['o', 'Ko', 'Mo', 'Go'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' To';
    }
}
