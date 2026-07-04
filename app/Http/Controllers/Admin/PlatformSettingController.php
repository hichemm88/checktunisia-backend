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
        return response()->json(['data' => $s->toArray()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
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

        return response()->json(['data' => $s->fresh()->toArray()]);
    }

    // ── Subscription plans ───────────────────────────────────────────────────

    public function listPlans(): JsonResponse
    {
        $plans = SubscriptionPlan::orderBy('sort_order')->get();
        return response()->json(['data' => $plans]);
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
}
