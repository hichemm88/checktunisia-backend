<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD des modèles de message (§ Écran 5). `index()` reste utilisable tel
 * quel par le bouton WhatsApp (§ Écran 4, actifs uniquement) ; `?all=1`
 * ajoute les modèles désactivés pour l'écran de gestion, réservé à l'admin
 * — un membre n'a pas à voir ni modifier les modèles que l'équipe utilise
 * pour démarcher.
 */
class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MessageTemplate::query();

        if (!($request->boolean('all') && $request->user()?->isAdmin())) {
            $query->where('active', true);
        }

        return response()->json(['data' => $query->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'segment' => ['sometimes', 'nullable', Rule::in(['maison_hotes', 'guesthouse', 'boutique_hotel', 'hotel', 'location_entiere', 'autre'])],
            'active' => ['sometimes', 'boolean'],
        ]);

        // ══════════════════════════════════════════════════════════════
        // RÈGLE MÉTIER — voir ProspectionMessageTemplateSeeder : aucun
        // modèle, ici non plus, ne doit laisser entendre une transmission
        // automatique des fiches aux autorités. Ce contrôle reste humain
        // à la relecture (pas de filtre automatique, trop fragile sur du
        // texte libre) — d'où ce rappel à chaque point de création.
        // ══════════════════════════════════════════════════════════════
        $template = MessageTemplate::create($data);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $template = MessageTemplate::findOrFail($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'body' => ['sometimes', 'string'],
            'segment' => ['sometimes', 'nullable', Rule::in(['maison_hotes', 'guesthouse', 'boutique_hotel', 'hotel', 'location_entiere', 'autre'])],
            'active' => ['sometimes', 'boolean'],
        ]);
        $template->update($data);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Suppression franche plutôt qu'un « archived » de plus (déjà
        // couvert par `active`, qui retire le modèle du bouton WhatsApp
        // sans perdre son historique) : un modèle mal créé par erreur doit
        // pouvoir disparaître pour de bon sans polluer la liste de gestion.
        MessageTemplate::findOrFail($id)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (!$request->user()?->isAdmin()) {
            abort(response()->json([
                'data' => null,
                'errors' => [['code' => 'PERMISSION_DENIED', 'message' => 'Réservé aux administrateurs.', 'field' => null]],
            ], 403));
        }
    }
}
