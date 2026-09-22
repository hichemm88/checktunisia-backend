<?php

namespace App\Http\Controllers\Prospection;

use App\Http\Controllers\Controller;
use App\Http\Requests\Prospection\StoreActionRequest;
use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use Illuminate\Http\JsonResponse;

/**
 * Journal des actions d'un prospect (§ Fiche prospect : timeline
 * antichronologique, formulaire d'ajout en 2 taps max). Append-only : pas
 * d'update ni de delete ici, voir ProspectionAction::UPDATED_AT.
 */
class ActionController extends Controller
{
    public function index(string $establishmentId): JsonResponse
    {
        $establishment = Establishment::findOrFail($establishmentId);

        $actions = $establishment->actions()
            ->with('createdBy:id,name')
            ->orderByDesc('occurred_at')
            ->get();

        return response()->json([
            'data' => $actions->map(fn (ProspectionAction $a) => [
                'id' => $a->id,
                'type' => $a->type,
                'channel' => $a->channel,
                'content' => $a->content,
                'objections' => $a->objections,
                'occurred_at' => $a->occurred_at,
                'created_by' => $a->createdBy?->name,
            ]),
        ]);
    }

    public function store(StoreActionRequest $request, string $establishmentId): JsonResponse
    {
        $establishment = Establishment::findOrFail($establishmentId);

        $action = ProspectionAction::create([
            'establishment_id' => $establishment->id,
            'type' => $request->input('type'),
            'channel' => $request->input('channel'),
            'content' => $request->input('content'),
            'objections' => $request->input('objections'),
            'occurred_at' => $request->input('occurred_at') ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $action->id]], 201);
    }
}
