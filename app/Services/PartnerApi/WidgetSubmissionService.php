<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\FicheSession;
use App\Models\Guest;
use App\Services\CheckIn\CheckInService;
use Illuminate\Support\Facades\DB;

/**
 * Ajout/retrait de voyageurs et soumission finale du widget (§3) — RÉUTILISE
 * intégralement CheckInService : même dédup document, même invariant "un
 * seul principal", même décompte de quota, même relais WhatsApp qu'une fiche
 * native. Aucune logique dupliquée.
 */
class WidgetSubmissionService
{
    public function __construct(
        private CheckInService $checkInService,
        private WidgetSessionService $sessions,
        private PartnerIntegrationActor $actor,
        private PartnerWebhookOutboxService $webhooks,
    ) {}

    public function addGuest(FicheSession $session, array $data): Guest
    {
        $checkIn = $this->sessions->checkInFor($session);
        $actor = $this->actorFor($session);

        return $this->checkInService->addGuest($checkIn, $actor, $data);
    }

    public function removeGuest(FicheSession $session, string $guestId): void
    {
        $checkIn = $this->sessions->checkInFor($session);
        $guest = $checkIn->guests()->where('guests.id', $guestId)->firstOrFail();

        $this->checkInService->removeGuest($checkIn, $guest);
    }

    /** @return array{fiche_id:string, guest_count:int} */
    public function submit(FicheSession $session): array
    {
        $checkIn = $this->sessions->checkInFor($session);
        $actor = $this->actorFor($session);

        try {
            $result = DB::transaction(function () use ($session, $checkIn, $actor) {
                if ($session->mode === FicheSession::MODE_CREATE) {
                    // complete() exige >= 1 voyageur : le widget doit en avoir
                    // ajouté via addGuest() avant d'appeler submit.
                    $checkIn = $this->checkInService->complete($checkIn, $actor);
                } else {
                    // Mode amend : le check-in est déjà actif/complété. Les
                    // voyageurs ajoutés via addGuest() ont déjà chacun déclenché
                    // leur propre relais WhatsApp individuel — pas de complete()
                    // à rejouer, pas de second décompte de quota.
                    $checkIn = $checkIn->fresh()->load('guests');
                }

                $session->update([
                    'status' => FicheSession::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                ]);

                return ['fiche_id' => $checkIn->id, 'guest_count' => $checkIn->guests->count()];
            });
        } catch (\DomainException $e) {
            $this->webhooks->enqueueForSession($session, 'fiche.failed', [
                'session_id' => $session->id,
                'booking_ref' => $session->booking_reference,
                'error_code' => 'submission_failed',
            ]);

            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, $e->getMessage());
        }

        if (! $session->is_test) {
            $this->webhooks->enqueueForSession($session, 'fiche.submitted', [
                'fiche_id' => $result['fiche_id'],
                'session_id' => $session->id,
                'booking_ref' => $session->booking_reference,
                'establishment_id' => $session->hotel_id,
                'guest_count' => $result['guest_count'],
                'submitted_at' => $session->submitted_at->toIso8601String(),
                'metadata' => $session->metadata,
            ]);
        }

        return $result;
    }

    private function actorFor(FicheSession $session): \App\Models\User
    {
        $hotel = $session->hotel;
        $actor = $this->actor->forOrganization($hotel->organization);
        $this->actor->ensureAttachedTo($actor, $hotel);

        return $actor;
    }
}
