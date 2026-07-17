<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Services\CheckIn\CheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private CheckInService $service) {}

    /**
     * POST /hotel/check-ins/{id}/scans
     * Upload passport image → trigger OCR.
     */
    public function store(Request $request, string $checkInId): JsonResponse
    {
        $checkIn = CheckIn::where('id', $checkInId)
            ->where('hotel_id', app('tenant')->id)
            ->firstOrFail();

        $request->validate([
            'passport_image' => ['required', 'file', 'mimes:jpeg,jpg,png,heic,pdf', 'max:10240'],
        ]);

        // Quota de scans mensuel du pack (piloté dans Admin > Abonnements).
        if ($org = app('tenant')->organization) {
            \App\Services\Subscription\PlanEntitlements::assertWithinLimit($org, 'ocr_scans_per_month');
        }

        $scan = $this->service->uploadScan($checkIn, $request->user(), $request->file('passport_image'));

        return response()->json([
            'data' => [
                'scan_id'     => $scan->id,
                'status'      => $scan->ocr_status,
                'polling_url' => "/api/v1/hotel/scans/{$scan->id}/status",
            ],
        ], 202);
    }

    /**
     * GET /hotel/scans/{scan_id}/status
     * Poll OCR result.
     */
    public function status(string $scanId): JsonResponse
    {
        // Validate tenant owns this scan
        $scan = DocumentScan::whereHas('checkIn', fn($q) => $q->where('hotel_id', app('tenant')->id))
            ->findOrFail($scanId);

        $response = [
            'scan_id' => $scan->id,
            'status'  => $scan->ocr_status,
        ];

        if ($scan->isCompleted()) {
            $response['confidence'] = $scan->ocr_confidence;
            $response['extracted']  = $this->formatExtracted($scan->ocr_raw_result);
        }

        if ($scan->isFailed()) {
            $response['error'] = $scan->ocr_error ?? 'OCR processing failed. Please try again or enter manually.';
        }

        if ($scan->isProcessing()) {
            $response['progress'] = 60; // Could be more granular with job progress
        }

        return response()->json(['data' => $response]);
    }

    private function formatExtracted(?array $raw): ?array
    {
        if (!$raw) return null;

        return [
            'first_name'           => $raw['first_name'] ?? null,
            'last_name'            => $raw['last_name'] ?? null,
            'date_of_birth'        => $raw['date_of_birth'] ?? null,
            'sex'                  => $raw['sex'] ?? null,
            'nationality_code'     => $raw['nationality_code'] ?? null,
            'document_type'        => $raw['document_type'] ?? 'passport',
            'document_number'      => $raw['document_number'] ?? null,
            'issuing_country_code' => $raw['issuing_country_code'] ?? null,
            'expiry_date'          => $raw['expiry_date'] ?? null,
            'mrz_line1'            => $raw['mrz_line1'] ?? null,
            'mrz_line2'            => $raw['mrz_line2'] ?? null,
        ];
    }
}
