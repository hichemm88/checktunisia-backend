<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\AiUsageEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Telemetrie des scans OCR MRZ locaux (tesseract, cote navigateur).
 *
 * L'OCR MRZ local est gratuit et ne passe jamais par le serveur : sans ce
 * beacon, ses reussites ne sont mesurees nulle part et le graphe comparatif
 * "OCR MRZ local vs Claude vision" serait impossible.
 *
 * Metadata-only (regle INPDP) : etablissement (tenant) + operateur, feature
 * mrz_local, cout et tokens a 0. AUCUNE donnee voyageur. Best-effort : ne doit
 * jamais faire echouer le scan cote client.
 */
class ScanEventController extends Controller
{
    /** POST /hotel/scan-events/mrz-local */
    public function mrzLocal(Request $request): JsonResponse
    {
        $request->validate([
            'latency_ms' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $hotel = app('tenant');
            AiUsageEvent::create([
                'hotel_id' => $hotel->id,
                'user_id' => $request->user()?->id,
                'feature' => AiUsageEvent::LOCAL_MRZ,
                'model' => 'local_ocr',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost_usd' => 0,
                'status' => 'success',
                'latency_ms' => max(0, (int) $request->input('latency_ms', 0)),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('mrz_local_beacon_failed', ['error' => $e->getMessage()]);

            return response()->json(['data' => ['recorded' => false]], 202);
        }

        return response()->json(['data' => ['recorded' => true]], 201);
    }
}
