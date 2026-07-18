<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AiUsageEvent;
use App\Services\AiUsageRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /internal/ai-usage
 *
 * Appelee par la fonction serverless Vercel (scan CIN / repli passeport) apres
 * chaque appel a Anthropic. Authentifiee par un secret de service partage
 * (middleware ai.tracking.secret), PAS par une session utilisateur.
 *
 * Le corps ne contient QUE des metadonnees (aucune donnee voyageur). L'operateur
 * (user_id) est resolu ici, cote serveur, depuis le token porteur transmis dans
 * l'en-tete X-Actor-Token (jamais le voyageur). Le client web/mobile n'envoie
 * rien de plus.
 *
 * `establishment_id` (vocabulaire produit) est mappe sur la colonne `hotel_id`.
 */
class AiUsageIngestController extends Controller
{
    public function store(Request $request, AiUsageRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'feature' => ['required', Rule::in(AiUsageEvent::FEATURES)],
            'establishment_id' => ['required', 'uuid'],
            'model' => ['required', 'string', 'max:120'],
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::in(AiUsageEvent::STATUSES)],
            'latency_ms' => ['required', 'integer', 'min:0'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $recorder->record([
                'hotel_id' => $validated['establishment_id'],
                'user_id' => $this->resolveUserId($request->header('X-Actor-Token')),
                'feature' => $validated['feature'],
                'model' => $validated['model'],
                'input_tokens' => $validated['input_tokens'],
                'output_tokens' => $validated['output_tokens'],
                'status' => $validated['status'],
                'latency_ms' => $validated['latency_ms'],
                'occurred_at' => $validated['occurred_at'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Ne jamais faire echouer le cote Vercel (qui avale de toute facon).
            // Un hotel_id inconnu (FK) ou une panne DB tombe ici.
            Log::warning('ai_usage_ingest_failed', ['error' => $e->getMessage()]);

            return response()->json(['data' => ['recorded' => false]], 202);
        }

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    /** Resolution best-effort de l'operateur depuis le token porteur Sanctum. */
    private function resolveUserId(?string $actorToken): ?string
    {
        if (! $actorToken) {
            return null;
        }
        try {
            $token = PersonalAccessToken::findToken($actorToken);
            if (! $token) {
                return null;
            }
            // Respecte l'expiration du token (Sanctum 4).
            if ($token->expires_at && $token->expires_at->isPast()) {
                return null;
            }

            return $token->tokenable_id ? (string) $token->tokenable_id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
