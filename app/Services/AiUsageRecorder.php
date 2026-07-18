<?php

namespace App\Services;

use App\Models\AiPricing;
use App\Models\AiUsageEvent;
use Illuminate\Support\Carbon;

/**
 * Calcule le cout et insere un evenement de tracking IA.
 *
 * Le cout est FIGE a l'insertion, avec le tarif actif du modele au moment present
 * (snapshot). Un changement de tarif ulterieur ne reecrit jamais l'historique.
 *
 *   cost_usd = (input_tokens  / 1 000 000) * input_price_per_mtok_usd
 *            + (output_tokens / 1 000 000) * output_price_per_mtok_usd
 *
 * Les prix ne sont jamais hardcodes ; ils viennent de la table ai_pricing. Si
 * aucun tarif actif n'existe pour le modele (ou prix a 0), le cout vaut 0.
 */
class AiUsageRecorder
{
    /**
     * @param  array{feature:string,hotel_id:string,user_id:?string,model:string,
     *               input_tokens:int,output_tokens:int,status:string,latency_ms:int,
     *               occurred_at?:?string}  $data
     */
    public function record(array $data): AiUsageEvent
    {
        $inputTokens = max(0, (int) ($data['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($data['output_tokens'] ?? 0));

        $pricing = AiPricing::activeFor($data['model']);
        $cost = 0.0;
        if ($pricing) {
            $cost = ($inputTokens / 1_000_000) * (float) $pricing->input_price_per_mtok_usd
                  + ($outputTokens / 1_000_000) * (float) $pricing->output_price_per_mtok_usd;
        }

        return AiUsageEvent::create([
            'hotel_id' => $data['hotel_id'],
            'user_id' => $data['user_id'] ?? null,
            'feature' => $data['feature'],
            'model' => $data['model'],
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($cost, 6),
            'status' => $data['status'],
            'latency_ms' => max(0, (int) ($data['latency_ms'] ?? 0)),
            'created_at' => isset($data['occurred_at']) && $data['occurred_at']
                ? Carbon::parse($data['occurred_at'])
                : now(),
        ]);
    }
}
