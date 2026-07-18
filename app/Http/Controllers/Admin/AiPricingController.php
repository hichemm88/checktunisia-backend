<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPricing;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lecture et edition des tarifs IA.
 *
 * Guards : memes que les metriques MRR (role platform_admin, applique au niveau
 * du groupe de routes admin). Il n'existe pas de role admin plus eleve sur la
 * plateforme ; l'edition est donc reservee au meme role, et tracee via
 * AuditLogger (comme l'edition des plans).
 */
class AiPricingController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = AiPricing::orderBy('model')->get()->map(fn (AiPricing $p) => $this->present($p));

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pricing = AiPricing::findOrFail($id);

        $validated = $request->validate([
            'input_price_per_mtok_usd' => ['required', 'numeric', 'min:0'],
            'output_price_per_mtok_usd' => ['required', 'numeric', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $old = $pricing->only(['input_price_per_mtok_usd', 'output_price_per_mtok_usd', 'active']);

        $pricing->fill($validated);
        $pricing->updated_at = now();
        $pricing->save();

        AuditLogger::log(
            'ai_pricing.updated',
            $pricing,
            $old,
            $pricing->only(['input_price_per_mtok_usd', 'output_price_per_mtok_usd', 'active']),
        );

        return response()->json(['data' => $this->present($pricing->fresh())]);
    }

    private function present(AiPricing $p): array
    {
        return [
            'id' => (string) $p->id,
            'model' => $p->model,
            'input_price_per_mtok_usd' => number_format((float) $p->input_price_per_mtok_usd, 4, '.', ''),
            'output_price_per_mtok_usd' => number_format((float) $p->output_price_per_mtok_usd, 4, '.', ''),
            'active' => (bool) $p->active,
            'updated_at' => optional($p->updated_at)->toIso8601String(),
        ];
    }
}
