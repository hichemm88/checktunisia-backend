<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
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
     * v1.1 — utilisateurs actifs des hébergeurs propriétaires des
     * établissements liés, pour que le manager côté partenaire relie à la
     * main ses comptes aux comptes Qayed (operator.user_id). Ni e-mail ni
     * téléphone : rien qui permette de contacter ou d'identifier hors Qayed.
     * L'utilisateur technique d'intégration est exclu.
     */
    public function operators(): JsonResponse
    {
        $partner = app(ApiPartner::class);

        $orgIds = Hotel::whereIn('id', $partner->establishmentLinks()->active()->select('hotel_id'))
            ->pluck('organization_id')
            ->unique();

        $users = User::whereIn('organization_id', $orgIds)
            ->where('status', 'active')
            ->where('is_system_actor', false)
            ->with('roles')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => trim("{$u->first_name} {$u->last_name}"),
            'role' => $u->primary_role,
        ])->values()]);
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
