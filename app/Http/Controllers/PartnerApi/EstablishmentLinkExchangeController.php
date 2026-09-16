<?php

namespace App\Http\Controllers\PartnerApi;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Services\PartnerApi\EstablishmentLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstablishmentLinkExchangeController extends Controller
{
    public function __construct(private EstablishmentLinkService $links) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'link_code' => ['required', 'string', 'max:20'],
        ]);

        $partner = app(ApiPartner::class);

        $link = $this->links->exchange($partner, $validated['link_code']);
        $link->loadMissing('hotel');

        return response()->json([
            'establishment_id' => $link->hotel_id,
            'establishment_name' => $link->hotel->name,
            'linked_at' => $link->linked_at->toIso8601String(),
        ], 201);
    }
}
