<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\SubscriptionPlan;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Packs d'abonnement — CRUD admin, source de vérité unique consommée à la fois
 * par la section Abonnements de l'admin et par la section tarifs publique
 * (GET /public/plans). Le champ `marketing` porte le contenu d'affichage
 * trilingue ; `features` reste une map de limites fonctionnelles.
 */
class PlanAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $plans->items(),
            'meta' => ['total' => $plans->total(), 'current_page' => $plans->currentPage(), 'per_page' => $plans->perPage()],
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $v = $request->validated();

        $plan = SubscriptionPlan::create(array_merge($v, [
            'currency'   => $v['currency'] ?? 'TND',
            'is_active'  => $v['is_active'] ?? true,
            'sort_order' => $v['sort_order'] ?? (SubscriptionPlan::max('sort_order') + 1),
        ]));

        AuditLogger::log('plan.created', $plan, [], $plan->only(['name', 'slug', 'price_monthly']));

        return response()->json(['data' => $plan], 201);
    }

    public function update(UpdatePlanRequest $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $old  = $plan->only(['name', 'slug', 'price_monthly', 'price_yearly', 'included_properties', 'extra_property_price', 'features', 'is_active', 'sort_order']);

        $plan->update($request->validated());

        AuditLogger::log('plan.updated', $plan, $old, $plan->fresh()->only(['name', 'slug', 'price_monthly', 'price_yearly', 'included_properties', 'extra_property_price', 'features', 'is_active', 'sort_order']));

        return response()->json(['data' => $plan->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $plan = SubscriptionPlan::withCount('subscriptions')->findOrFail($id);
        if ($plan->subscriptions_count > 0) {
            return response()->json([
                'errors' => [['code' => 'IN_USE', 'message' => "Ce pack est utilisé par {$plan->subscriptions_count} abonnement(s) — désactivez-le plutôt que de le supprimer."]],
            ], 422);
        }

        AuditLogger::log('plan.deleted', $plan, $plan->only(['name', 'slug']), []);
        $plan->delete();

        return response()->json(null, 204);
    }
}
