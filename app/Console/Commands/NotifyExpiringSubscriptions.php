<?php

namespace App\Console\Commands;

use App\Models\Hotel;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use App\Services\Audit\AuditLogger;
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

            // Un compte interne n'a pas de renouvellement a preparer :
            // aucune relance d'echeance ne le concerne.
            $subscriptions = Subscription::with(['hotel.users', 'organization', 'plan'])
                ->commercial()
                ->where('status', 'active')
                ->whereDate('expires_at', $target)
                ->get();

            foreach ($subscriptions as $sub) {
                $admins = $this->managersOf($sub);
                if ($admins->isEmpty()) {
                    // Compte sans gestionnaire joignable (invitation en cours,
                    // données historiques) : on le dit, on ne s'arrête pas.
                    $this->warn("No manager to notify for subscription {$sub->id}.");
                    continue;
                }

                $name = $sub->organization?->name ?? $sub->hotel?->name ?? 'Client Qayed';

                foreach ($admins as $admin) {
                    $admin->notify(new SubscriptionExpiringNotification($name, $sub, $days));
                }

                AuditLogger::log('subscription.reminder_sent', $sub, newValues: ['days_remaining' => $days], hotelId: $sub->hotel_id);
                $this->line("Notified {$name} — {$days}d remaining.");
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
                ->commercial()
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
                    'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), 'Voir les abonnements'),
                ]);

                AuditLogger::log('subscription.trial_reminder_sent', $sub, newValues: ['days_remaining' => $days]);
                $this->line("Notified trial org {$org->name} — {$days}d remaining.");
            }
        }

        $this->info('Done.');
    }

    /**
     * Les gestionnaires à prévenir pour CET abonnement.
     *
     * L'abonnement est porté par l'organisation : ses gestionnaires sont ceux
     * de l'organisation, tous établissements confondus — c'est elle qui paie,
     * et prévenir le seul premier établissement laisserait les autres
     * découvrir l'expiration en même temps que le blocage.
     *
     * Repli sur le pivot établissement pour les abonnements historiques
     * rattachés à un établissement hors organisation.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function managersOf(Subscription $sub): \Illuminate\Support\Collection
    {
        $orgId = $sub->organization_id ?? $sub->hotel?->organization_id;

        if ($orgId) {
            return User::where('organization_id', $orgId)
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('name', 'hotel_admin'))
                ->get();
        }

        return $sub->hotel
            ? $sub->hotel->users()
                ->where('users.status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('name', 'hotel_admin'))
                ->get()
            : collect();
    }
}
