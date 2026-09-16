<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\FicheSession;
use App\Services\CheckIn\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scan CIN/passeport embarqué — même pipeline OCR que ScanController (flux
 * natif), juste authentifié par le token de session widget au lieu de
 * Sanctum + X-Property-Id.
 */
class WidgetScanController extends Controller
{
    public function __construct(private CheckInService $service) {}

    public function store(Request $request): JsonResponse
    {
        $session = app(FicheSession::class);
        $checkIn = $session->checkIn ?? \App\Models\CheckIn::findOrFail($session->check_in_id);

        $request->validate([
            'passport_image' => ['required', 'file', 'mimes:jpeg,jpg,png,heic,pdf', 'max:10240'],
        ]);

        $actor = app(\App\Services\PartnerApi\PartnerIntegrationActor::class)->forOrganization($checkIn->hotel->organization);

        $scan = $this->service->uploadScan($checkIn, $actor, $request->file('passport_image'));

        return response()->json([
            'data' => [
                'scan_id' => $scan->id,
                'status' => $scan->ocr_status,
            ],
        ], 202);
    }

    public function status(string $scanId): JsonResponse
    {
        $session = app(FicheSession::class);

        $scan = \App\Models\DocumentScan::where('check_in_id', $session->check_in_id)->findOrFail($scanId);

        $response = ['scan_id' => $scan->id, 'status' => $scan->ocr_status];

        if ($scan->isCompleted()) {
            $response['confidence'] = $scan->ocr_confidence;
            $response['extracted'] = $scan->ocr_raw_result;
        }

        if ($scan->isFailed()) {
            $response['error'] = $scan->ocr_error ?? 'OCR impossible sur cette image.';
        }

        return response()->json(['data' => $response]);
    }
}
