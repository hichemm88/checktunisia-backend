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
        $pricing = null;
        if ($sub && in_array($sub->status, ['active', 'trial'], true)) {
            // Un compte interne ne pèse rien dans le revenu : 0, et pas
            // « null » — l'admin doit lire explicitement zéro plutôt qu'une
            // absence qu'il pourrait prendre pour une donnée manquante.
            $mrr = $org->isCommercial()
                ? \App\Services\Subscription\PlanPricing::monthlyValue($sub, $org->properties->count())
                : 0.0;
            $pricing = \App\Services\Subscription\PlanPricing::detail($sub, $org->properties->count());
        }

        // ── Quota check-ins (grille V2) : conso du mois vs quota, dépassements
        // facturés et historique 12 mois — la vue 360° de l'upsell.
        $quota = \App\Services\Subscription\CheckinQuota::status($org);

        $monthlyCounts = \App\Models\CheckIn::whereIn('hotel_id', $hotelIds)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->startOfMonth()->subMonths(11))
            ->selectRaw("to_char(date_trunc('month', created_at), 'YYYY-MM') as month, COUNT(*) as count")
            ->groupBy('month')
            ->pluck('count', 'month');

        $charges = \App\Models\OverageCharge::with('invoice')
            ->where('organization_id', $org->id)
            ->where('period', '>=', now()->startOfMonth()->subMonths(11)->toDateString())
            ->get()
            ->keyBy(fn ($c) => $c->period->format('Y-m'));

        $quotaHistory = [];
        for ($i = 11; $i >= 0; $i--) {
            $month  = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $charge = $charges->get($month);
            $quotaHistory[] = [
                'month'          => $month,
                'count'          => (int) ($monthlyCounts[$month] ?? 0),
                'overage_count'  => $charge?->overage_count,
                'overage_amount' => $charge?->amount,
                'overage_status' => $charge?->status,
                'invoice_number' => $charge?->invoice?->invoice_number,
            ];
        }

        return response()->json(['data' => array_merge($org->toArray(), [
            'users' => $users,
            'metrics' => [
                'last_check_in_at'     => $lastCheckIn,
                'check_ins_this_month' => $checkInsThisMonth,
                'mrr'                  => $mrr,
            ],
            // Fonctionnalités effectives + usage réel + overrides bruts (pour
            // l'édition « deal négocié » sur la fiche).
            'entitlements'      => \App\Services\Subscription\PlanEntitlements::summary($org),
            'feature_overrides' => (array) ($sub?->metadata['feature_overrides'] ?? []),
            'pricing'           => $pricing,
            'quota'             => $quota,
            'quota_history'     => $quotaHistory,
            'is_legacy_plan'    => (bool) ($sub?->is_legacy_plan),
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
            // Bascule commercial ↔ interne. Réservée au back-office (route
            // platform_admin) : c'est une décision d'exploitation, jamais une
            // action client. Marquer un compte interne le sort de toute
            // facturation et de toutes les métriques de revenu.
            'billing_mode'        => ['sometimes', 'in:'.implode(',', Organization::BILLING_MODES)],
            'billing_mode_note'   => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $org->update($v);
        AuditLogger::log('organization.updated', $org, $old, $org->fresh()->toArray());

        // Une exemption commerciale se trace à part : c'est ce qui explique
        // qu'un compte cesse d'apparaître dans le chiffre d'affaires.
        if (array_key_exists('billing_mode', $v) && ($old['billing_mode'] ?? null) !== $v['billing_mode']) {
            AuditLogger::log('organization.billing_mode_changed', $org,
                oldValues: ['billing_mode' => $old['billing_mode'] ?? null],
                newValues: ['billing_mode' => $v['billing_mode'], 'note' => $v['billing_mode_note'] ?? null],
            );
        }

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
