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
    protected $description = 'Move active subscriptions past their expiry date to "expired", blocking check-ins until renewed';

    public function handle(): void
    {
        $subscriptions = Subscription::with(['organization', 'hotel'])
            ->where('status', 'active')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($subscriptions as $sub) {
            $sub->update(['status' => 'expired']);

            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'expired',
                'previous_status' => 'active',
                'new_status'      => 'expired',
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

            SystemMailer::send('account_suspended', $to, [
                'name'   => $name,
                'reason' => 'Abonnement expiré le ' . $sub->expires_at->format('d/m/Y') . '. Contactez-nous pour le renouveler.',
            ]);

            $this->line("Expired subscription {$sub->id} ({$name}).");
        }

        $this->info(count($subscriptions) . ' subscription(s) expired.');
    }
}
