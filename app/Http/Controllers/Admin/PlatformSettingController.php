<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    // ── Platform settings ────────────────────────────────────────────────────

    public function show(): JsonResponse
    {
        $s = PlatformSetting::get();
        // toPublicArray() hides flouci_app_token/flouci_app_secret — never round-trip API
        // credentials to the browser, even for platform_admin.
        return response()->json(['data' => $s->toPublicArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'company_name'         => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_mf'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_rc'           => ['sometimes', 'nullable', 'string', 'max:50'],
            'company_address'      => ['sometimes', 'nullable', 'string'],
            'flouci_enabled'       => ['sometimes', 'boolean'],
            'flouci_app_token'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'flouci_app_secret'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'virement_enabled'     => ['sometimes', 'boolean'],
            'virement_rib'         => ['sometimes', 'nullable', 'string', 'max:50'],
            'virement_iban'        => ['sometimes', 'nullable', 'string', 'max:34'],
            'virement_bank_name'   => ['sometimes', 'nullable', 'string', 'max:100'],
            'virement_beneficiary' => ['sometimes', 'nullable', 'string', 'max:150'],
            'virement_details'     => ['sometimes', 'nullable', 'string'],
        ]);

        $s = PlatformSetting::get();
        $s->update($v);

        // Same reasoning as show(): never round-trip flouci_app_token/flouci_app_secret.
        return response()->json(['data' => $s->fresh()->toPublicArray()]);
    }

    // ── Subscription plans ───────────────────────────────────────────────────

    public function listPlans(Request $request): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->paginate($request->integer('per_page', 50));
        return response()->json([
            'data' => $plans->items(),
            'meta' => ['total' => $plans->total(), 'current_page' => $plans->currentPage(), 'per_page' => $plans->perPage()],
        ]);
    }

    public function updatePlan(Request $request, int $id): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $v = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100'],
            'min_rooms'     => ['sometimes', 'integer', 'min:1'],
            'max_rooms'     => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'price_yearly'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'features'      => ['sometimes', 'array'],
            'is_active'     => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer'],
        ]);

        $plan->update($v);

        return response()->json(['data' => $plan->fresh()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => ['required', 'string', 'max:100', 'unique:subscription_plans,slug'],
            'min_rooms'     => ['required', 'integer', 'min:1'],
            'max_rooms'     => ['nullable', 'integer', 'min:1'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly'  => ['nullable', 'numeric', 'min:0'],
            'currency'      => ['sometimes', 'string', 'max:10'],
            'features'      => ['sometimes', 'array'],
        ]);

        $plan = SubscriptionPlan::create(array_merge($v, [
            'currency'   => $v['currency'] ?? 'TND',
            'is_active'  => true,
            'sort_order' => SubscriptionPlan::max('sort_order') + 1,
        ]));

        return response()->json(['data' => $plan], 201);
    }

    public function destroyPlan(int $id): JsonResponse
    {
        $plan = SubscriptionPlan::withCount('subscriptions')->findOrFail($id);
        if ($plan->subscriptions_count > 0) {
            return response()->json([
                'errors' => [['code' => 'IN_USE', 'message' => "Ce pack est utilisé par {$plan->subscriptions_count} abonnement(s) — désactivez-le plutôt que de le supprimer."]],
            ], 422);
        }
        $plan->delete();
        return response()->json(null, 204);
    }

    // ── Payments (read-only ledger) ──────────────────────────────────────────

    public function payments(Request $request): JsonResponse
    {
        $query = \App\Models\Payment::with(['hotel', 'invoice.subscription.organization'])->orderByDesc('created_at');

        if ($request->filled('status'))   $query->where('status', $request->status);
        if ($request->filled('provider')) $query->where('provider', $request->provider);
        if ($request->filled('hotel_id')) $query->where('hotel_id', $request->hotel_id);

        $payments = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $payments->map(fn($p) => [
                'id'                 => $p->id,
                'provider'           => $p->provider,
                'status'             => $p->status,
                'amount'             => $p->amount,
                'currency'           => $p->currency,
                'hotel_name'         => $p->hotel?->name ?? $p->invoice?->subscription?->organization?->name,
                'invoice_number'     => $p->invoice?->invoice_number,
                'declared_reference' => $p->declared_reference,
                'declared_at'        => $p->declared_at,
                'completed_at'       => $p->completed_at,
                'created_at'         => $p->created_at,
            ]),
            'meta' => ['total' => $payments->total(), 'current_page' => $payments->currentPage(), 'per_page' => $payments->perPage()],
        ]);
    }
}
