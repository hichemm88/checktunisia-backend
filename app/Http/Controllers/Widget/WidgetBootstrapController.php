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

        ['session' => $session, 'widget_token' => $widgetToken] = $this->sessions->bootstrap($jwt);
        $session->loadMissing(['hotel', 'checkIn.guests.documents']);

        $existingGuests = $session->mode === FicheSession::MODE_AMEND
            ? $session->checkIn?->guests->map(fn ($g) => [
                'id' => $g->id,
                'first_name' => $g->first_name,
                'last_name' => $g->last_name,
                'nationality_code' => $g->nationality_code,
                'is_primary' => (bool) $g->pivot?->is_primary,
            ])->values()
            : [];

        return response()->json([
            'session_id' => $session->id,
            'widget_token' => $widgetToken,
            'mode' => $session->mode,
            'establishment_name' => $session->hotel->name,
            'booking_ref' => $session->booking_reference,
            'arrival_date' => $session->arrival_date,
            'departure_date' => $session->departure_date,
            'room' => $session->room_label,
            'prefill_guests' => $session->prefill_guests,
            'existing_guests' => $existingGuests,
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
