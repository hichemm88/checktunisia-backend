<?php

namespace App\Http\Controllers\Widget;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Models\FicheSession;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\RequestOrigin;
use App\Services\PartnerApi\WidgetSessionService;
use App\Services\PartnerApi\WidgetTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WidgetBootstrapController extends Controller
{
    public function __construct(
        private WidgetSessionService $sessions,
        private WidgetTokenService $tokens,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $jwt = (string) $request->query('token', '');
        $claims = $this->tokens->decode($jwt);

        $partner = ApiPartner::findOrFail($claims->partner_id);
        $this->assertOriginAllowed($partner, $request);

        $boot = $this->sessions->bootstrap($jwt);
        $session = $boot['session'];
        $session->loadMissing(['hotel', 'checkIn.room', 'checkIn.guests']);

        // Voyageurs déjà sur la fiche, QUEL QUE SOIT le mode : en v1.1 un
        // brouillon repris (mode create) les ignorait et le widget redemandait
        // le voyageur 1 déjà saisi. Brouillon : retirables ; fiche transmise
        // (amend) : seuls ceux ajoutés par cette session le sont.
        $existingGuests = ($session->checkIn?->guests ?? collect())
            ->sortBy(fn ($g) => (string) $g->pivot?->added_at)
            ->map(fn ($g) => [
                'id' => $g->id,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'nationality_code' => $g->nationality_code,
                'is_primary' => (bool) $g->pivot?->is_primary,
                'removable' => $session->mode === FicheSession::MODE_CREATE
                    || ($g->pivot?->added_at && Carbon::parse($g->pivot->added_at)->gte($session->created_at)),
            ])->values();

        return response()->json([
            'session_id' => $session->id,
            'widget_token' => $boot['widget_token'],
            // v1.2 : fiche ouverte par une autre personne en ce moment.
            'locked_by' => $boot['widget_token'] === null ? ($boot['locked_by'] ?? '') : null,
            'mode' => $session->mode,
            'establishment_name' => $session->hotel->name,
            'booking_ref' => $session->booking_reference,
            'arrival_date' => ($session->checkIn?->check_in_date ?? $session->arrival_date)?->toDateString(),
            'departure_date' => ($session->checkIn?->expected_check_out_date ?? $session->departure_date)?->toDateString(),
            // Chambre Qayed réellement rattachée (room_id) en priorité, sinon
            // le libellé brut du partenaire.
            'room' => $session->checkIn?->room?->number ?? $session->room_label,
            'prefill_guests' => $session->prefill_guests,
            'existing_guests' => $boot['widget_token'] === null ? [] : $existingGuests,
            'expected_guest_count' => max($session->expectedGuestCount(), (int) $session->checkIn?->adults_count),
            'locale' => $session->locale ?? 'fr',
        ]);
    }

    private function assertOriginAllowed(ApiPartner $partner, Request $request): void
    {
        $origin = RequestOrigin::of($request);

        if ($origin !== null && ! $partner->allowsOrigin($origin)) {
            throw new PartnerApiException(ErrorCodes::FRAME_DISALLOWED);
        }
    }
}
