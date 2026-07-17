<?php

namespace App\Notifications;

use App\Models\Hotel;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Hotel        $hotel,
        private Subscription $subscription,
        private int          $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $planName   = $this->subscription->plan?->name ?? 'Actuel';
        $expiresAt  = $this->subscription->expires_at?->format('d/m/Y') ?? '—';
        $urgency    = $this->daysRemaining <= 3 ? 'URGENT — ' : '';

        return (new MailMessage)
            ->subject("{$urgency}[Qayed] Votre abonnement expire dans {$this->daysRemaining} jour(s)")
            ->greeting("Bonjour,")
            ->line("L'abonnement **{$planName}** de **{$this->hotel->name}** expire le **{$expiresAt}** ({$this->daysRemaining} jour(s) restant(s)).")
            ->line("Pour continuer à utiliser Qayed et rester en conformité avec la réglementation tunisienne, veuillez renouveler votre abonnement.")
            ->action('Renouveler mon abonnement', url('/hotel/settings'))
            ->line("Sans renouvellement, l'accès aux fonctionnalités de check-in sera suspendu à l'expiration.")
            ->salutation("L'équipe Qayed");
    }
}
