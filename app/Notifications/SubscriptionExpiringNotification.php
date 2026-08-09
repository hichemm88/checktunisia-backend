<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Services\Email\SystemMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Le rappel porte le NOM DU COMPTE, pas un établissement.
     *
     * L'abonnement appartient à l'organisation : exiger un établissement
     * rendait cette notification impossible à construire pour un abonnement
     * porté par l'organisation (le cas normal depuis l'inscription publique),
     * et faisait tomber la commande planifiée entière.
     */
    public function __construct(
        private string       $accountName,
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
            ->line("L'abonnement **{$planName}** de **{$this->accountName}** expire le **{$expiresAt}** ({$this->daysRemaining} jour(s) restant(s)).")
            ->line("Pour continuer à utiliser Qayed et rester en conformité avec la réglementation tunisienne, veuillez renouveler votre abonnement.")
            // `url()` compose une adresse sur APP_URL — le domaine de l'API,
            // où cette page n'existe pas. Le client cliquait dans le vide.
            ->action('Renouveler mon abonnement', SystemMailer::frontendUrl('/hotel/subscription'))
            ->line("Sans renouvellement, l'accès aux fonctionnalités de check-in sera suspendu à l'expiration.")
            ->salutation("L'équipe Qayed");
    }
}
