<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autorisation d'un scan IA, prononcée AVANT le moindre appel payant.
 *
 * ── Le problème que cet endpoint résout ─────────────────────────────────
 *
 * Les fonctions serverless `/api/scan/cin` et `/api/scan/mrz` appellent Claude
 * vision — une API facturée à l'appel. Elles ne vérifiaient que la FORME du
 * jeton porteur :
 *
 *     if (!/^Bearer\s+.+/i.test(authorization)) { 401 }
 *
 * N'importe quelle chaîne passait. Le jeton n'était réellement utilisé
 * qu'APRÈS, pour attribuer le coût. Conséquences :
 *
 *  1. Toute personne connaissant l'URL — publique, sur le domaine de
 *     l'application — pouvait consommer le budget Anthropic sans compte ;
 *  2. `propertyId` étant choisi par l'appelant, la dépense était imputée à
 *     l'établissement de son choix. L'écran « Coûts IA » aurait présenté ces
 *     montants comme la consommation réelle d'un client ;
 *  3. le plafond de débit vit en mémoire de l'instance serverless et se
 *     répartit sur autant de compteurs que de `propertyId` : il ne freinait
 *     rien.
 *
 * ── Ce que l'endpoint garantit ──────────────────────────────────────────
 *
 * Le jeton est authentifié par `auth:sanctum` (401 sinon), le rôle par la
 * garde du groupe (403 sinon), et l'établissement demandé est vérifié ICI :
 * il doit faire partie de ceux auxquels le compte est réellement rattaché.
 *
 * Ce dernier point ne pouvait pas être délégué à `ResolveTenant` : ce
 * middleware RETOMBE sur le premier établissement accessible quand
 * l'en-tête ne correspond à rien. C'est le bon comportement pour servir un
 * écran — jamais un établissement étranger — mais il ne sait pas répondre
 * « celui-ci n'est pas le vôtre », qui est précisément la question posée ici.
 *
 * ── Ce que l'endpoint ne fait PAS ───────────────────────────────────────
 *
 * Il ne consomme aucun quota et n'écrit rien. Il ne dit pas non plus si un
 * établissement existe : un identifiant inconnu et un identifiant appartenant
 * à quelqu'un d'autre donnent la même réponse, pour ne pas transformer cette
 * route en oracle d'existence.
 */
class ScanAuthorizationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_id' => ['required', 'uuid'],
        ]);

        $user = $request->user();

        $allowed = $user->hotels()
            ->where('hotels.id', $validated['property_id'])
            ->exists();

        if (! $allowed) {
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'PROPERTY_NOT_ACCESSIBLE',
                    'message' => 'Cet établissement n\'est pas accessible avec ce compte.',
                    'field' => 'property_id',
                ]],
            ], 403);
        }

        return response()->json(['data' => [
            'property_id' => $validated['property_id'],
            // L'identifiant de l'opérateur, pour que la fonction serverless
            // puisse l'attacher au suivi des coûts sans avoir à redemander.
            'user_id' => $user->id,
        ]]);
    }
}
