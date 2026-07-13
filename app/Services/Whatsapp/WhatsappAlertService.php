<?php

namespace App\Services\Whatsapp;

use App\Jobs\SendExpoPushJob;
use App\Mail\SystemMail;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WhatsappSendLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Alertes admin du relais WhatsApp (push + email + log) :
 *  - session déconnectée / échec d'authentification (worker en pause),
 *  - job abandonné définitivement après 24 h de retries.
 *
 * Best-effort : une alerte qui échoue ne doit jamais casser le flux appelant.
 */
class WhatsappAlertService
{
    /** Session tombée (QR expiré, téléphone hors ligne, ban, auth échouée). */
    public function sessionDown(string $status, ?string $reason): void
    {
        $this->dispatch(
            "WhatsApp Qayed — session {$status}",
            "La session WhatsApp du relais police est « {$status} ». "
            .'Les check-ins continuent normalement, les envois s\'accumulent en attente et reprendront '
            .'automatiquement à la reconnexion.'
            .($reason ? "\n\nRaison : {$reason}" : '')
            ."\n\nScannez à nouveau le QR sur le service WhatsApp si nécessaire.",
        );
    }

    /** Job abandonné après épuisement des retries (24 h). */
    public function jobPermanentlyFailed(WhatsappSendLog $job, ?string $error): void
    {
        $this->dispatch(
            'WhatsApp Qayed — envoi définitivement échoué',
            "Un envoi WhatsApp a échoué définitivement après 24 h de tentatives.\n\n"
            ."Journal : {$job->id}\n"
            .'Check-in : '.($job->check_in_id ?? '—')."\n"
            .'Dernière erreur : '.($error ?? '—')."\n\n"
            .'Vous pouvez le renvoyer depuis l\'écran WhatsApp de l\'administration.',
        );
    }

    private function dispatch(string $subject, string $body): void
    {
        try {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'platform_admin'))->get();

            // Email
            $html = '<div style="font-family:sans-serif;font-size:15px;line-height:1.5;color:#1a1a1a;">'
                .nl2br(e($body)).'</div>';
            foreach ($admins as $admin) {
                if ($admin->email) {
                    try {
                        Mail::to($admin->email)->send(new SystemMail($subject, $html));
                    } catch (\Throwable $e) {
                        Log::warning('[whatsapp] alert email failed: '.$e->getMessage());
                    }
                }
            }

            // Push (best-effort — seuls les admins ayant l'app mobile en ont)
            $tokens = DeviceToken::whereIn('user_id', $admins->pluck('id'))->pluck('token')->all();
            if (! empty($tokens)) {
                dispatch(new SendExpoPushJob(array_values(array_unique($tokens)), $subject, $body, [
                    'type' => 'whatsapp_alert',
                ]))->afterResponse();
            }
        } catch (\Throwable $e) {
            Log::warning('[whatsapp] alert dispatch failed: '.$e->getMessage());
        }

        Log::error('[whatsapp] '.$subject.' — '.$body);
    }
}
