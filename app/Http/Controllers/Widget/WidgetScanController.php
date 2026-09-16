<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\FicheSession;
use App\Services\CheckIn\CheckInService;
use App\Services\OCR\WidgetVisionScanService;
use App\Services\PartnerApi\PartnerIntegrationActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scan CIN/passeport embarqué — l'upload passe par le même pipeline que le
 * flux natif (CheckInService::uploadScan, image conservée), mais la LECTURE
 * elle-même est propre au widget : OcrService (natif) est un no-op délibéré
 * en production, la vraie lecture native se faisant côté client, hors de
 * portée d'un iframe tiers authentifié par jeton de session opaque (voir
 * WidgetVisionScanService).
 */
class WidgetScanController extends Controller
{
    public function __construct(private CheckInService $service) {}

    public function store(Request $request, WidgetVisionScanService $vision): JsonResponse
    {
        $session = app(FicheSession::class);
        $checkIn = $session->checkIn ?? CheckIn::findOrFail($session->check_in_id);

        $data = $request->validate([
            'passport_image' => ['required', 'file', 'mimes:jpeg,jpg,png,heic,pdf', 'max:10240'],
            'document_type' => ['nullable', 'in:cin,passport'],
        ]);

        $actor = app(PartnerIntegrationActor::class)->forOrganization($checkIn->hotel->organization);

        $scan = $this->service->uploadScan($checkIn, $actor, $request->file('passport_image'), runOcr: false);

        // Idempotence par hash (voir uploadScan) : une photo déjà lue (avec
        // succès ou non) reste 'pending' UNIQUEMENT si c'est un scan neuf —
        // une reprise sur exactement les mêmes octets ne redéclenche pas un
        // appel Claude payant.
        if ($scan->ocr_status === 'pending') {
            $result = $vision->extract($scan, $checkIn->hotel_id, $data['document_type'] ?? 'cin');
            $scan->update([
                'ocr_status' => $result['status'],
                'ocr_raw_result' => $result['extracted'] ?? null,
                'ocr_confidence' => $result['confidence'] ?? null,
                'ocr_error' => $result['error'] ?? null,
                'ocr_processed_at' => now(),
            ]);
        }

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

        $scan = DocumentScan::where('check_in_id', $session->check_in_id)->findOrFail($scanId);

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
