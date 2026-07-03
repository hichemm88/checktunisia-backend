<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use Illuminate\Console\Command;

class NotifyExpiringSubscriptions extends Command
{
    protected $signature   = 'subscriptions:notify-expiring';
    protected $description = 'Send email alerts for subscriptions expiring in 7 or 3 days';

    public function handle(): void
    {
        $thresholds = [7, 3, 1];

        foreach ($thresholds as $days) {
            $target = now()->addDays($days)->toDateString();

            $subscriptions = Subscription::with(['hotel.users', 'plan'])
                ->where('status', 'active')
                ->whereDate('expires_at', $target)
                ->get();

            foreach ($subscriptions as $sub) {
                $hotel  = $sub->hotel;
                $admins = $hotel->users()
                    ->whereHas('roles', fn($q) => $q->where('name', 'hotel_admin'))
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(new SubscriptionExpiringNotification($hotel, $sub, $days));
                }

                $this->line("Notified hotel {$hotel->name} — {$days}d remaining.");
            }
        }

        $this->info('Done.');
    }
}
