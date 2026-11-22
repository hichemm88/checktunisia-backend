<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\WhatsappSendLog;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\EstablishmentLinkService;
use App\Services\PartnerApi\PartnerStayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerFicheController extends Controller
{
    public function __construct(private EstablishmentLinkService $links) {}

    public function show(string $ficheId): JsonResponse
    {
        return response()->json($this->payload($this->findForPartner($ficheId)));
    }

    /**
     * v1.1 — prolongation, départ anticipé, changement de chambre, sans
     * widget (voir PartnerStayService::update).
     */
    public function update(Request $request, string $ficheId, PartnerStayService $stays): JsonResponse
    {
        $data = $request->validate([
            'departure_date' => ['sometimes', 'date'],
            'room_id' => ['sometimes', 'uuid'],
            'room' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($data === []) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'Indiquez au moins departure_date, room_id ou room.');
        }

        if (isset($data['departure_date'])) {
            $data['departure_date'] = substr((string) $data['departure_date'], 0, 10);
        }

        $checkIn = $stays->update($this->findForPartner($ficheId), $data);

        return response()->json($this->payload($checkIn->load('guests')));
    }

    private function findForPartner(string $ficheId): CheckIn
    {
        $partner = app(ApiPartner::class);

        $checkIn = CheckIn::with('guests')->find($ficheId);

        if (! $checkIn || ($checkIn->metadata['partner_id'] ?? null) !== $partner->id) {
            throw new PartnerApiException(ErrorCodes::FICHE_NOT_FOUND);
        }

        // Établissement toujours lié : une révocation ne doit pas laisser une
        // fiche déjà soumise consultable indéfiniment par un partenaire coupé.
        $this->links->assertLinked($partner, $checkIn->hotel_id);

        return $checkIn;
    }

    private function payload(CheckIn $checkIn): array
    {
        return [
            'fiche_id' => $checkIn->id,
            'booking_ref' => $checkIn->booking_reference,
            'establishment_id' => $checkIn->hotel_id,
            'guest_count' => $checkIn->guests->count(),
            'submitted_at' => $checkIn->completed_at?->toIso8601String(),
            'authority_status' => $this->authorityStatusFor($checkIn),
            'reference_number' => $checkIn->reference,
            // v1.1 — séjour tel que Qayed le voit (PATCH le met à jour).
            'arrival_date' => $checkIn->check_in_date?->toDateString(),
            'departure_date' => $checkIn->expected_check_out_date?->toDateString(),
            'checked_out_at' => $checkIn->actual_check_out_date?->toDateString(),
            'room_id' => $checkIn->room_id,
            'stay_status' => $checkIn->status,
        ];
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
