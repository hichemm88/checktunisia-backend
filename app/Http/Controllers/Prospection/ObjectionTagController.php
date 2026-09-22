<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Models\Prospection\ObjectionTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Référentiel objections (§ Modèle de données), éditable par l'admin.
 */
class ObjectionTagController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ObjectionTag::where('active', true)->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate(['label' => ['required', 'string', 'max:150', 'unique:prospection.objection_tags,label']]);

        $tag = ObjectionTag::create($data);

        return response()->json(['data' => ['id' => $tag->id]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tag = ObjectionTag::findOrFail($id);
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:150'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $tag->update($data);

        return response()->json(['data' => ['id' => $tag->id]]);
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
