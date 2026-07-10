<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\CheckIn;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Console\Command;

/**
 * Fires the "fiche non validée" notification (§6.1) for draft check-ins that already have
 * at least one guest (scan done) but haven't been validated within 30 minutes. Each pending
 * check-in is notified once — a prior `fiche_pending` row for it suppresses re-alerting.
 */
class NotifyPendingCheckIns extends Command
{
    protected $signature   = 'checkins:notify-pending';
    protected $description = 'Notify managers of draft check-ins left unvalidated for over 30 minutes';

    public function handle(PushNotificationService $push): int
    {
        $cutoff = now()->subMinutes(30);

        $pending = CheckIn::query()
            ->with(['hotel', 'room', 'guests'])
            ->where('status', 'draft')
            ->whereHas('guests')                 // a scan/guest was already captured
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;

        foreach ($pending as $checkIn) {
            $alreadyNotified = AppNotification::where('check_in_id', $checkIn->id)
                ->where('type', PushNotificationService::TYPE_FICHE_PENDING)
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $push->notifyCheckInEvent($checkIn, PushNotificationService::TYPE_FICHE_PENDING, null);
            $this->line("Pending alert — {$checkIn->reference} ({$checkIn->hotel?->name}).");
            $count++;
        }

        $this->info("Done. {$count} pending check-in(s) notified.");

        return self::SUCCESS;
    }
}
