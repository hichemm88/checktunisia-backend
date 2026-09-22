<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use App\Services\Prospection\PipelineStatus;
use Illuminate\Http\JsonResponse;

/**
 * Tableau de bord (§ Écran 6) : entonnoir par statut, répartition par zone et
 * priorité, taux de réponse, objections les plus fréquentes. Lecture seule,
 * calculée à la volée — le volume visé (~25 puis quelques centaines
 * d'établissements) ne justifie pas une table de stats pré-agrégée.
 */
class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $active = Establishment::where('archived', false);

        $byStatus = (clone $active)->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $byZone = (clone $active)->selectRaw('zone, count(*) as count')->groupBy('zone')->pluck('count', 'zone');
        $byPriority = (clone $active)->selectRaw('priority, count(*) as count')->groupBy('priority')->pluck('count', 'priority');

        return response()->json([
            'data' => [
                'total' => (clone $active)->count(),
                'funnel' => collect(PipelineStatus::ALL)->map(fn ($status) => [
                    'status' => $status,
                    'count' => (int) ($byStatus[$status] ?? 0),
                ])->values(),
                'by_zone' => $byZone->map(fn ($count, $zone) => ['zone' => $zone, 'count' => (int) $count])->values(),
                'by_priority' => $byPriority->map(fn ($count, $priority) => ['priority' => $priority, 'count' => (int) $count])->values(),
                'response_rate' => $this->responseRate(),
                'top_objections' => $this->topObjections(),
            ],
        ]);
    }

    /**
     * Établissements ayant reçu au moins un message envoyé vs. ceux ayant au
     * moins une réponse reçue — par établissement, pas par message, pour ne
     * pas gonfler artificiellement le taux avec plusieurs relances sans
     * réponse sur un même prospect.
     */
    private function responseRate(): array
    {
        $contacted = ProspectionAction::where('type', 'message_envoye')->distinct('establishment_id')->count('establishment_id');
        $responded = ProspectionAction::where('type', 'reponse_recue')->distinct('establishment_id')->count('establishment_id');

        return [
            'contacted' => $contacted,
            'responded' => $responded,
            'rate' => $contacted > 0 ? round($responded / $contacted, 4) : 0,
        ];
    }

    /**
     * `actions.objections` est un tableau JSON (voir ProspectionAction) —
     * agrégé en PHP plutôt qu'en SQL brut sur jsonb : le volume reste petit
     * et ça évite une requête dépendante du moteur (Postgres uniquement).
     */
    private function topObjections(): array
    {
        $counts = [];

        ProspectionAction::whereNotNull('objections')->pluck('objections')->each(function (?array $objections) use (&$counts) {
            foreach ($objections ?? [] as $label) {
                $counts[$label] = ($counts[$label] ?? 0) + 1;
            }
        });

        arsort($counts);

        return collect($counts)
            ->take(10)
            ->map(fn ($count, $label) => ['label' => $label, 'count' => $count])
            ->values()
            ->all();
    }
}
