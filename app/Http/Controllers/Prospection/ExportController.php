<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\Establishment;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV (§ Écran 8) : mêmes colonnes que l'import, plus statut et
 * dernière action — exportable à tout moment, base complète.
 */
class ExportController extends Controller
{
    public function export(): StreamedResponse
    {
        $columns = [
            'Nom', 'Numéro WhatsApp', 'Adresse / Repère', 'Taille estimée', 'Segment',
            'Décideur / Contact', 'Canal pour trouver le numéro', 'Notes de qualification',
            'Statut', 'Date relance', 'Objections / Retours', 'Dernière action',
        ];

        $callback = function () use ($columns) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 : Excel (Windows) sans lui interprète les accents en
            // Latin-1 et affiche "MaisonÂ dâ€™hÃ´tes" au lieu de "Maison d'hôtes".
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ';');

            Establishment::with(['actions' => fn ($q) => $q->latest('occurred_at')->limit(1)])
                ->orderBy('name')
                ->chunk(200, function ($chunk) use ($out) {
                    foreach ($chunk as $e) {
                        $lastAction = $e->actions->first();

                        fputcsv($out, [
                            $e->name,
                            $e->whatsapp_phone,
                            $e->address,
                            $e->size,
                            $e->segment,
                            $e->decision_maker_name,
                            $e->origin_channel,
                            $e->qualification_notes,
                            $e->status,
                            $e->next_action_at?->toDateString(),
                            '', // Objections / Retours : voir la timeline, pas résumables en une cellule.
                            $lastAction ? "{$lastAction->type} — {$lastAction->content}" : '',
                        ], ';');
                    }
                });

            fclose($out);
        };

        return response()->streamDownload($callback, 'prospection-qayed-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
