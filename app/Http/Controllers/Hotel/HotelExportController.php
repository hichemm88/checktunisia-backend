<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Jobs\ExportPoliceFichesJob;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Exports côté manager établissement (fiches de police en PDF par email). */
class HotelExportController extends Controller
{
    /**
     * POST /hotel/exports/police-fiches
     * Génère le PDF des fiches de police sur une plage de dates et l'envoie par
     * email au manager (asynchrone). Réponse immédiate 202.
     */
    public function policeFiches(Request $request): JsonResponse
    {
        $v = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to'   => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        // Garde-fou : plage bornée (évite un PDF géant / abus).
        if (\Illuminate\Support\Carbon::parse($v['date_from'])->diffInDays($v['date_to']) > 366) {
            return response()->json([
                'errors' => [['code' => 'RANGE_TOO_WIDE', 'message' => 'La plage ne peut pas dépasser un an.', 'field' => 'date_to']],
            ], 422);
        }

        $hotel = app('tenant');
        $email = $request->user()->email;

        ExportPoliceFichesJob::dispatch($hotel->id, $v['date_from'], $v['date_to'], $email);

        AuditLogger::log('hotel.police_fiches_exported', $hotel, [], [
            'date_from' => $v['date_from'], 'date_to' => $v['date_to'], 'email' => $email,
        ], hotelId: $hotel->id);

        return response()->json(['data' => ['queued' => true, 'email' => $email]], 202);
    }
}
