<?php

namespace App\Services\Whatsapp;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\WhatsappSendLog;
use App\Models\WhatsappSessionState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function __construct(private WhatsappAlertService $alerts) {}

    public function enabled(): bool
    {
        return (bool) config('whatsapp.enabled') && (bool) config('whatsapp.recipient');
    }

    /**
     * Enfile un message par voyageur du check-in. JAMAIS bloquant : toute erreur
     * est avalée et journalisée — un échec WhatsApp ne doit jamais gêner le check-in.
     *
     * @return int nombre de jobs enfilés
     */
    public function enqueueForCheckIn(CheckIn $checkIn): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        try {
            $recipient = (string) config('whatsapp.recipient');
            $checkIn->loadMissing(['hotel.organization', 'room', 'guests.documents']);

            // Le relais peut être coupé par pack ou par client (Admin > Abonnements).
            $org = $checkIn->hotel?->organization;
            if ($org && ! \App\Services\Subscription\PlanEntitlements::allows($org, 'whatsapp_relay')) {
                Log::info('[whatsapp] relais désactivé par le pack pour org '.$org->id.' — check-in '.$checkIn->id.' non enfilé.');

                return 0;
            }

            // Voyageur principal d'abord, puis les accompagnants.
            $guests = $checkIn->guests
                ->sortByDesc(fn ($g) => (bool) ($g->pivot->is_primary ?? false))
                ->values();

            $count = 0;
            foreach ($guests as $guest) {
                if ($this->createJob($checkIn, $guest, $recipient)) {
                    $count++;
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
        if (! $this->enabled()) {
            return false;
        }

        try {
            // Rechargement explicite (et non loadMissing) : l'appelant vient
            // d'ajouter le voyageur, une relation déjà chargée serait périmée et
            // fausserait le choix de la photo (photoScanId compte les voyageurs).
            $checkIn->load(['hotel.organization', 'room', 'guests.documents']);

            $org = $checkIn->hotel?->organization;
            if ($org && ! \App\Services\Subscription\PlanEntitlements::allows($org, 'whatsapp_relay')) {
                Log::info('[whatsapp] relais désactivé par le pack pour org '.$org->id.' — voyageur '.$guest->id.' non enfilé.');

                return false;
            }

            // Garde-fou anti-doublon : ne jamais renvoyer une fiche déjà journalisée.
            $already = WhatsappSendLog::where('check_in_id', $checkIn->id)
                ->where('guest_id', $guest->id)
                ->exists();

            if ($already) {
                return false;
            }

            return $this->createJob($checkIn, $guest, (string) config('whatsapp.recipient'));
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
            'caption' => FicheFormatter::format($checkIn, $guest),
            'status' => $hasIdentity ? WhatsappSendLog::STATUS_PENDING : WhatsappSendLog::STATUS_CANCELLED,
            'last_error' => $hasIdentity ? null : 'Identité voyageur manquante (nom et prénom vides) — fiche bloquée avant envoi.',
            'next_attempt_at' => $hasIdentity ? now() : null,
            'queued_at' => now(),
        ]);

        return $hasIdentity;
    }

    /** Enfile une fiche factice [TEST] pour le bouton « message test » admin. */
    public function enqueueTest(?string $propertyName = null): ?WhatsappSendLog
    {
        if (! $this->enabled()) {
            return null;
        }

        return WhatsappSendLog::create([
            'hotel_id' => null,
            'recipient' => (string) config('whatsapp.recipient'),
            'caption' => FicheFormatter::testFiche($propertyName),
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
        if (! $this->enabled() || ! WhatsappSessionState::current()->canDispatch()) {
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

            if (! $job) {
                return null;
            }

            $job->update([
                'claimed_at' => now(),
                'attempts' => $job->attempts + 1,
            ]);

            return $job->fresh();
        });
    }

    /** Le worker confirme l'envoi. */
    public function markSent(WhatsappSendLog $job, ?string $messageId): void
    {
        $job->update([
            'status' => WhatsappSendLog::STATUS_SENT,
            'sent_at' => now(),
            'message_id_whatsapp' => $messageId,
            'claimed_at' => null,
            'last_error' => null,
        ]);
    }

    /**
     * Le worker signale un échec. Applique le backoff, ou abandonne
     * définitivement (+ alerte admin) au-delà de 24 h.
     */
    public function markFailed(WhatsappSendLog $job, ?string $error): void
    {
        $ageMinutes = Carbon::parse($job->queued_at)->diffInMinutes(now());
        $maxAge = (int) config('whatsapp.max_age_minutes', 1440);

        if ($ageMinutes >= $maxAge) {
            $job->update([
                'status' => WhatsappSendLog::STATUS_FAILED,
                'last_error' => $error,
                'claimed_at' => null,
            ]);
            $this->alerts->jobPermanentlyFailed($job, $error);

            return;
        }

        $job->update([
            'status' => WhatsappSendLog::STATUS_PENDING,
            'last_error' => $error,
            'claimed_at' => null,
            'next_attempt_at' => $this->nextAttemptAt($job->attempts),
        ]);
    }

    /**
     * Renvoi manuel depuis l'écran admin (bouton « Renvoyer »). Régénère la
     * fiche depuis les données à jour (l'hébergeur a pu corriger le nom du
     * voyageur depuis l'enfilage) et refuse de renvoyer sans identité.
     */
    public function resend(WhatsappSendLog $job): void
    {
        $updates = [
            'status' => WhatsappSendLog::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'claimed_at' => null,
            'sent_at' => null,
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ];

        if ($job->check_in_id && $job->guest_id) {
            $checkIn = CheckIn::with(['hotel', 'room', 'guests.documents'])->find($job->check_in_id);
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
        $jobs = WhatsappSendLog::where('status', WhatsappSendLog::STATUS_FAILED)->get();
        foreach ($jobs as $job) {
            $this->resend($job);
        }

        return $jobs->count();
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
        $forGuest = DocumentScan::query()
            ->where('check_in_id', $checkIn->id)
            ->where('guest_id', $guest->id)
            ->latest('created_at')
            ->value('id');

        if ($forGuest) {
            return $forGuest;
        }

        if ($checkIn->guests->count() <= 1) {
            return DocumentScan::query()
                ->where('check_in_id', $checkIn->id)
                ->latest('created_at')
                ->value('id');
        }

        return null;
    }
}
