<?php

namespace App\Services\Whatsapp;

use App\Contracts\DeliveryChannel;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Subscription\PlanEntitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Cœur métier du relais WhatsApp côté Laravel :
 *  - enfilage (un message par voyageur, non bloquant pour le check-in),
 *  - distribution FIFO au worker Node (un envoi à la fois),
 *  - planification des retries (backoff exponentiel) et abandon définitif,
 *  - renvoi manuel et fiche de test.
 *
 * Le worker Node ne fait qu'émettre : toute la logique reste ici, en PHP
 * testable. Le destinataire est porté par le job (colonne `recipient`) pour
 * que le futur passage au multi-destinataires ne touche que l'enfilage.
 */
class WhatsappOutboxService
{
    /** Fenêtre au-delà de laquelle une réclamation « bloquée » est reprise (worker crashé en plein envoi). */
    private const CLAIM_LOCK_SECONDS = 120;

    /**
     * Motif d'annulation de l'arriéré accumulé pendant le bannissement du
     * relais WhatsApp Web. Valeur stable : elle sert de filtre dans le journal
     * admin et dans le rapport CSV, pas seulement de texte d'affichage.
     */
    public const PRE_CUTOVER_REASON = 'pre_cutover_backlog';

    public function __construct(private WhatsappAlertService $alerts) {}

    /**
     * Canal de transmission actif (STRAT-07). Résolu à l'appel plutôt
     * qu'injecté : la config peut changer entre deux requêtes, et les tests
     * basculent de canal à la volée.
     */
    private function channel(): DeliveryChannel
    {
        return app(DeliveryChannelManager::class)->active();
    }

    public function enabled(): bool
    {
        // Délégué au canal — la condition est identique à celle d'avant pour
        // WhatsApp Web, mais chaque canal porte désormais la sienne.
        return $this->channel()->isConfigured();
    }

