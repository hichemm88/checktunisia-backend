<?php

namespace App\Services\Whatsapp;

use App\Jobs\SendExpoPushJob;
use App\Mail\SystemMail;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
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
    /**
     * Perte de session.
     *
     * L'alerte distingue désormais les deux situations, parce qu'elles
     * n'appellent pas la même action — et que les confondre coûtait cher : on
     * réclamait un QR pour une coupure passagère (déplacement inutile jusqu'au
     * téléphone émetteur), et on ne disait rien quand il en fallait vraiment un.
     *
     *  • logged_out → WhatsApp a révoqué l'appareil. RIEN ne repartira sans un
     *    ré-appairage humain.
     *  • le reste → incident technique, la reconnexion est automatique ; on
     *    donne quand même le lien, sans en faire une consigne.
     */
    public function sessionDown(string $status, ?string $reason, ?WhatsappSessionState $state = null): void
    {
        // Le QR lui-même ne peut pas être joint : il tourne toutes les ~30 s et
        // serait expiré à l'ouverture. On pointe vers la page /qr, qui affiche
        // toujours le QR frais et se rafraîchit seule.
        $qrUrl = (string) config('whatsapp.qr_url');
        $state ??= WhatsappSessionState::current();
        $needsPairing = $status === WhatsappSessionState::STATUS_LOGGED_OUT;

        $pending = WhatsappSendLog::where('status', WhatsappSendLog::STATUS_PENDING)->count();

        $context = [];
        if ($state->last_ready_at) {
            $context[] = 'Dernière session active : '.$state->last_ready_at->timezone('Africa/Tunis')->format('d/m/Y H:i');
        }
        if ($state->heartbeat_at) {
            $context[] = 'Dernier signe de vie du worker : '.$state->heartbeat_at->timezone('Africa/Tunis')->format('d/m/Y H:i');
        }
        $context[] = 'Fiches en attente : '.$pending;

        $subject = $needsPairing
            ? 'WhatsApp Qayed — ré-appairage nécessaire (QR)'
            : 'WhatsApp Qayed — session temporairement déconnectée';

        $body = $needsPairing
            ? 'WhatsApp a révoqué l\'appareil lié du relais police. Aucune reconnexion automatique n\'est '
                .'possible : le service restera en attente tant qu\'un QR n\'aura pas été scanné avec le '
                .'téléphone émetteur Qayed.'
            : 'La session WhatsApp du relais police est momentanément interrompue. Aucune action n\'est '
                .'requise : la reconnexion se fait automatiquement. Les check-ins continuent normalement et '
                .'les envois en attente repartiront seuls.';

        $body .= ($reason ? "\n\nRaison : {$reason}" : '')
            ."\n\n".implode("\n", $context);

        if ($needsPairing) {
            $body .= "\n\nPour reconnecter : ouvrez la page ci-dessous et scannez le QR avec le téléphone émetteur "
                .'Qayed (WhatsApp → Appareils connectés → Connecter un appareil).'
                .($qrUrl === '' ? "\n\nPage de reconnexion : voir le service WhatsApp sur Railway (URL /qr)." : '');
        }

        $this->dispatch(
            $subject,
            $body,
            $needsPairing && $qrUrl !== '' ? SystemMailer::ctaButton($qrUrl, 'Reconnecter (scanner le QR)') : null,
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
