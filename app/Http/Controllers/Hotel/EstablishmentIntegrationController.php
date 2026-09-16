<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\EstablishmentPartnerLink;
use App\Models\Hotel;
use App\Services\Audit\AuditLogger;
use App\Services\PartnerApi\EstablishmentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Section « Intégrations » du dashboard établissement — owner uniquement (org.owner). */
class EstablishmentIntegrationController extends Controller
{
    public function __construct(private EstablishmentLinkService $links) {}

    public function index(Request $request): JsonResponse
    {
        $hotelIds = $request->user()->organization->properties()->pluck('id');

        $links = EstablishmentPartnerLink::whereIn('hotel_id', $hotelIds)
            ->with(['hotel', 'partner'])
            ->orderByDesc('linked_at')
            ->get();

        return response()->json(['data' => $links->map(fn (EstablishmentPartnerLink $l) => [
            'id' => $l->id,
            'hotel_id' => $l->hotel_id,
            'hotel_name' => $l->hotel->name,
            'partner_name' => $l->partner->name,
            'linked_at' => $l->linked_at,
            'revoked_at' => $l->revoked_at,
            'active' => $l->isActive(),
        ])]);
    }

    public function storeLinkCode(Request $request): JsonResponse
    {
        $v = $request->validate([
            'hotel_id' => ['required', 'uuid', Rule::exists('hotels', 'id')->where('organization_id', $request->user()->organization_id)],
        ]);

        $hotel = Hotel::findOrFail($v['hotel_id']);
        ['code' => $code, 'plaintext' => $plaintext] = $this->links->generate($hotel, $request->user());

        AuditLogger::log('establishment_link_code.generated', $code, [], ['hotel_id' => $hotel->id], hotelId: $hotel->id);

        return response()->json(['data' => [
            'code' => $plaintext,
            'expires_at' => $code->expires_at,
        ]], 201);
    }

    public function destroy(Request $request, string $linkId): JsonResponse
    {
        $hotelIds = $request->user()->organization->properties()->pluck('id');

        $link = EstablishmentPartnerLink::whereIn('hotel_id', $hotelIds)->findOrFail($linkId);
        $this->links->revoke($link, $request->user());

        AuditLogger::log('establishment_link.revoked', $link, [], ['hotel_id' => $link->hotel_id], hotelId: $link->hotel_id);

        return response()->json(null, 204);
    }
}
