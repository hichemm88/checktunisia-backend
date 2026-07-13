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
            $checkIn->loadMissing(['hotel', 'room', 'guests.documents']);

            // Voyageur principal d'abord, puis les accompagnants.
            $guests = $checkIn->guests
                ->sortByDesc(fn ($g) => (bool) ($g->pivot->is_primary ?? false))
                ->values();

            $count = 0;
            foreach ($guests as $guest) {
                WhatsappSendLog::create([
                    'hotel_id' => $checkIn->hotel_id,
                    'check_in_id' => $checkIn->id,
                    'guest_id' => $guest->id,
                    'scan_id' => $this->photoScanId($checkIn, $guest),
                    'recipient' => $recipient,
                    'caption' => FicheFormatter::format($checkIn, $guest),
                    'status' => WhatsappSendLog::STATUS_PENDING,
                    'next_attempt_at' => now(),
                    'queued_at' => now(),
                ]);
                $count++;
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('[whatsapp] enqueue failed for check-in '.$checkIn->id.': '.$e->getMessage());

            return 0;
        }
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

    /** Renvoi manuel depuis l'écran admin (bouton « Renvoyer »). */
    public function resend(WhatsappSendLog $job): void
    {
        $job->update([
            'status' => WhatsappSendLog::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'claimed_at' => null,
            'sent_at' => null,
            'next_attempt_at' => now(),
            'queued_at' => now(),
        ]);
    }

    /** Backoff exponentiel : 1 min, 5 min, 15 min, 1 h, puis toutes les 4 h. */
    private function nextAttemptAt(int $attempts): Carbon
    {
        $schedule = config('whatsapp.retry_schedule_minutes', [1, 5, 15, 60, 240]);
        $index = min(max($attempts - 1, 0), count($schedule) - 1);

        return now()->addMinutes((int) $schedule[$index]);
    }

    /** Scan (photo) le plus récent de ce voyageur pour ce check-in. */
    private function photoScanId(CheckIn $checkIn, Guest $guest): ?string
    {
        return DocumentScan::query()
            ->where('check_in_id', $checkIn->id)
            ->where('guest_id', $guest->id)
            ->latest('created_at')
            ->value('id');
    }
}
