<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupEncryptor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Vérifie qu'une sauvegarde est réellement restaurable — sans jamais toucher
 * à la production.
 *
 * Une sauvegarde jamais restaurée n'est pas une sauvegarde, c'est une
 * hypothèse. Cette commande transforme l'hypothèse en fait : elle déchiffre
 * l'archive, la restaure dans une base TEMPORAIRE créée pour l'occasion,
 * contrôle le schéma, puis supprime cette base.
 *
 * Sécurité : la base cible est toujours créée par la commande avec un nom
 * horodaté, et la commande REFUSE de viser la base applicative.
 */
class VerifyBackupRestore extends Command
{
    protected $signature = 'qayed:backup-verify-restore
                            {source : archive chiffrée (.sql.gz.enc) déjà téléchargée}
                            {--keep : conserver la base temporaire pour inspection}';

    protected $description = 'Restaure une sauvegarde dans une base temporaire et vérifie son intégrité';

    public function handle(BackupEncryptor $encryptor): int
    {
        $source = $this->argument('source');

        if (! is_file($source)) {
            $this->error("Archive introuvable : {$source}");

            return self::FAILURE;
        }

        $db = config('database.connections.pgsql');
        $tempDb = 'qayed_verify_'.now()->format('YmdHis');

        $work = [];
        $created = false;

        try {
            // ── 1. Déchiffrement ────────────────────────────────────────────
            $this->line('1/5 Déchiffrement…');
            $gz = $work[] = sys_get_temp_dir()."/{$tempDb}.sql.gz";
            $encryptor->decryptFile($source, $gz);
            $this->info('    OK — clé « '.$encryptor->keyIdOf($source).' », archive authentifiée et complète');

            // ── 2. Décompression ────────────────────────────────────────────
            $this->line('2/5 Décompression…');
            $sql = $work[] = sys_get_temp_dir()."/{$tempDb}.sql";
            $this->shell("gunzip -c \"$gz\" > \"$sql\"", 'gunzip');
            $this->info('    OK — '.$this->humanSize((int) filesize($sql)));

            // ── 3. Base temporaire ──────────────────────────────────────────
            $this->line("3/5 Création de la base temporaire {$tempDb}…");
            $this->psql($db, 'postgres', "CREATE DATABASE \\\"{$tempDb}\\\"");
            $created = true;
            $this->info('    OK');

            // ── 4. Restauration ─────────────────────────────────────────────
            $this->line('4/5 Restauration…');
            $this->shell(
                sprintf(
                    'PGPASSWORD=%s psql -q -h %s -p %s -U %s -d %s -v ON_ERROR_STOP=1 -f %s',
                    escapeshellarg((string) $db['password']),
                    escapeshellarg((string) $db['host']),
                    escapeshellarg((string) $db['port']),
                    escapeshellarg((string) $db['username']),
                    escapeshellarg($tempDb),
                    escapeshellarg($sql)
                ),
                'psql'
            );
            $this->info('    OK');

            // ── 5. Contrôles d'intégrité ────────────────────────────────────
            $this->line('5/5 Contrôles…');
            $ok = $this->verify($db, $tempDb);

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Vérification échouée : '.$e->getMessage());

            return self::FAILURE;
        } finally {
            foreach ($work as $f) {
                @unlink($f);
            }

            if ($created && ! $this->option('keep')) {
                try {
                    $this->psql($db, 'postgres', "DROP DATABASE IF EXISTS \\\"{$tempDb}\\\"");
                    $this->line("Base temporaire {$tempDb} supprimée.");
                } catch (Throwable $e) {
                    $this->warn("Base temporaire {$tempDb} non supprimée : ".$e->getMessage());
                }
            }
        }
    }

    /** Contrôles qui distinguent une restauration saine d'une restauration partielle. */
    private function verify(array $db, string $tempDb): bool
    {
        $conn = 'verify_temp';
        config(["database.connections.{$conn}" => array_merge($db, ['database' => $tempDb])]);
        DB::purge($conn);

        $checks = [];

        $tables = (int) DB::connection($conn)->selectOne(
            "SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public'"
        )->n;
        $checks['tables restaurées'] = [$tables, $tables > 20];

        // L'extension et les index trigram : leur absence ne casse rien
        // visiblement mais dégrade la recherche d'un facteur 8 à 180.
        $trgm = DB::connection($conn)->selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname='pg_trgm'");
        $checks['extension pg_trgm'] = [$trgm ? 'présente' : 'ABSENTE', (bool) $trgm];

        $indexes = (int) DB::connection($conn)->selectOne(
            "SELECT count(*) AS n FROM pg_indexes WHERE indexname LIKE '%trgm%'"
        )->n;
        $checks['index trigram'] = [$indexes, $indexes === 3];

        $migrations = (int) DB::connection($conn)->selectOne('SELECT count(*) AS n FROM migrations')->n;
        $checks['migrations'] = [$migrations, $migrations > 0];

        // Les tables du registre doivent exister et être interrogeables.
        foreach (['guests', 'check_ins', 'travel_documents', 'audit_logs'] as $table) {
            try {
                $count = (int) DB::connection($conn)->selectOne("SELECT count(*) AS n FROM {$table}")->n;
                $checks["table {$table}"] = ["{$count} ligne(s)", true];
            } catch (Throwable) {
                $checks["table {$table}"] = ['ILLISIBLE', false];
            }
        }

        DB::purge($conn);

        $allOk = true;

        foreach ($checks as $label => [$value, $ok]) {
            $this->line(sprintf('    %-24s %-16s %s', $label, $value, $ok ? '✓' : '✗'));
            $allOk = $allOk && $ok;
        }

        $allOk
            ? $this->info('Sauvegarde VÉRIFIÉE : restaurable et complète.')
            : $this->error('Sauvegarde SUSPECTE : au moins un contrôle a échoué.');

        return $allOk;
    }

    private function psql(array $db, string $database, string $sql): void
    {
        $this->shell(sprintf(
            'PGPASSWORD=%s psql -q -h %s -p %s -U %s -d %s -c "%s"',
            escapeshellarg((string) $db['password']),
            escapeshellarg((string) $db['host']),
            escapeshellarg((string) $db['port']),
            escapeshellarg((string) $db['username']),
            escapeshellarg($database),
            $sql
        ), 'psql');
    }

    private function shell(string $command, string $label): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            $password = (string) config('database.connections.pgsql.password');
            $error = trim($process->getErrorOutput());

            throw new \RuntimeException($label.' : '.str_replace($password, '[masqué]', $error));
        }
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
