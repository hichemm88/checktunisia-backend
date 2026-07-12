<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\CheckIn;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Console\Command;

/**
 * Reminds an establishment's staff (§8) about active stays whose expected departure is today
 * and that have no recorded check-out. Runs once a day (14:00 Tunis). Each stay is reminded at
 * most once per day — a same-day `departure_due` notification for it suppresses re-alerting.
 */
class NotifyDeparturesDue extends Command
{
    protected $signature   = 'checkins:notify-departures-due';
    protected $description = 'Remind staff about active stays due to depart today without a recorded check-out';

    public function handle(PushNotificationService $push): int
    {
        $today = today();

        $due = CheckIn::query()
            ->with(['hotel', 'room', 'guests'])
            ->where('status', 'active')
            ->whereNull('actual_check_out_date')
            ->whereDate('expected_check_out_date', $today)
            ->get();

        $count = 0;

        foreach ($due as $checkIn) {
            $alreadyRemindedToday = AppNotification::where('check_in_id', $checkIn->id)
                ->where('type', PushNotificationService::TYPE_DEPARTURE_DUE)
                ->whereDate('created_at', $today)
                ->exists();

            if ($alreadyRemindedToday) {
                continue;
            }

            $push->notifyDepartureDue($checkIn);
            $this->line("Departure reminder — {$checkIn->reference} ({$checkIn->hotel?->name}).");
            $count++;
        }

        $this->info("Done. {$count} departure reminder(s) sent.");

        return self::SUCCESS;
    }
}