    /**
     * Enfile un message par voyageur du check-in. JAMAIS bloquant : toute erreur
     * est avalée et journalisée — un échec WhatsApp ne doit jamais gêner le check-in.
     *
     * @return int nombre de jobs enfilés
     */
    public function enqueueForCheckIn(CheckIn $checkIn): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        try {
            $checkIn->loadMissing(['hotel.organization', 'hotel.address', 'room', 'guests.documents']);

            // Le relais peut être coupé par pack ou par client (Admin > Abonnements).
            $org = $checkIn->hotel?->organization;
            if ($org && !PlanEntitlements::allows($org, 'whatsapp_relay')) {
                Log::info('[whatsapp] relais désactivé par le pack pour org '.$org->id.' — check-in '.$checkIn->id.' non enfilé.');

                return 0;
            }

            // Destinataires : agents assignés à l'établissement, sinon numéro global.
            $recipients = $this->recipientsForHotel($checkIn->hotel);

            // Voyageur principal d'abord, puis les accompagnants.
            $guests = $checkIn->guests
                ->sortByDesc(fn ($g) => (bool) ($g->pivot->is_primary ?? false))
                ->values();

            $count = 0;
            foreach ($guests as $guest) {
                // Une fiche par (voyageur × destinataire).
                foreach ($recipients as $recipient) {
                    // Garde-fou anti-doublon, identique à enqueueForGuest() : une
                    // fiche déjà journalisée pour ce couple (voyageur, destinataire)
                    // ne doit JAMAIS repartir. Sans lui, toute finalisation rejouée
                    // — retry après timeout, worker relancé, ou simplement une
                    // fiche ré-enfilée — renvoyait la fiche de police au poste.
                    $already = WhatsappSendLog::where('check_in_id', $checkIn->id)
                        ->where('guest_id', $guest->id)
                        ->where('recipient', $recipient)
                        ->exists();

                    if ($already) {
                        continue;
                    }

                    if ($this->createJob($checkIn, $guest, $recipient)) {
                        $count++;
                    }
                }
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('[whatsapp] enqueue failed for check-in '.$checkIn->id.': '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Enfile la fiche d'UN SEUL voyageur — cas d'un voyageur ajouté à un séjour
     * DÉJÀ finalisé (enqueueForCheckIn ne tourne qu'à la finalisation, donc sa
     * fiche ne partait jamais). Idempotent : ne fait rien si une fiche existe
     * déjà pour ce couple check-in/voyageur. JAMAIS bloquant, comme le reste.
     */
    public function enqueueForGuest(CheckIn $checkIn, Guest $guest): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        try {
            // Rechargement explicite (et non loadMissing) : l'appelant vient
            // d'ajouter le voyageur, une relation déjà chargée serait périmée et
            // fausserait le choix de la photo (photoScanId compte les voyageurs).
            $checkIn->load(['hotel.organization', 'hotel.address', 'room', 'guests.documents']);

            $org = $checkIn->hotel?->organization;
            if ($org && !PlanEntitlements::allows($org, 'whatsapp_relay')) {
                Log::info('[whatsapp] relais désactivé par le pack pour org '.$org->id.' — voyageur '.$guest->id.' non enfilé.');

                return false;
            }

            $recipients = $this->recipientsForHotel($checkIn->hotel);
            $any = false;
            foreach ($recipients as $recipient) {
                // Garde-fou anti-doublon PAR destinataire : ne jamais renvoyer une
                // fiche déjà journalisée pour ce couple (voyageur, destinataire).
                $already = WhatsappSendLog::where('check_in_id', $checkIn->id)
                    ->where('guest_id', $guest->id)
                    ->where('recipient', $recipient)
                    ->exists();

                if ($already) {
                    continue;
                }

                if ($this->createJob($checkIn, $guest, $recipient)) {
                    $any = true;
                }
            }

            return $any;
        } catch (\Throwable $e) {
            Log::warning('[whatsapp] enqueue failed for guest '.$guest->id.' (check-in '.$checkIn->id.'): '.$e->getMessage());

            return false;
        }
    }

    /**
     * Crée la ligne de journal/file pour un voyageur.
     *
     * @return bool true si la fiche est réellement enfilée (identité présente)
     */
    private function createJob(CheckIn $checkIn, Guest $guest, string $recipient): bool
    {
        // Le jeton est tiré AVANT la création : il entre à la fois dans la
        // ligne et dans les variables du modèle, qui portent le suffixe du
        // bouton. Le générer après obligerait à réécrire les variables.
        $ficheToken = (string) Str::ulid();

        // Jamais de fiche police sans identité voyageur : on trace le
        // blocage dans le journal (cause visible côté admin) au lieu
        // d'envoyer une fiche « — » inutilisable.
        $hasIdentity = trim((string) $guest->first_name.(string) $guest->last_name) !== '';

        WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $guest->id,
            'scan_id' => $this->photoScanId($checkIn, $guest),
            'recipient' => $recipient,
            // La légende texte reste écrite quel que soit le canal : c'est ce
            // que lit l'écran admin, et c'est ce qui repart si l'on rebascule
            // sur le relais Web.
            'caption' => FicheFormatter::format($checkIn, $guest),
            'template_name' => (string) config('whatsapp.cloud.template.name'),
            'template_language' => (string) config('whatsapp.cloud.template.language'),
            'template_params' => FicheTemplate::params($checkIn, $guest, $ficheToken),
            'public_token' => $ficheToken,
            'channel' => $this->channel()->name(),
            'status' => $hasIdentity ? WhatsappSendLog::STATUS_PENDING : WhatsappSendLog::STATUS_CANCELLED,
            'last_error' => $hasIdentity ? null : 'Identité voyageur manquante (nom et prénom vides) — fiche bloquée avant envoi.',
            'next_attempt_at' => $hasIdentity ? now() : null,
            'queued_at' => now(),
        ]);

        return $hasIdentity;
    }

