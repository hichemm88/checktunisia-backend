<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappChannelOutage;
use App\Models\WhatsappSendLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Garde-fous d'émission de la Cloud API.
 *
 * Le numéro précédent a été banni. Le nouveau est neuf, sa vérification
 * d'entreprise est en cours, et un émetteur neuf qui expédie soudain des
 * centaines de messages vers des comptes qui ne lui ont jamais écrit est le
 * profil exact que Meta bannit. Cette classe est le seul endroit qui a le
 * droit de dire « pas maintenant ».
 *
 * Cinq freins, du plus grossier au plus fin :
 *
 *  1. COUPE-CIRCUIT (`sending_enabled`) — relu à chaque envoi, donc
 *     actionnable en quelques secondes sans redéploiement.
 *  2. BASCULE (`cutover_at`) — rien de ce qui a été enfilé avant la migration
 *     ne part, jamais. Non définie, la Cloud API n'envoie rien du tout : une
 *     bascule ne doit pas s'armer toute seule au déploiement.
 *  3. APPROBATION DU MODÈLE — tant que Meta n'a pas approuvé le modèle des
 *     fiches, AUCUNE tentative n'est faite. Le coupe-circuit et l'approbation
 *     sont deux conditions indépendantes, et c'est de les avoir confondues
 *     que vient l'incident : WHATSAPP_SENDING_ENABLED est passé à true alors
 *     que le modèle était encore PENDING, chaque fiche a brûlé ses tentatives
 *     sur des 132001, et l'abandon à 24 h les a déclarées définitivement
 *     échouées — alors qu'aucune n'avait jamais eu de chance d'aboutir.
 *  4. DÉBIT (par minute, par jour) — la file attend, elle n'est jamais vidée.
 *  5. ARRIÉRÉ (`backlog_alert_threshold`) — un arriéré n'est pas du travail en
 *     retard, c'est le symptôme d'une panne. Le vider automatiquement, c'est
 *     transformer une panne silencieuse en rafale vers des officiels : c'est
 *     très exactement ce qui vient de coûter le numéro précédent.
 *
 * Aucun de ces freins ne perd de fiche : tout reste en attente.
 *
 * Et parce qu'aucun ne perd de fiche, tous doivent SUSPENDRE L'HORLOGE : le
 * temps passé sous un de ces freins n'est pas du temps de tentative, il ne
 * peut donc pas compter dans les 24 h d'abandon. C'est le rôle du registre
 * `whatsapp_channel_outages`, alimenté par `noteCapability()`.
 */
class WhatsappSendingGuard
{
    private const PAUSE_KEY = 'whatsapp:global_pause_until';

    private const BACKLOG_ACK_KEY = 'whatsapp:backlog_ack_until';

    private const DAILY_ALERT_KEY = 'whatsapp:daily_cap_alerted';

    private const TZ = 'Africa/Tunis';

    public function __construct(
        private WhatsappAlertService $alerts,
        private WhatsappTemplateStatus $templates,
    ) {}

    /**
     * Pourquoi l'émission est-elle interdite en ce moment ?
     *
     * Renvoie null si tout est ouvert. Une phrase sinon — destinée au journal
     * et à la sortie de commande, jamais au destinataire.
     */
    public function blockingReason(): ?string
    {
        if (! config('whatsapp.guard.sending_enabled', true)) {
            return 'Coupe-circuit actif (WHATSAPP_SENDING_ENABLED=false).';
        }

        if ($this->cutoverAt() === null) {
            return 'Bascule non armée : WHATSAPP_CLOUD_API_CUTOVER_AT n\'est pas définie. '
                .'Tant qu\'elle est absente, la Cloud API n\'émet rien — c\'est volontaire.';
        }

        if ($reason = $this->templateBlock()) {
            return $reason;
        }

        if ($until = $this->pausedUntil()) {
            return 'Pause globale jusqu\'à '.$until->toDateTimeString().' (limite de débit ou de qualité côté Meta).';
        }

        if ($this->dailyCapReached()) {
            return 'Plafond quotidien atteint ('.$this->dailyCap().' envois). Reprise demain.';
        }

        if ($reason = $this->backlogBlock()) {
            return $reason;
        }

        return null;
    }

