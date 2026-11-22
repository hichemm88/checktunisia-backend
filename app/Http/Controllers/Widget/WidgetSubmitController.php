<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\FicheSession;
use App\Services\PartnerApi\WidgetSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetSubmitController extends Controller
{
    public function __construct(private WidgetSubmissionService $submissions) {}

    public function store(Request $request): JsonResponse
    {
        $session = app(FicheSession::class);

        $stay = $request->validate([
            'arrival_date' => ['nullable', 'date'],
            'departure_date' => ['nullable', 'date', 'after_or_equal:arrival_date'],
        ]);

        $result = $this->submissions->submit($session, array_filter($stay));

        return response()->json([
            'session_id' => $session->id,
            'fiche_id' => $result['fiche_id'],
            'guest_count' => $result['guest_count'],
        ]);
    }
}
