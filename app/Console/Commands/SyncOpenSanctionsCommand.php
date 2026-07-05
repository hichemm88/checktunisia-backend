<?php

namespace App\Console\Commands;

use App\Services\Watchlist\OpenSanctionsService;
use Illuminate\Console\Command;

/**
 * Sync the watchlist with OpenSanctions public datasets.
 *
 * Datasets synced:
 *  - Interpol Red Notices (wanted for arrest internationally)
 *  - UN Security Council Sanctions
 *
 * Scheduled daily at 02:00 via routes/console.php.
 * Can also be triggered manually:  php artisan watchlist:sync-opensanctions
 */
class SyncOpenSanctionsCommand extends Command
{
    protected $signature   = 'watchlist:sync-opensanctions';
    protected $description = 'Sync watchlist entries from OpenSanctions (Interpol Red Notices + UN Sanctions)';

    public function handle(OpenSanctionsService $service): int
    {
        $this->info('Starting OpenSanctions sync...');
        $start = microtime(true);

        $stats   = $service->sync();
        $elapsed = round(microtime(true) - $start, 1);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Inserted',    $stats['inserted']],
                ['Updated',     $stats['updated']],
                ['Deactivated', $stats['deactivated']],
                ['Errors',      $stats['errors']],
            ]
        );

        $this->line("Completed in {$elapsed}s");

        if ($stats['errors'] > 0) {
            $this->warn("{$stats['errors']} dataset(s) failed — check logs for details.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