    /**
     * Destinataires WhatsApp (JID …@c.us) d'un établissement.
     *
     * Envoi direct : si le routage direct est actif ET que l'établissement a des
     * agents assignés qui reçoivent les fiches → on renvoie leurs numéros. Sinon
     * REPLI sur le numéro global (comportement historique). Ainsi un établissement
     * non configuré ne change pas, et on bascule établissement par établissement.
     *
     * @return array<int,string> liste de JID (jamais vide : au pire le global)
     */
    public function recipientsForHotel(?Hotel $hotel): array
    {
        $recipients = $this->channel()->recipientsFor($hotel);

        // Mode ombre : exerce le canal cible à blanc sur le même établissement
        // et journalise tout écart. Ne transmet rien, n'appelle pas le réseau,
        // et n'échoue jamais bruyamment.
        app(DeliveryChannelManager::class)
            ->compareRecipients($hotel, $recipients);

        return $recipients;
    }

    /** Numéro (chiffres internationaux) → adresse native du canal actif. */
    private function toJid(?string $number): ?string
    {
        return $this->channel()->formatRecipient($number);
    }

    /** Enfile une fiche factice [TEST] pour le bouton « message test » admin. */
    public function enqueueTest(?string $propertyName = null): ?WhatsappSendLog
    {
        if (!$this->enabled()) {
            return null;
        }

        // Le destinataire du test passe par le canal ACTIF : le numéro global
        // est stocké au format WhatsApp Web (« …@c.us ») et la Cloud API le
        // refuserait tel quel. Un bouton de test qui échoue pour cette raison
        // ferait conclure à une panne du canal.
        $recipient = $this->channel()->formatRecipient((string) config('whatsapp.recipient'));

        if ($recipient === null) {
            return null;
        }

        $ficheToken = (string) Str::ulid();

        return WhatsappSendLog::create([
            'hotel_id' => null,
            'recipient' => $recipient,
            'caption' => FicheFormatter::testFiche($propertyName),
            'template_name' => (string) config('whatsapp.cloud.template.name'),
            'template_language' => (string) config('whatsapp.cloud.template.language'),
            'template_params' => FicheTemplate::testParams($propertyName, $ficheToken),
            'public_token' => $ficheToken,
            'channel' => $this->channel()->name(),
            'status' => WhatsappSendLog::STATUS_PENDING,
            'is_test' => true,
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ]);
    }

