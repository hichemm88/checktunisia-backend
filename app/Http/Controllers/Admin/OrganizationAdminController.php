<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Hébergeurs (comptes société/particulier propriétaires d'établissements) — vue platform_admin. */
class OrganizationAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Organization::withCount('properties')->with('activeSubscription.plan');

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('entity_type')) $query->where('entity_type', $request->entity_type);
        if ($request->filled('search'))      $query->where('name', 'ilike', "%{$request->search}%");

        $orgs = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $orgs->map(fn(Organization $o) => [
                'id'                  => $o->id,
                'name'                => $o->name,
                'entity_type'         => $o->entity_type,
                'registration_number' => $o->registration_number,
                'contact_email'       => $o->contact_email,
                'contact_phone'       => $o->contact_phone,
                'status'              => $o->status,
                'properties_count'    => $o->properties_count,
                'subscription'        => $o->activeSubscription ? [
                    'plan'       => $o->activeSubscription->plan?->name,
                    'status'     => $o->activeSubscription->status,
                    'expires_at' => $o->activeSubscription->expires_at,
                ] : null,
                'created_at' => $o->created_at,
            ]),
            'meta' => ['total' => $orgs->total(), 'current_page' => $orgs->currentPage(), 'per_page' => $orgs->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'entity_type'         => ['required', 'in:company,individual'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'contact_email'       => ['required', 'email', 'max:255'],
            'contact_phone'       => ['nullable', 'string', 'max:30'],
            'address'             => ['nullable', 'array'],
        ]);

        $org = Organization::create(array_merge($v, ['status' => 'active']));
        AuditLogger::log('organization.created', $org, [], $org->toArray());

        return response()->json(['data' => $org], 201);
    }

    public function show(string $id): JsonResponse
    {
        $org = Organization::with(['properties.address', 'activeSubscription.plan'])->findOrFail($id);
        $users = $org->users()->with('roles')->get()->map(fn($u) => [
            'id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'email' => $u->email, 'role' => $u->primary_role, 'status' => $u->status,
        ]);

        $hotelIds = $org->properties->pluck('id');

        $lastCheckIn = \App\Models\CheckIn::whereIn('hotel_id', $hotelIds)
            ->orderByDesc('created_at')->value('created_at');

        $checkInsThisMonth = \App\Models\CheckIn::whereIn('hotel_id', $hotelIds)
            ->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)
            ->count();

        $sub = $org->activeSubscription;
        $mrr = null;
        if ($sub && in_array($sub->status, ['active', 'trial'], true)) {
            $effectivePrice = $sub->custom_price ?? ($sub->billing_cycle === 'yearly' ? $sub->plan?->effective_price_yearly : $sub->plan?->price_monthly);
            if ($effectivePrice !== null) {
                $mrr = $sub->billing_cycle === 'yearly' ? round($effectivePrice / 12, 3) : round((float) $effectivePrice, 3);
            }
        }

        return response()->json(['data' => array_merge($org->toArray(), [
            'users' => $users,
            'metrics' => [
                'last_check_in_at'     => $lastCheckIn,
                'check_ins_this_month' => $checkInsThisMonth,
                'mrr'                  => $mrr,
            ],
        ])]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $old = $org->toArray();

        $v = $request->validate([
            'name'                => ['sometimes', 'string', 'max:255'],
            'entity_type'         => ['sometimes', 'in:company,individual'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_email'       => ['sometimes', 'email', 'max:255'],
            'contact_phone'       => ['sometimes', 'nullable', 'string', 'max:30'],
            'address'             => ['sometimes', 'array'],
        ]);

        $org->update($v);
        AuditLogger::log('organization.updated', $org, $old, $org->fresh()->toArray());

        return response()->json(['data' => $org->fresh()]);
    }

    public function destroy(string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        AuditLogger::log('organization.deleted', $org, $org->toArray(), []);
        $org->delete(); // soft delete

        return response()->json(null, 204);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];

        $org->update(['status' => 'suspended']);
        // Suspend every property under this org too — a suspended host shouldn't keep operating properties.
        $org->properties()->update(['status' => 'suspended']);

        if ($sub = $org->activeSubscription) {
            $sub->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => $reason]);
        }

        AuditLogger::log('organization.suspended', $org, [], ['reason' => $reason]);
        \App\Services\Email\SystemMailer::send('account_suspended', $org->contact_email, [
            'name'   => $org->name,
            'reason' => $reason,
        ]);

        return response()->json(['data' => ['status' => 'suspended']]);
    }

    public function activate(string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $org->update(['status' => 'active']);
        $org->properties()->update(['status' => 'active']);
        AuditLogger::log('organization.activated', $org);

        return response()->json(['data' => ['status' => 'active']]);
    }
}
