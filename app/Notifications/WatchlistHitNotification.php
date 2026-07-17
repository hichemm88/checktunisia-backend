<?php

namespace App\Notifications;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\WatchlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WatchlistHitNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Hotel          $hotel,
        private Guest          $guest,
        private WatchlistEntry $entry,
        private string         $checkInId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $guestName = trim("{$this->guest->first_name} {$this->guest->last_name}");
        $alertLabel = match($this->entry->alert_level ?? 'medium') {
            'critical' => 'CRITIQUE',
            'high'     => 'ÉLEVÉ',
            default    => 'MOYEN',
        };

        return (new MailMessage)
            ->subject("[Qayed] Alerte watchlist — {$alertLabel}")
            ->greeting("Alerte de sécurité — {$this->hotel->name}")
            ->line("Un voyageur figurant sur la liste de surveillance a effectué un check-in dans votre établissement.")
            ->line("**Voyageur :** {$guestName}")
            ->line("**Niveau d'alerte :** {$alertLabel}")
            ->line("**Référence check-in :** {$this->checkInId}")
            ->action('Voir les alertes', url('/hotel/security'))
            ->line("Ne partagez pas cette information. Contactez les autorités compétentes si nécessaire.")
            ->salutation("L'équipe Qayed");
    }
}
