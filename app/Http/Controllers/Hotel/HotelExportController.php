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

        /*
         * Volume refusé ICI, pendant que le manager est devant son écran.
         *
         * Le plafond de 366 jours au-dessus borne la DURÉE, pas la quantité :
         * un mois dans un établissement bien rempli produit déjà plusieurs
         * centaines de fiches. Au-delà d'environ 150, la génération du PDF
         * épuise la mémoire du worker et meurt sur une erreur fatale de PHP —
         * que rien ne rattrape, et dont le manager n'entend jamais parler : il
         * a reçu un « 202, envoi en cours » et attend un email qui n'arrivera
         * pas.
         *
         * Refuser tout de suite, en disant combien de fiches la plage contient,
         * transforme cette panne muette en une consigne applicable : réduire la
         * plage. Voir config/fiche.php pour les mesures qui fixent le plafond.
         */
        $count = ExportPoliceFichesJob::ficheCount($hotel->id, $v['date_from'], $v['date_to']);
        $max = (int) config('fiche.export_max_fiches', 120);

        if ($count > $max) {
            return response()->json([
                'errors' => [[
                    'code' => 'TOO_MANY_FICHES',
                    'message' => "Cette plage contient {$count} fiches, au-delà de la limite de {$max} par export. Choisissez une plage plus courte.",
                    'field' => 'date_to',
                ]],
            ], 422);
        }

        ExportPoliceFichesJob::dispatch($hotel->id, $v['date_from'], $v['date_to'], $email);

        AuditLogger::log('hotel.police_fiches_exported', $hotel, [], [
            'date_from' => $v['date_from'], 'date_to' => $v['date_to'], 'email' => $email,
        ], hotelId: $hotel->id);

        return response()->json(['data' => ['queued' => true, 'email' => $email]], 202);
    }
}
