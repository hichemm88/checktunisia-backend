<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\EstablishmentLinkService;
use App\Services\Subscription\CheckinQuota;
use App\Services\Subscription\PlanEntitlements;
use Illuminate\Http\JsonResponse;

class PartnerEstablishmentController extends Controller
{
    public function __construct(private EstablishmentLinkService $links) {}

    public function index(): JsonResponse
    {
        $partner = app(ApiPartner::class);

        $links = $partner->establishmentLinks()->active()->with('hotel.organization')->get();

        $data = $links->map(function ($link) {
            $hotel = $link->hotel;
            $org = $hotel->organization;
            $summary = $org ? PlanEntitlements::summary($org)['checkins_per_month'] : ['limit' => null, 'used' => 0];

            return [
                'establishment_id' => $hotel->id,
                'name' => $hotel->name,
                'status' => $hotel->status,
                'quota' => [
                    'used' => $summary['used'],
                    'limit' => $summary['limit'],
                    'period' => 'monthly',
                ],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Chambres RÉELLES de l'établissement (§ suite : correspondance des
     * chambres). Le partenaire construit sa propre table de correspondance
     * (ses libellés OTA → ces chambres) et envoie directement `room_id` dans
     * POST /v1/fiche-sessions — jamais de nom à deviner côté Qayed.
     */
    public function rooms(string $hotelId): JsonResponse
    {
        $partner = app(ApiPartner::class);
        $this->links->assertLinked($partner, $hotelId);

        $hotel = Hotel::find($hotelId);

        if (! $hotel) {
            throw new PartnerApiException(ErrorCodes::ESTABLISHMENT_NOT_LINKED);
        }

        $rooms = Room::where('hotel_id', $hotel->id)
            ->orderBy('number')
            ->get(['id', 'number', 'floor', 'type', 'capacity', 'status']);

        return response()->json(['data' => $rooms]);
    }
}
