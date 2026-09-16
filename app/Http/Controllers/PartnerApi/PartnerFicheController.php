<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\WhatsappSendLog;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\EstablishmentLinkService;
use Illuminate\Http\JsonResponse;

class PartnerFicheController extends Controller
{
    public function __construct(private EstablishmentLinkService $links) {}

    public function show(string $ficheId): JsonResponse
    {
        $partner = app(ApiPartner::class);

        $checkIn = CheckIn::with('guests')->find($ficheId);

        if (! $checkIn || ($checkIn->metadata['partner_id'] ?? null) !== $partner->id) {
            throw new PartnerApiException(ErrorCodes::FICHE_NOT_FOUND);
        }

        // Établissement toujours lié : une révocation ne doit pas laisser une
        // fiche déjà soumise consultable indéfiniment par un partenaire coupé.
        $this->links->assertLinked($partner, $checkIn->hotel_id);

        return response()->json([
            'fiche_id' => $checkIn->id,
            'booking_ref' => $checkIn->booking_reference,
            'establishment_id' => $checkIn->hotel_id,
            'guest_count' => $checkIn->guests->count(),
            'submitted_at' => $checkIn->completed_at?->toIso8601String(),
            'authority_status' => $this->authorityStatusFor($checkIn),
            'reference_number' => $checkIn->reference,
        ]);
    }

    /**
     * Statut de transmission DÉRIVÉ du relais WhatsApp provisoire — PAS un
     * accusé de réception gouvernemental (aucune intégration MI n'existe
     * aujourd'hui). Voir API-V1-DECISIONS.md. Isolé ici pour être remplaçable
     * sans casser le contrat une fois l'homologation MI faite.
     */
    private function authorityStatusFor(CheckIn $checkIn): string
    {
        $logs = WhatsappSendLog::where('check_in_id', $checkIn->id)->get();

        if ($logs->isEmpty()) {
            return 'pending';
        }

        $delivered = $logs->contains(fn (WhatsappSendLog $l) => in_array($l->delivery_status, [
            WhatsappSendLog::DELIVERY_DELIVERED, WhatsappSendLog::DELIVERY_READ,
        ], true) || $l->status === WhatsappSendLog::STATUS_SENT);

        if ($delivered) {
            return 'relayed';
        }

        $allDead = $logs->every(fn (WhatsappSendLog $l) => in_array($l->status, [
            WhatsappSendLog::STATUS_FAILED, WhatsappSendLog::STATUS_CANCELLED,
        ], true));

        return $allDead ? 'unavailable' : 'pending';
    }
}