    /**
     * Le modèle des fiches interdit-il d'émettre ?
     *
     * Ne s'applique qu'au canal Cloud API : le relais WhatsApp Web n'a pas de
     * modèle, et lui opposer une approbation Meta le bloquerait pour une règle
     * qui ne le concerne pas.
     *
     * L'OTP (qayed_otp) n'est pas concerné non plus — il est approuvé et
     * emprunte un tout autre chemin (WhatsappOtpSender). Refuser une connexion
     * d'agent parce qu'un modèle de FICHE attend sa validation reviendrait à
     * fermer le portail pour un problème qui n'est pas le sien.
     */
    public function templateBlock(): ?string
    {
        if (config('whatsapp.channel') !== 'cloud') {
            return null;
        }

        return $this->templates->blockingReason();
    }

    /** Instant de bascule, ou null si la variable est absente ou illisible. */
    public function cutoverAt(): ?Carbon
    {
        $raw = config('whatsapp.guard.cutover_at');

        if (blank($raw)) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            // Une date illisible vaut une date absente : on ne devine pas une
            // bascule, on refuse d'émettre.
            Log::error('[whatsapp-guard] WHATSAPP_CLOUD_API_CUTOVER_AT illisible : '.$raw);

            return null;
        }
    }

    /**
     * Cette entrée date-t-elle d'AVANT la bascule ?
     *
     * Sur `created_at`, jamais sur `queued_at` : un renvoi manuel réécrit
     * `queued_at` à maintenant, ce qui ferait passer pour récente une fiche de
     * l'arriéré. `created_at` ne bouge pas — c'est le seul repère qui résiste
     * à toutes les façons de relancer une fiche.
     */
    public function isPreCutover(WhatsappSendLog $job): bool
    {
        $cutover = $this->cutoverAt();

        if ($cutover === null) {
            // Sans bascule armée, rien ne part : toute fiche est « avant ».
            return true;
        }

        return $job->created_at !== null && $job->created_at->lt($cutover);
    }

    /** Reste-t-il du budget sur la minute en cours ? */
    public function minuteBudgetAvailable(): bool
    {
        $max = (int) config('whatsapp.guard.max_sends_per_minute', 20);

        return $max <= 0 || $this->counter($this->minuteKey()) < $max;
    }

    public function dailyCapReached(): bool
    {
        $max = $this->dailyCap();

        return $max > 0 && $this->counter($this->dayKey()) >= $max;
    }

    /**
     * Comptabilise un envoi effectué. Appelé APRÈS acceptation par Meta : un
     * refus ne consomme pas de quota de réputation.
     */
    public function recordSend(): void
    {
        $this->increment($this->minuteKey(), now()->addMinutes(2));
        $this->increment($this->dayKey(), now(self::TZ)->endOfDay()->addHour());
    }

    /**
     * Suspend tout envoi pendant N minutes.
     *
     * Déclenchée par les codes Meta de débit ou de qualité : continuer à
     * pousser quand Meta vient de dire « trop » est le comportement qui fait
     * passer d'une limitation temporaire à un bannissement.
     */
    public function pauseGlobally(?int $minutes = null, ?string $reason = null): void
    {
        $minutes = $minutes ?? (int) config('whatsapp.guard.quality_pause_minutes', 15);
        $until = now()->addMinutes($minutes);

        Cache::put(self::PAUSE_KEY, $until->toIso8601String(), $until->copy()->addMinutes(5));

        Log::warning('[whatsapp-guard] pause globale de '.$minutes.' min — '.($reason ?? 'sans motif'));
    }

    public function pausedUntil(): ?Carbon
    {
        $raw = Cache::get(self::PAUSE_KEY);

        if (blank($raw)) {
            return null;
        }

        $until = Carbon::parse($raw);

        return $until->isFuture() ? $until : null;
    }

    /** Nombre de fiches réellement en attente d'envoi. */
    public function pendingCount(): int
    {
        return WhatsappSendLog::where('status', WhatsappSendLog::STATUS_PENDING)->count();
    }

    /**
     * Autorise l'envoi d'un arriéré, pour une durée bornée.
     *
     * Le déblocage est volontairement TEMPORAIRE : une autorisation permanente
     * redeviendrait, au premier incident suivant, le comportement qu'on vient
     * de retirer.
     */
    public function acknowledgeBacklog(int $minutes = 60): Carbon
    {
        $until = now()->addMinutes($minutes);

        Cache::put(self::BACKLOG_ACK_KEY, $until->toIso8601String(), $until->copy()->addMinutes(5));

        return $until;
    }

    /**
     * L'arriéré bloque-t-il l'envoi automatique ?
     *
     * Alerte une fois par heure au plus : un arriéré bloqué reste bloqué, il
     * ne faut pas en faire une pluie d'emails.
     */
    private function backlogBlock(): ?string
    {
        $threshold = (int) config('whatsapp.guard.backlog_alert_threshold', 50);

        if ($threshold <= 0) {
            return null;
        }

        $pending = $this->pendingCount();

        if ($pending <= $threshold) {
            return null;
        }

        $ack = Cache::get(self::BACKLOG_ACK_KEY);
        if (filled($ack) && Carbon::parse($ack)->isFuture()) {
            return null;
        }

        if (Cache::add('whatsapp:backlog_alerted', true, now()->addHour())) {
            $this->alerts->backlogHeldBack($pending, $threshold);
        }

        return "Arriéré de {$pending} fiches en attente (seuil : {$threshold}). "
            .'Envoi automatique suspendu — vérifier la cause, puis débloquer avec '
            .'« php artisan whatsapp:allow-backlog ».';
    }

    /**
     * Consigne, à chaque passe d'envoi, si le canal était capable d'émettre.
     *
     * C'est le seul endroit qui alimente le registre d'incapacité, et il est
     * appelé par la boucle d'envoi — pas par l'écran d'administration. Une
     * sonde de supervision qui ouvre et referme des périodes d'arrêt écrirait
     * un registre qui décrit l'observateur, pas le canal.
     *
     * Conséquence assumée : si le planificateur lui-même est arrêté, aucune
     * incapacité n'est consignée et l'horloge des 24 h continue de tourner.
     * C'est le bon sens de l'erreur — on ne prolonge la vie d'une fiche que
     * sur la foi d'un constat, jamais sur une absence de constat.
     */
    public function noteCapability(?string $blockingReason): void
    {
        if ($blockingReason === null) {
            WhatsappChannelOutage::end();

            return;
        }

        WhatsappChannelOutage::begin($blockingReason);
    }

    /**
     * Minutes, depuis $from, pendant lesquelles le canal ne pouvait rien
     * émettre — à retrancher de l'âge d'une fiche avant de l'abandonner.
     */
    public function unavailableMinutesSince(Carbon $from): int
    {
        return WhatsappChannelOutage::minutesOverlapping($from);
    }

    /**
     * Âge d'un envoi, décompté du seul temps où il POUVAIT partir.
     *
     * Les 24 h d'abandon sont un budget de tentatives, pas un délai de
     * péremption. Compter dedans une attente d'approbation de modèle revient à
     * jeter une fiche pour une raison qui ne lui appartient pas — c'est
     * littéralement ce qui a produit les « échecs définitifs » de l'incident.
     */
    public function effectiveAgeMinutes(Carbon $queuedAt): int
    {
        $wall = (int) abs(now()->getTimestamp() - $queuedAt->getTimestamp());

        return max(0, intdiv($wall, 60) - $this->unavailableMinutesSince($queuedAt));
    }

    private function dailyCap(): int
    {
        return (int) config('whatsapp.guard.max_sends_per_day', 500);
    }

    /**
     * Alerte le plafond quotidien, une seule fois par jour, et renvoie s'il
     * vient d'être franchi. Appelé par la boucle d'envoi.
     */
    public function noteDailyCapReached(): void
    {
        if (Cache::add(self::DAILY_ALERT_KEY.':'.now(self::TZ)->toDateString(), true, now(self::TZ)->endOfDay())) {
            $this->alerts->dailyCapReached($this->dailyCap(), $this->pendingCount());
        }
    }

    private function minuteKey(): string
    {
        return 'whatsapp:sends:'.now()->format('YmdHi');
    }

    private function dayKey(): string
    {
        return 'whatsapp:sends:'.now(self::TZ)->format('Ymd');
    }

    private function counter(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    /**
     * Incrémente un compteur en créant la clé si besoin.
     *
     * `Cache::increment` ne crée pas la clé sur tous les pilotes ; `add` puis
     * `increment` couvre les deux cas sans dépendre du pilote configuré.
     */
    private function increment(string $key, Carbon $expiresAt): void
    {
        if (! Cache::add($key, 1, $expiresAt)) {
            Cache::increment($key);
        }
    }
}
