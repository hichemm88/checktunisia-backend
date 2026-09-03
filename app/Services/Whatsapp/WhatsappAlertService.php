<?php

namespace App\Services\Whatsapp;

use App\Jobs\SendExpoPushJob;
use App\Mail\SystemMail;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Email\SystemMailer;
use Illuminate\Support\Facades\Cache;
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

        // UN événement, UN email — quel que soit le nombre d'appelants.
        //
        // Deux mécanismes concurrents envoyaient l'alerte : la transition de
        // statut côté contrôleur, et la commande planifiée toutes les 10 min.
        // Chacun avait sa propre déduplication, et aucune ne portait sur
        // l'ÉVÉNEMENT :
        //   • le garde `$previous !== $status` re-alertait sur la séquence
        //     logged_out → disconnected → logged_out, soit deux emails de plus
        //     pour une seule et même révocation ;
        //   • le drapeau de la commande planifiée était levé dès que la session
        //     repassait « prête », si bien qu'une reprise de quelques minutes
        //     suffisait à autoriser un second email pour la panne suivante.
        //
        // La clé est désormais celle de l'événement lui-même : l'instant de la
        // révocation pour un logout, celui de la dernière session vivante pour
        // une coupure. Une nouvelle panne porte naturellement une nouvelle clé —
        // il n'y a plus de drapeau à lever, ni à effacer au bon moment.
        //
        // Cache::add est atomique (écrit seulement si absent) : deux appels
        // simultanés ne peuvent pas produire deux emails.
        if (!Cache::add($this->outageKey($status, $state), true, now()->addDays(7))) {
            Log::info('[whatsapp] alerte déjà envoyée pour cet événement de session — email supprimé.');

            return;
        }

        /*
         * Plancher de fréquence pour les coupures techniques — la clé
         * d'événement ne suffit pas quand les événements se succèdent.
         *
         * Une session qui tombe, revient, retombe fabrique à chaque cycle un
         * `last_ready_at` neuf, donc une clé neuve, donc un email de plus. Les
         * administrateurs recevaient une rafale de messages « aucune action
         * n'est requise » — le meilleur moyen de leur apprendre à ne plus les
         * lire, y compris le jour où l'un d'eux compte vraiment.
         *
         * Le plancher ne couvre QUE les coupures : un ré-appairage demande un
         * geste humain, il doit passer sans condition. Et une panne qui dure
         * n'est pas perdue de vue pour autant — `whatsapp:check-health` la
         * reprend toutes les 10 minutes.
         */
        if (!$needsPairing && !Cache::add('whatsapp:alerted:floor', true, now()->addHour())) {
            Log::info('[whatsapp] coupure signalée il y a moins d\'une heure — email supprimé (la panne reste suivie).');

            return;
        }

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
     * Identifiant de l'ÉVÉNEMENT de panne, et non de l'état courant.
     *
     *  • révocation → l'instant où WhatsApp a invalidé l'appareil ;
     *  • coupure    → l'instant de la dernière session vivante.
     *
     * Deux alertes portant la même clé décrivent la même panne, quels que
     * soient l'appelant et le libellé. Le repli sur la date du jour couvre le
     * cas où aucun des deux horodatages n'est connu (première mise en service) :
     * une alerte par jour au pire, jamais une boucle.
     */
    private function outageKey(string $status, WhatsappSessionState $state): string
    {
        // Tant qu'une révocation n'est pas réparée, TOUTE alerte de panne se
        // rapporte à elle. Sans cette convergence, une déconnexion technique
        // survenant après le logout aurait sa propre clé et enverrait un second
        // email — celui-là annonçant « reconnexion automatique, aucune action
        // requise », juste après avoir réclamé un QR. Message contradictoire
        // pour un seul et même incident.
        if ($state->revoked_at) {
            $scope = WhatsappSessionState::STATUS_LOGGED_OUT;
            $moment = $state->revoked_at;
        } else {
            $scope = $status;
            $moment = $state->last_ready_at;
        }

        return 'whatsapp:alerted:'.sha1($scope.'|'.($moment?->toAtomString() ?? now()->toDateString()));
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

    /**
     * Le CANAL est tombé, pas une fiche.
     *
     * Jeton révoqué, compte Meta verrouillé, modèle suspendu : plus rien ne
     * partira tant qu'un humain n'aura pas agi dans la console Meta. Ces
     * pannes-là se noyaient jusqu'ici dans les échecs d'envoi ordinaires,
     * alors qu'elles arrêtent l'obligation légale du produit.
     *
     * Déduplication à l'heure : une file de 200 fiches sur un jeton expiré ne
     * doit pas produire 200 emails.
     */
    public function channelDown(string $channel, ?string $reason): void
    {
        $key = 'whatsapp_channel_down:'.$channel;

        if (! Cache::add($key, true, now()->addHour())) {
            return;
        }

        $this->dispatch(
            'WhatsApp Qayed — canal de transmission bloqué',
            "Le canal « {$channel} » refuse tous les envois.\n\n"
            .'Cause : '.($reason ?? '—')."\n\n"
            .'Aucune fiche ne peut plus partir tant que le problème n\'est pas corrigé '
            .'dans la console Meta (jeton d\'accès, état du compte WhatsApp Business, '
            .'statut du modèle de message). Les fiches restent en file et repartiront '
            .'une fois le canal rétabli.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

    /**
     * Le modèle configuré ne correspond pas à ce qui est approuvé chez Meta.
     *
     * UNE alerte, pas une par fiche. C'est la correction directe de
     * l'incident : le canal a été ouvert alors que le modèle était encore en
     * attente d'approbation, et chaque fiche de la file a produit son propre
     * « échec définitif » avec son propre email — des dizaines de messages
     * décrivant tous la même panne, unique et réparable en une fois.
     *
     * Déduplication à la journée, et non à l'heure : une approbation Meta se
     * compte en heures ou en jours, et un rappel horaire pendant tout ce temps
     * n'apprendrait rien qui ne soit déjà su.
     */
    public function templateMisconfigured(?int $code, ?string $reason, string $templateName, string $language): void
    {
        if (! Cache::add('whatsapp_template_config:'.$templateName.':'.$language, true, now()->addDay())) {
            return;
        }

        $this->dispatch(
            'WhatsApp Qayed — modèle de message non utilisable, envois suspendus',
            "Meta refuse le modèle « {$templateName} » en « {$language} »".
            ($code !== null ? " (code {$code})" : '').".\n\n"
            .'Cause : '.($reason ?? '—')."\n\n"
            ."C'est une erreur de CONFIGURATION du canal, pas l'échec d'une fiche : les fiches "
            ."concernées restent EN FILE, aucune n'est marquée en échec, et aucune tentative "
            ."ne leur est décomptée.\n\n"
            ."À vérifier, dans cet ordre :\n"
            ."  1. le modèle est-il APPROVED chez Meta ? « php artisan whatsapp:templates »\n"
            ."  2. WHATSAPP_TEMPLATE_NAME et WHATSAPP_TEMPLATE_LANGUAGE correspondent-ils EXACTEMENT "
            ."au modèle approuvé ? Un écart d'une lettre produit ce code.\n\n"
            .'Les envois reprendront d\'eux-mêmes dès que le modèle sera approuvé : rien à relancer.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

    /**
     * Un arriéré s'est constitué et n'a PAS été envoyé.
     *
     * L'alerte porte sur la retenue, pas sur l'accumulation : c'est le fait
     * que des fiches attendent SANS partir qui exige une décision humaine.
     * L'envoi automatique d'un arriéré est exactement ce qui a coûté le
     * numéro émetteur précédent.
     */
    public function backlogHeldBack(int $pending, int $threshold): void
    {
        $this->dispatch(
            'WhatsApp Qayed — arriéré retenu, action requise',
            "{$pending} fiches sont en attente d'envoi (seuil d'alerte : {$threshold}).\n\n"
            ."L'envoi automatique est SUSPENDU : un arriéré de cette taille signale une panne, "
            ."et le vider d'un coup depuis un numéro neuf conduirait à un bannissement.\n\n"
            ."À faire : identifier la cause de l'accumulation, décider quelles fiches doivent "
            ."réellement partir, puis débloquer explicitement avec « php artisan whatsapp:allow-backlog ». "
            .'Aucune fiche n\'est perdue en attendant.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

    /** Plafond quotidien d'envois atteint : le reste part demain. */
    public function dailyCapReached(int $cap, int $pending): void
    {
        $this->dispatch(
            'WhatsApp Qayed — plafond quotidien atteint',
            "Le plafond de {$cap} envois par jour est atteint. {$pending} fiches restent en attente "
            ."et repartiront demain.\n\n"
            .'Ce plafond protège la réputation d\'un numéro émetteur encore neuf. S\'il est atteint '
            .'régulièrement, c\'est le plafond qu\'il faut relever (WHATSAPP_MAX_SENDS_PER_DAY), '
            .'pas les fiches qu\'il faut forcer.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

    /** Job abandonné après épuisement des retries (24 h). */
    /**
     * Des fiches ont ete ACCEPTEES par Meta sans jamais etre livrees.
     *
     * Distincte de `jobPermanentlyFailed()` : la, l'envoi a echoue et le
     * systeme le sait. Ici il croit avoir reussi. C'est justement pour cela que
     * l'alerte est necessaire — sans elle, l'ecran affiche un succes et le
     * poste de police n'a rien recu.
     *
     * Le message ne porte AUCUNE identite de voyageur : un email d'exploitation
     * n'a pas a transporter les personnes qu'il denombre.
     */
    public function fichesUndelivered(int $count, int $minutes): void
    {
        $this->dispatch(
            'WhatsApp Qayed — fiches acceptees mais jamais livrees',
            "{$count} fiche(s) ont ete acceptees par Meta il y a plus de {$minutes} min "
            ."sans accuse de livraison.

"
            ."Meta accuse reception a l'ACCEPTATION, pas a la livraison : ces fiches "
            ."apparaissent comme envoyees alors que le destinataire n'a peut-etre rien recu.

"
            .'Causes usuelles : numero sans compte WhatsApp, appareil eteint, numero '
            ."bloque, ou accuse de livraison perdu.

"
            .'A verifier dans le journal WhatsApp, puis renvoyer si necessaire.',
            SystemMailer::ctaButton(SystemMailer::frontendUrl('/admin/whatsapp'), 'Ouvrir le journal WhatsApp'),
        );
    }

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
     * Disjoncteur déclenché : WhatsApp refuse les envois alors que la session
     * fonctionne. Presque toujours une restriction de compte.
     *
     * L'alerte dit explicitement de NE PAS relancer tout de suite : le réflexe
     * naturel (« Reprendre », « Renvoyer tout ») est ici le geste qui transforme
     * une suspension de quelques heures en bannissement définitif du numéro.
     */
    public function relayHalted(?string $reason, WhatsappSessionState $state): void
    {
        $number = $state->phone_number ? ' ('.$state->phone_number.')' : '';

        $this->dispatch(
            'WhatsApp Qayed — relais coupé automatiquement',
            "Le relais WhatsApp a été mis en pause automatiquement : plusieurs envois d'affilée ont été "
            ."refusés alors que la session était connectée.\n\n"
            .'Motif : '.($reason ?? '—')."\n"
            ."Numéro émetteur{$number}\n\n"
            ."C'est la signature d'une restriction de compte imposée par WhatsApp. Les fiches sont "
            ."conservées en attente, rien n'est perdu.\n\n"
            .'NE RELANCEZ PAS tout de suite. Chaque nouvelle tentative pendant une restriction est une '
            ."infraction supplémentaire, et c'est ainsi qu'une suspension de quelques heures devient un "
            ."bannissement définitif du numéro.\n\n"
            .'Vérifiez d\'abord l\'état du compte dans WhatsApp sur le téléphone émetteur. Une fois la '
            .'restriction levée, reprenez avec « Reprendre » : les envois repartiront à cadence réduite.',
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
            if (!empty($tokens)) {
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
