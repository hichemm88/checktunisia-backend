<?php

namespace App\Services\Whatsapp;

use App\Jobs\SendExpoPushJob;
use App\Mail\SystemMail;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Services\Email\SystemMailer;
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
        // Le QR lui-même ne peut pas être joint : il tourne toutes les ~30 s et
        // serait expiré à l'ouverture. On pointe vers la page /qr, qui affiche
        // toujours le QR frais et se rafraîchit seule.
        $qrUrl = (string) config('whatsapp.qr_url');

        $this->dispatch(
            "WhatsApp Qayed — session {$status}",
            "La session WhatsApp du relais police est « {$status} ». "
            .'Les check-ins continuent normalement, les envois s\'accumulent en attente et reprendront '
            .'automatiquement à la reconnexion.'
            .($reason ? "\n\nRaison : {$reason}" : '')
            ."\n\nPour reconnecter : ouvrez la page ci-dessous et scannez le QR avec le téléphone émetteur Qayed "
            .'(WhatsApp → Appareils connectés → Connecter un appareil).'
            .($qrUrl === '' ? "\n\nPage de reconnexion : voir le service WhatsApp sur Railway (URL /qr)." : ''),
            $qrUrl !== '' ? SystemMailer::ctaButton($qrUrl, 'Reconnecter (scanner le QR)') : null,
        );
    }

    /**
     * Worker muet : plus aucun battement de cœur depuis N minutes — le
     * conteneur est probablement mort (OOM, crash loop, déploiement bloqué),
     * il ne peut donc pas signaler lui-même sa panne.
     */
    public function workerSilent(int $minutes): void
    {
        $this->dispatch(
            'WhatsApp Qayed — worker injoignable',
            "Le worker WhatsApp n'a donné aucun signe de vie depuis {$minutes} minute(s). "
            .'Le conteneur est probablement arrêté ou bloqué : vérifiez le service Railway '
            .'(logs, redeploy). Les fiches s\'accumulent en attente et repartiront au retour du worker.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
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
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

    /**
     * @param  string|null  $ctaButton  bouton pré-rendu (SystemMailer::ctaButton) inséré sous le texte.
     */
    private function dispatch(string $subject, string $body, ?string $ctaButton = null): void
    {
        try {
            $admins = User::whereHas('roles', fn ($q) => $q->where('name', 'platform_admin'))->get();

            // Email — même habillage que les autres emails système (wrapShell).
            $html = SystemMailer::wrapShell(
                '<p style="margin:0 0 16px;">'.nl2br(e($body)).'</p>'.($ctaButton ?? ''),
            );
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
