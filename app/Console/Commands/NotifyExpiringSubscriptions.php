<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use App\Services\Email\SystemMailer;
use Illuminate\Console\Command;

class NotifyExpiringSubscriptions extends Command
{
    protected $signature   = 'subscriptions:notify-expiring';
    protected $description = 'Send email alerts for subscriptions expiring in 7 or 3 days, and trials ending in 2 or 0 days';

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

        // Trials are org-level and may not have a property yet during onboarding,
        // so — unlike paid reminders above — this goes to the org's contact
        // email via SystemMailer (the same branded, admin-editable channel
        // used for every other subscription-lifecycle email) rather than to
        // individual hotel_admin users.
        foreach ([2, 0] as $days) {
            $target = now()->addDays($days)->toDateString();

            $trials = Subscription::with('organization')
                ->where('status', 'trial')
                ->whereDate('expires_at', $target)
                ->get();

            foreach ($trials as $sub) {
                $org = $sub->organization;
                if (!$org?->contact_email) continue;

                SystemMailer::send('trial_ending', $org->contact_email, [
                    'name'          => $org->name,
                    'trial_message' => $days > 0
                        ? "Votre essai gratuit se termine dans {$days} jour(s), le {$sub->expires_at->format('d/m/Y')}."
                        : "Votre essai gratuit se termine aujourd'hui.",
                    'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), 'Voir les abonnements'),
                ]);

                $this->line("Notified trial org {$org->name} — {$days}d remaining.");
            }
        }

        $this->info('Done.');
    }
}
