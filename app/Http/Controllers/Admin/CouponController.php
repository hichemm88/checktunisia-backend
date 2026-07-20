<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestion admin des codes promo (CRUD). Les remises s'appliquent aux factures
 * via CouponService ; ici on ne fait que gerer le catalogue de codes.
 */
class CouponController extends Controller
{
    /** GET /admin/coupons */
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::withCount('redemptions')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => $coupons->items(),
            'meta' => ['total' => $coupons->total(), 'current_page' => $coupons->currentPage(), 'per_page' => $coupons->perPage()],
        ]);
    }

    /** POST /admin/coupons */
    public function store(Request $request): JsonResponse
    {
        $v = $this->validated($request);
        $v['created_by'] = $request->user()->id;

        $coupon = Coupon::create($v);
        AuditLogger::log('coupon.created', $coupon, newValues: ['code' => $coupon->code]);

        return response()->json(['data' => $coupon], 201);
    }

    /** PATCH /admin/coupons/{id} — le code reste modifiable tant qu'il n'a jamais servi. */
    public function update(Request $request, string $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $v = $this->validated($request, $coupon);

        // Un code deja utilise ne doit plus changer de code (les redemptions y
        // referent) — on ignore silencieusement une tentative de renommage.
        if ($coupon->used_count > 0) {
            unset($v['code']);
        }

        $coupon->update($v);
        AuditLogger::log('coupon.updated', $coupon);

        return response()->json(['data' => $coupon->fresh()->loadCount('redemptions')]);
    }

    /** DELETE /admin/coupons/{id} — un code deja utilise est desactive plutot que supprime (trace conservee). */
    public function destroy(string $id): JsonResponse
    {
        $coupon = Coupon::withCount('redemptions')->findOrFail($id);

        if ($coupon->redemptions_count > 0) {
            $coupon->update(['active' => false]);
            AuditLogger::log('coupon.deactivated', $coupon);

            return response()->json(['data' => ['id' => $coupon->id, 'active' => false, 'deactivated' => true]]);
        }

        AuditLogger::log('coupon.deleted', $coupon, oldValues: ['code' => $coupon->code]);
        $coupon->delete();

        return response()->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?Coupon $existing = null): array
    {
        $creating = $existing === null;

        $validator = validator($request->all(), [
            'code' => [
                $creating ? 'required' : 'sometimes',
                'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($existing?->id),
            ],
            'type'        => [$creating ? 'required' : 'sometimes', Rule::in(Coupon::TYPES)],
            'value'       => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:200'],
            'min_amount'  => ['nullable', 'numeric', 'min:0'],
            'max_uses'    => ['nullable', 'integer', 'min:1'],
            'expires_at'  => ['nullable', 'date'],
            'active'      => ['boolean'],
        ]);

        // Un pourcentage ne peut pas depasser 100.
        $validator->after(function ($v) use ($request, $existing) {
            $type = $request->input('type', $existing?->type);
            if ($type === Coupon::TYPE_PERCENT && $request->filled('value') && (float) $request->input('value') > 100) {
                $v->errors()->add('value', 'Une remise en pourcentage ne peut pas depasser 100.');
            }
        });

        return $validator->validate();
    }
}
