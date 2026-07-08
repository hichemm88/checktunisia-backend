<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Email\SystemMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SuspendExpiredSubscriptions extends Command
{
    protected $signature   = 'subscriptions:expire-overdue';
    protected $description = 'Move active subscriptions past their expiry date to "expired" (or trials to "trial_expired"), blocking check-ins until renewed';

    public function handle(): void
    {
        $subscriptions = Subscription::with(['organization', 'hotel'])
            ->whereIn('status', ['active', 'trial'])
            ->where('expires_at', '<', now())
            ->get();

        foreach ($subscriptions as $sub) {
            $wasTrial = $sub->isTrial();
            $newStatus = $wasTrial ? 'trial_expired' : 'expired';
            $sub->update(['status' => $newStatus]);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => $wasTrial ? 'trial_expired' : 'expired',
                'previous_status' => $wasTrial ? 'trial' : 'active',
                'new_status'      => $newStatus,
                'created_at'      => now(),
            ]);

            if ($sub->hotel_id) {
                Cache::forget("hotel_subscription_active:{$sub->hotel_id}");
            }
            if ($sub->organization_id) {
                Cache::forget("org_subscription_active:{$sub->organization_id}");
            }

            $org  = $sub->organization;
            $name = $org?->name ?? $sub->hotel?->name ?? 'Client Qayed';
            $to   = $org?->contact_email
                ?? $sub->hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;

            if ($wasTrial) {
                SystemMailer::send('trial_ending', $to, [
                    'name'          => $name,
                    'trial_message' => "Votre essai gratuit s'est terminé le " . $sub->expires_at->format('d/m/Y') . '.',
                    'cta_button'    => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), 'Voir les abonnements'),
                ]);
            } else {
                SystemMailer::send('account_suspended', $to, [
                    'name'   => $name,
                    'reason' => 'Abonnement expiré le ' . $sub->expires_at->format('d/m/Y') . '. Contactez-nous pour le renouveler.',
                ]);
            }

            $this->line(($wasTrial ? 'Trial ended' : 'Expired subscription') . " {$sub->id} ({$name}).");
        }

        $this->info(count($subscriptions) . ' subscription(s) expired.');
    }
}