    /**
     * Réclame le prochain job dispatchable pour le worker Node (FIFO, un seul à
     * la fois). Renvoie null s'il n'y a rien à envoyer, si la session n'est pas
     * prête, si le module est en pause ou désactivé.
     */
    public function claimNextJob(): ?WhatsappSendLog
    {
        // `queueMayAdvance()` porte la même distinction pull/push que la
        // branche main écrivait ici : en PUSH il n'y a pas de session à
        // attendre, seulement la pause humaine.
        if (! $this->enabled() || ! $this->queueMayAdvance()) {
            return null;
        }

        // Plafond HORAIRE (et montée en charge après appairage), venu des
        // garde-fous écrits après la restriction du 17/08. Appliqué ICI et pas
        // côté worker, parce qu'un worker qui redémarre repart avec un
        // compteur vierge — le seul endroit qui sache combien de messages sont
        // partis dans l'heure, c'est le journal.
        //
        // Il se cumule avec le plafond par MINUTE et par JOUR de
        // WhatsappSendingGuard : ils ne mesurent pas la même chose et se
        // complètent (rafale courte / usure quotidienne / réputation horaire).
        if (! $this->throttle()['allowed']) {
            return null;
        }

        return DB::transaction(function () {
            $staleBefore = now()->subSeconds(self::CLAIM_LOCK_SECONDS);

            $job = WhatsappSendLog::query()
                ->where('status', WhatsappSendLog::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', $staleBefore))
                ->orderBy('queued_at')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if (!$job) {
                return null;
            }

            $job->update([
                'claimed_at' => now(),
                'attempts' => $job->attempts + 1,
            ]);

            return $job->fresh();
        });
    }

    /**
     * La file peut-elle avancer ?
     *
     * En PULL (WhatsApp Web), il faut une session appairée et prête. En PUSH
     * (Cloud API), il n'y a pas de session du tout : exiger « ready »
     * bloquerait la file à jamais, puisque plus aucun worker ne vient poser
     * cet état. Le bouton « pause » de l'administration, lui, reste souverain
     * dans les deux cas — c'est le seul frein d'urgence disponible.
     */
    private function queueMayAdvance(): bool
    {
        $state = WhatsappSessionState::current();

        return $this->channel()->supportsPush()
            ? ! $state->paused
            : $state->canDispatch();
    }

    /**
     * Cadence et plafond en vigueur, tenant compte de la montée en charge.
     *
     * Une seule source pour trois consommateurs : la distribution
     * (claimNextJob), le worker (control) et l'écran d'admin (health) — sans
     * quoi l'administrateur verrait « 14 en attente » sans jamais comprendre
     * pourquoi rien ne part.
     *
     * @return array{allowed:bool, warmup:bool, sent_last_hour:int, max_per_hour:int, min_interval_seconds:int, next_slot_at:?Carbon}
     */
    public function throttle(?WhatsappSessionState $state = null): array
    {
        $state ??= WhatsappSessionState::current();
        $warmup = $state->inWarmup();

        $maxPerHour = (int) config($warmup ? 'whatsapp.warmup_max_per_hour' : 'whatsapp.max_per_hour', $warmup ? 6 : 30);
        $minInterval = (int) config($warmup ? 'whatsapp.warmup_min_interval_seconds' : 'whatsapp.min_interval_seconds', $warmup ? 120 : 45);

        $windowStart = now()->subHour();
        $recent = WhatsappSendLog::query()
            ->where('status', WhatsappSendLog::STATUS_SENT)
            ->where('sent_at', '>=', $windowStart)
            ->orderBy('sent_at');

        $sentLastHour = (clone $recent)->count();
        $allowed = $maxPerHour <= 0 || $sentLastHour < $maxPerHour;

        // Fenêtre GLISSANTE : la prochaine place se libère quand le plus ancien
        // envoi de la fenêtre en sort, pas au prochain top d'heure.
        $nextSlotAt = null;
        if (!$allowed) {
            $oldest = (clone $recent)->value('sent_at');
            $nextSlotAt = $oldest ? Carbon::parse($oldest)->addHour() : now()->addHour();
        }

        return [
            'allowed' => $allowed,
            'warmup' => $warmup,
            'sent_last_hour' => $sentLastHour,
            'max_per_hour' => $maxPerHour,
            'min_interval_seconds' => $minInterval,
            'next_slot_at' => $nextSlotAt,
        ];
    }

    /**
     * Disjoncteur : coupe le relais et alerte, après N refus d'affilée que le
     * worker n'impute PAS à sa page.
     *
     * La pause est écrite en base — donc durable, contrairement à la veille
     * interne du worker qui disparaît à son redémarrage. C'est délibéré : un
     * blocage de compte ne se dissipe pas parce qu'un conteneur a redémarré, et
     * reprendre les envois « au cas où » est exactement ce qui transforme une
     * restriction temporaire en bannissement définitif. La reprise reste un
     * geste humain (bouton « Reprendre »).
     *
     * @return bool false si le relais était déjà en pause (rien à faire, pas de
     *              seconde alerte)
     */
    public function tripCircuitBreaker(?string $reason): bool
    {
        $state = WhatsappSessionState::current();

        if ($state->paused) {
            return false;
        }

        $state->forceFill(['paused' => true])->save();

        // Les fiches réclamées mais jamais confirmées repartiraient au bout du
        // verrou de 120 s ; on les rend tout de suite à la file, à leur place
        // d'origine. Rien n'est perdu, rien ne double.
        WhatsappSendLog::query()
            ->where('status', WhatsappSendLog::STATUS_PENDING)
            ->whereNotNull('claimed_at')
            ->update(['claimed_at' => null]);

        Log::warning('[whatsapp] disjoncteur déclenché — relais mis en pause : '.$reason);
        $this->alerts->relayHalted($reason, $state);

        return true;
    }

    /**
     * L'envoi a été accepté.
     *
     * « Accepté » et non « reçu » : avec la Cloud API, un 200 signifie que Meta
     * prend le message en charge. La livraison réelle arrive ensuite par
     * webhook (delivered / read / failed) et alimente `delivery_status`.
     */
    public function markSent(WhatsappSendLog $job, ?string $messageId): void
    {
        $job->update([
            'status' => WhatsappSendLog::STATUS_SENT,
            'sent_at' => now(),
            'message_id_whatsapp' => $messageId,
            'channel' => $this->channel()->name(),
            'delivery_status' => WhatsappSendLog::DELIVERY_ACCEPTED,
            'claimed_at' => null,
            'last_error' => null,
            'error_code' => null,
        ]);
    }

    /**
     * Échec d'une tentative. Applique le backoff, ou abandonne définitivement
     * (+ alerte admin) au-delà de 24 h.
     *
     * `$retryable = false` court-circuite le backoff : certaines erreurs de la
     * Cloud API ne changeront jamais d'issue (numéro sans compte WhatsApp,
     * modèle refusé, variables invalides). Les repasser en file 24 h durant ne
     * ferait que retarder de 24 h l'alerte qui aurait dû partir tout de suite,
     * sur un canal qui porte une obligation légale.
     */
    public function markFailed(WhatsappSendLog $job, ?string $error, bool $retryable = true, ?string $errorCode = null): void
    {
        $ageMinutes = Carbon::parse($job->queued_at)->diffInMinutes(now());
        $maxAge = (int) config('whatsapp.max_age_minutes', 1440);

        if (! $retryable || $ageMinutes >= $maxAge) {
            $job->update([
                'status' => WhatsappSendLog::STATUS_FAILED,
                'last_error' => $error,
                'error_code' => $errorCode,
                'claimed_at' => null,
                'next_attempt_at' => null,
            ]);
            $this->alerts->jobPermanentlyFailed($job, $error);

            return;
        }

        $job->update([
            'status' => WhatsappSendLog::STATUS_PENDING,
            'last_error' => $error,
            'error_code' => $errorCode,
            'claimed_at' => null,
            'next_attempt_at' => $this->nextAttemptAt($job->attempts),
        ]);
    }

    /**
     * Vide la file par le canal actif, quand celui-ci transmet lui-même.
     *
     * Un job = un couple (voyageur, destinataire). Chaque envoi est donc
     * indépendant : un destinataire injoignable ne doit pas empêcher les
     * autres de recevoir la fiche — d'où la boucle qui ENCAISSE l'échec et
     * poursuit, au lieu de s'interrompre à la première erreur.
     *
     * Bornée par `$max` : la commande tourne chaque minute et doit rendre la
     * main avant la suivante.
     *
     * @return array{sent: int, failed: int}
     */
    public function dispatchPending(int $max = 50): array
    {
        $channel = $this->channel();

        if (! $channel->supportsPush() || ! $this->enabled()) {
            return ['sent' => 0, 'failed' => 0, 'cancelled' => 0, 'blocked' => null];
        }

        $guard = app(WhatsappSendingGuard::class);

        // Un seul contrôle complet en tête de boucle : inutile de recompter
        // l'arriéré à chaque fiche, et un refus doit se lire une fois, pas
        // cinquante.
        if ($blocked = $guard->blockingReason()) {
            Log::info('[whatsapp] envoi suspendu — '.$blocked);

            return ['sent' => 0, 'failed' => 0, 'cancelled' => 0, 'blocked' => $blocked];
        }

        $sent = 0;
        $failed = 0;
        $cancelled = 0;

        // Refus consécutifs imputables à NOUS (numéro refusé, message rejeté,
        // compte suspendu) — pas les incidents réseau, qui ne disent rien de
        // notre légitimité. Au-delà du seuil, le disjoncteur coupe le relais.
        $refusals = 0;
        $ceiling = (int) config('whatsapp.circuit_breaker_failures', 5);

        for ($i = 0; $i < $max; $i++) {
            // Budget de débit AVANT la réclamation : réclamer puis renoncer
            // consommerait une tentative sans qu'aucun envoi ait été tenté.
            if (! $guard->minuteBudgetAvailable()) {
                break;
            }

            if ($guard->dailyCapReached()) {
                $guard->noteDailyCapReached();
                break;
            }

            // Une pause a pu s'armer en cours de boucle (code de débit ou de
            // qualité renvoyé par Meta sur la fiche précédente).
            if ($guard->pausedUntil() !== null) {
                break;
            }

            $job = $this->claimNextJob();

            if ($job === null) {
                break;
            }

            if ($guard->isPreCutover($job)) {
                $this->cancelPreCutover($job);
                $cancelled++;

                continue;
            }

            /*
             * Cadence entre deux envois, avec gigue.
             *
             * Reprise du canal historique, et pas seulement par prudence : une
             * régularité à la milliseconde près est en soi une signature
             * d'automate, et la rafale est ce que les heuristiques anti-spam
             * repèrent en premier — canal officiel ou non.
             *
             * L'attente est AVANT l'envoi et seulement à partir du deuxième :
             * placée après, elle s'appliquait aussi au dernier envoi d'une file
             * vidée, immobilisant l'exécution pour rien.
             */
            if ($sent > 0) {
                usleep($this->pacingMicroseconds());
            }

            try {
                $result = $channel->send($job);
            } catch (\Throwable $e) {
                // Un bug d'adaptateur ne doit pas faire tomber la boucle et
                // bloquer avec elle toutes les fiches suivantes.
                Log::error('[whatsapp] envoi en exception pour le job '.$job->id.' : '.$e->getMessage());
                $this->markFailed($job, 'Exception a l\'envoi : '.$e->getMessage());
                $failed++;

                continue;
            }

            if ($result->success) {
                $this->markSent($job, $result->messageId);
                $sent++;
                $refusals = 0;

                continue;
            }

            $this->markFailed($job, $result->error, $result->retryable, $result->errorCode);
            $failed++;

            if ($result->retryable) {
                // Réseau, 5xx, limitation : rien à conclure sur notre
                // légitimité. On rend la main sans incriminer le compte.
                break;
            }

            $refusals++;
            if ($ceiling > 0 && $refusals >= $ceiling) {
                // Plusieurs refus d'affilée alors que l'API répond : c'est la
                // signature d'une restriction de compte. Chaque tentative de
                // plus est une infraction supplémentaire — c'est ainsi qu'une
                // suspension de quelques heures devient un bannissement.
                $this->tripCircuitBreaker(
                    $refusals.' envois refusés d\'affilée par '.$channel->name()
                    .' — dernière erreur : '.$result->error
                );
                break;
            }

            if ($result->critical) {
                // Jeton révoqué, compte verrouillé, modèle suspendu : ce n'est
                // pas cette fiche qui a échoué, c'est le canal. Poursuivre la
                // boucle brûlerait toute la file sur la même panne.
                $this->alerts->channelDown($channel->name(), $result->error);
                break;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'cancelled' => $cancelled, 'blocked' => null];
    }

    /**
     * Plancher de cadence, augmenté d'une gigue aléatoire.
     *
     * La gigue n'est pas une coquetterie : un intervalle constant est une
     * empreinte d'automate aussi lisible qu'une rafale.
     */
    private function pacingMicroseconds(): int
    {
        $seconds = max(1, (int) $this->throttle()['min_interval_seconds']);
        $ratio = min(max((float) config('whatsapp.interval_jitter_ratio', 0.4), 0), 1);

        return (int) round(($seconds + mt_rand(0, 1000) / 1000 * $ratio * $seconds) * 1_000_000);
    }

    /**
     * Neutralise une fiche de l'arriéré antérieur à la bascule.
     *
     * Annulée, pas supprimée : la ligne reste consultable dans le journal
     * admin (compteur « Annulés »), avec un motif qui dit pourquoi. Sur un
     * canal qui porte une obligation légale, effacer la trace d'une fiche non
     * transmise serait pire que de ne pas l'avoir transmise.
     */
    public function cancelPreCutover(WhatsappSendLog $job): void
    {
        $job->update([
            'status' => WhatsappSendLog::STATUS_CANCELLED,
            'error_code' => self::PRE_CUTOVER_REASON,
            'last_error' => self::PRE_CUTOVER_REASON.' — fiche antérieure à la bascule Cloud API, '
                .'non transmise, annulée le '.now()->toDateTimeString().'.',
            'next_attempt_at' => null,
            'claimed_at' => null,
        ]);
    }

    /** Progression du cycle de vie côté Meta ; un état inconnu ne recule rien. */
    private static function deliveryRank(?string $status): int
    {
        return match ($status) {
            WhatsappSendLog::DELIVERY_ACCEPTED => 1,
            WhatsappSendLog::DELIVERY_SENT => 2,
            WhatsappSendLog::DELIVERY_DELIVERED => 3,
            WhatsappSendLog::DELIVERY_READ => 4,
            default => 0,
        };
    }

    /**
     * Accusé de réception du webhook Meta, corrélé par `wamid`.
     *
     * Ne touche PAS à `status` tant que la livraison n'échoue pas : l'envoi a
     * bien eu lieu, c'est la livraison qui progresse. Un échec de livraison,
     * lui, doit redevenir une fiche à traiter — sinon une fiche jamais reçue
     * resterait affichée « envoyée » dans le journal.
     */
    public function recordDeliveryUpdate(
        WhatsappSendLog $job,
        string $status,
        ?string $errorCode = null,
        ?string $errorTitle = null,
        bool $retryable = false,
    ): void {
        if ($status === WhatsappSendLog::DELIVERY_FAILED) {
            $job->update(['delivery_status' => WhatsappSendLog::DELIVERY_FAILED]);
            $this->markFailed(
                $job,
                'Livraison refusée par WhatsApp : '.($errorTitle ?? 'cause non précisée'),
                $retryable,
                $errorCode,
            );

            return;
        }

        // Meta ne garantit pas l'ordre d'arrivée des accusés de réception : un
        // « sent » retardataire peut suivre un « read ». Sans ce garde-fou,
        // une fiche lue redeviendrait « envoyée » dans le journal — un recul
        // que rien ne justifie.
        if (self::deliveryRank($status) < self::deliveryRank($job->delivery_status)) {
            return;
        }

        $updates = ['delivery_status' => $status];

        if ($status === WhatsappSendLog::DELIVERY_DELIVERED) {
            $updates['delivered_at'] = $job->delivered_at ?? now();
        }

        if ($status === WhatsappSendLog::DELIVERY_READ) {
            $updates['read_at'] = $job->read_at ?? now();
            $updates['delivered_at'] = $job->delivered_at ?? now();
        }

        $job->update($updates);
    }

    /**
     * Renvoi manuel depuis l'écran admin (bouton « Renvoyer »). Régénère la
     * fiche depuis les données à jour (l'hébergeur a pu corriger le nom du
     * voyageur depuis l'enfilage) et refuse de renvoyer sans identité.
     */
    public function resend(WhatsappSendLog $job): void
    {
        // Le renvoi manuel est le chemin par lequel l'arriéré repartirait le
        // plus facilement : un clic sur « Relancer tout » et plusieurs
        // centaines de fiches de séjours terminés partent vers des officiels.
        // Il est donc barré ici aussi, pas seulement dans la boucle d'envoi.
        if ($this->channel()->supportsPush() && app(WhatsappSendingGuard::class)->isPreCutover($job)) {
            $this->cancelPreCutover($job);

            return;
        }

        $updates = [
            'status' => WhatsappSendLog::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'error_code' => null,
            'delivery_status' => null,
            'delivered_at' => null,
            'read_at' => null,
            'message_id_whatsapp' => null,
            'claimed_at' => null,
            'sent_at' => null,
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ];

        if ($job->check_in_id && $job->guest_id) {
            $checkIn = CheckIn::with(['hotel.address', 'room', 'guests.documents'])->find($job->check_in_id);
            $guest = $checkIn?->guests->firstWhere('id', $job->guest_id);

            if ($checkIn && $guest) {
                if (trim((string) $guest->first_name.(string) $guest->last_name) === '') {
                    $job->update([
                        'status' => WhatsappSendLog::STATUS_CANCELLED,
                        'last_error' => 'Identité voyageur manquante (nom et prénom vides) — fiche bloquée avant envoi.',
                        'next_attempt_at' => null,
                    ]);

                    return;
                }
                $updates['caption'] = FicheFormatter::format($checkIn, $guest);
                // Les variables du modèle sont régénérées avec la légende, et
                // pour la même raison : c'est la fiche CORRIGÉE qui doit
                // repartir, pas celle figée à l'enfilage.
                // Le jeton NE CHANGE PAS au renvoi : un lien stable qui se
                // périmerait au premier renvoi ne serait pas un lien stable.
                $updates['template_params'] = FicheTemplate::params($checkIn, $guest, $job->publicToken());
                $updates['template_name'] = (string) config('whatsapp.cloud.template.name');
                $updates['template_language'] = (string) config('whatsapp.cloud.template.language');
                $updates['scan_id'] = $job->scan_id ?? $this->photoScanId($checkIn, $guest);
            }
        }

        $job->update($updates);
    }

    /**
     * Renvoi groupé (bouton « Relancer tout ») : remet en file tous les envois
     * échoués. Renvoie le nombre de fiches relancées.
     */
    public function resendAllFailed(): int
    {
        return $this->requeueStuck();
    }

    /**
     * Nombre de fiches que « Renvoyer tout » remettrait en tête de file.
     *
     * Deux familles, et la seconde manquait : les `failed` (retries épuisés),
     * mais AUSSI les `pending` dont la prochaine tentative est repoussée par le
     * backoff (jusqu'à 4 h). Après une panne réparée — session ré-appairée,
     * worker recyclé — ces fiches-là sont prêtes à partir, mais plus rien dans
     * l'admin ne pouvait les toucher : le bouton ne visait que `failed` et la
     * ligne « en attente » n'offre pas de « Renvoyer ». L'exploitant n'avait
     * plus qu'à attendre le backoff, sans le savoir.
     */
    public function stuckCount(): int
    {
        return $this->stuckQuery()->count();
    }

    /**
     * Remet en tête de file tout ce qui est bloqué : `failed` relancées, et
     * `pending` en attente de backoff dont l'échéance est ramenée à maintenant.
     *
     * @return int nombre de fiches débloquées
     */
    public function requeueStuck(): int
    {
        $jobs = $this->stuckQuery()->get();
        foreach ($jobs as $job) {
            $this->resend($job);
        }

        return $jobs->count();
    }

    private function stuckQuery(): Builder
    {
        return WhatsappSendLog::query()->where(function ($q) {
            $q->where('status', WhatsappSendLog::STATUS_FAILED)
                ->orWhere(fn ($p) => $p
                    ->where('status', WhatsappSendLog::STATUS_PENDING)
                    ->whereNotNull('next_attempt_at')
                    ->where('next_attempt_at', '>', now()));
        });
    }

    /** Backoff exponentiel : 1 min, 5 min, 15 min, 1 h, puis toutes les 4 h. */
    private function nextAttemptAt(int $attempts): Carbon
    {
        $schedule = config('whatsapp.retry_schedule_minutes', [1, 5, 15, 60, 240]);
        $index = min(max($attempts - 1, 0), count($schedule) - 1);

        return now()->addMinutes((int) $schedule[$index]);
    }

    /** Scan (photo) le plus récent de ce voyageur pour ce check-in. */
    /**
     * Photo (scan) à joindre à la fiche de ce voyageur.
     *
     * Le scan est rattaché au check-in mais PAS toujours au voyageur : le
     * document est scanné avant la création du voyageur, donc guest_id est
     * souvent null sur document_scans. On procède donc ainsi :
     *  1. scan explicitement lié à ce voyageur (guest_id renseigné) ;
     *  2. sinon, si le check-in n'a qu'UN voyageur, le scan du check-in lui
     *     appartient sans ambiguïté → on le joint ;
     *  3. en multi-voyageurs sans lien scan→voyageur, on ne joint pas de photo
     *     (mieux vaut pas de photo qu'un mauvais document sur une fiche police).
     */
    private function photoScanId(CheckIn $checkIn, Guest $guest): ?string
    {
        // Résolution partagée avec l'export PDF (DocumentScan::forFiche) : les
        // deux canaux doivent joindre exactement la même pièce.
        return DocumentScan::forFiche($checkIn, $guest)?->id;
    }
}
