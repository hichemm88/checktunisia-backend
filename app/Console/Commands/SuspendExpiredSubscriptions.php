<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Email\SystemMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SuspendExpiredSubscriptions extends Command
{
    protected $signature   = 'subscriptions:expire-overdue';
    protected $description = 'Move active subscriptions past their expiry date to "expired" (or trials to "trial_expired"), blocking check-ins until renewed';

    public function handle(): void
    {
        // Un compte interne n'a pas d'échéance commerciale : il n'achète
        // rien, donc rien n'expire. Le couper reviendrait à nous couper
        // nous-mêmes pour une facture qui n'existe pas.
        $subscriptions = Subscription::with(['organization', 'hotel'])
            ->commercial()
            ->whereIn('status', ['active', 'trial'])
            ->where('expires_at', '<', now())
            ->get();

        $spared = 0;

        foreach ($subscriptions as $sub) {
            // Échéance dépassée mais recouvrement en cours : on ne coupe pas.
            // Le client reçoit ses relances J+3 / J+7 / J+14 et sera suspendu
            // à J+21 par `invoices:dunning` — c'est ce qui lui a été annoncé.
            // Couper ici rendait ces trois relances mensongères et privait
            // l'établissement d'une déclaration légalement obligatoire pour
            // une facture en retard de vingt-quatre heures.
            //
            // Le filet reste posé : passé ce terme, si le recouvrement n'a
            // suspendu personne (facture jamais émise, annulée à la main), on
            // expire. La grâce est une tolérance bornée, jamais un abonnement
            // gratuit à durée indéterminée.
            if ($sub->isInGracePeriod()) {
                $spared++;
                $this->line("Grace period for {$sub->id} until ".$sub->graceEndsAt()->format('d/m/Y').'.');
                continue;
            }

            $wasTrial = $sub->isTrial();
            $newStatus = $wasTrial ? 'trial_expired' : 'expired';
            $previousStatus = $sub->status;
            $sub->update(['status' => $newStatus]);

            AuditLogger::log(
                $wasTrial ? 'subscription.trial_expired' : 'subscription.expired',
                $sub,
                oldValues: ['status' => $previousStatus],
                newValues: ['status' => $newStatus],
                hotelId: $sub->hotel_id,
            );

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
                    'cta_button'    => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/subscription'), 'Voir les abonnements'),
                ]);
            } else {
                SystemMailer::send('account_suspended', $to, [
                    'name'   => $name,
                    'reason' => 'Abonnement expiré le ' . $sub->expires_at->format('d/m/Y') . '. Contactez-nous pour le renouveler.',
                ]);
            }

            $this->line(($wasTrial ? 'Trial ended' : 'Expired subscription') . " {$sub->id} ({$name}).");
        }

        $this->info((count($subscriptions) - $spared).' subscription(s) expired, '.$spared.' in grace period.');
    }
}
