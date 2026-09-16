<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\FicheSession;
use App\Services\PartnerApi\WidgetSubmissionService;
use Illuminate\Http\JsonResponse;

class WidgetSubmitController extends Controller
{
    public function __construct(private WidgetSubmissionService $submissions) {}

    public function store(): JsonResponse
    {
        $session = app(FicheSession::class);

        $result = $this->submissions->submit($session);

        return response()->json([
            'session_id' => $session->id,
            'fiche_id' => $result['fiche_id'],
            'guest_count' => $result['guest_count'],
        ]);
    }
}
