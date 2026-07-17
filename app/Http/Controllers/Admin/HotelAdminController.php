<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelAddress;
use App\Models\HotelContact;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class HotelAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Hotel::with(['activeSubscription.plan', 'address'])
            ->withCount('checkIns');

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('search'))  $query->where('name', 'ilike', "%{$request->search}%");

        $hotels = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $hotels->map(fn(Hotel $h) => [
                'id'                  => $h->id,
                'name'                => $h->name,
                'slug'                => $h->slug,
                'type'                => $h->type,
                'room_count'          => $h->room_count,
                'status'              => $h->status,
                'registration_number' => $h->registration_number,
                'subscription'        => $h->activeSubscription ? [
                    'plan'       => $h->activeSubscription->plan?->name,
                    'status'     => $h->activeSubscription->status,
                    'expires_at' => $h->activeSubscription->expires_at,
                ] : null,
                'check_ins_count' => $h->check_ins_count,
                'created_at'      => $h->created_at,
            ]),
            'meta' => ['total' => $hotels->total(), 'current_page' => $hotels->currentPage(), 'per_page' => $hotels->perPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'organization_id'     => ['required', 'uuid', 'exists:organizations,id'],
            'type'                => ['required', 'in:hotel,guesthouse,rental,hostel,resort'],
            'room_count'          => ['required', 'integer', 'min:1'],
            'registration_number' => ['nullable', 'string', 'max:100', 'unique:hotels'],
            'stars'               => ['nullable', 'integer', 'between:1,5'],
            'address'             => ['required', 'array'],
            'address.line1'       => ['required', 'string'],
            'address.city'        => ['required', 'string'],
            'address.governorate' => ['required', 'string'],
            'address.postal_code' => ['nullable', 'string'],
            'contacts'            => ['nullable', 'array'],
            'contacts.*.type'     => ['required', 'string'],
            'contacts.*.value'    => ['required', 'string'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        $hotel = DB::transaction(function () use ($validated, $request) {
            $hotel = Hotel::create([
                'name'                => $validated['name'],
                'organization_id'     => $validated['organization_id'],
                'type'                => $validated['type'],
                'room_count'          => $validated['room_count'],
                'registration_number' => $validated['registration_number'] ?? null,
                'stars'               => $validated['stars'] ?? null,
                'status'              => 'active',
                'created_by'          => $request->user()->id,
            ]);

            HotelAddress::create(array_merge($validated['address'], ['hotel_id' => $hotel->id, 'is_primary' => true]));

            foreach ($validated['contacts'] ?? [] as $contact) {
                HotelContact::create(array_merge($contact, ['hotel_id' => $hotel->id]));
            }

            AuditLogger::log('hotel.created', $hotel, [], $hotel->toArray());
            return $hotel;
        });

        return response()->json(['data' => $hotel->load(['address', 'contacts'])], 201);
    }

    public function show(string $id): JsonResponse
    {
        $hotel = Hotel::with(['address', 'contacts', 'activeSubscription.plan'])->findOrFail($id);

        return response()->json(['data' => array_merge($hotel->toArray(), [
            'active_subscription' => $hotel->activeSubscription,
        ])]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $old   = $hotel->toArray();

        $validated = $request->validate([
            'name'       => ['sometimes', 'string', 'max:255'],
            'room_count' => ['sometimes', 'integer', 'min:1'],
            'status'     => ['sometimes', 'in:active,suspended,pending,closed'],
            'stars'      => ['nullable', 'integer', 'between:1,5'],
        ]);

        $hotel->update($validated);
        AuditLogger::log('hotel.updated', $hotel, $old, $hotel->fresh()->toArray());

        return response()->json(['data' => $hotel->fresh()]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];

        $hotel->update(['status' => 'suspended']);

        // Also suspend the active subscription
        if ($sub = $hotel->activeSubscription) {
            $sub->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => $reason]);
            // Bust cache
            cache()->forget("hotel_subscription_active:{$hotel->id}");
        }

        AuditLogger::log('hotel.suspended', $hotel, [], ['reason' => $reason]);

        return response()->json(['data' => ['status' => 'suspended', 'suspended_at' => now()]]);
    }

    public function activate(string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);
        $hotel->update(['status' => 'active']);
        cache()->forget("hotel_subscription_active:{$hotel->id}");
        AuditLogger::log('hotel.activated', $hotel);

        return response()->json(['data' => ['status' => 'active']]);
    }

    public function destroy(string $id): JsonResponse
    {
        $hotel = Hotel::findOrFail($id);

        DB::transaction(function () use ($hotel) {
            AuditLogger::log('hotel.deleted', $hotel, $hotel->toArray(), []);

            // Soft-deleting the hotel alone leaves its check-ins live and its
            // staff active — several authority-side queries only look at
            // check-in/user state and never re-check whether the hotel itself
            // still exists, so a deleted hotel's data kept leaking through.
            $hotel->checkIns()->delete();

            $userIds = $hotel->users()->pluck('users.id');
            $hotel->users()->detach();
            User::whereIn('id', $userIds)
                ->whereDoesntHave('hotels')
                ->each(function (User $u) {
                    $u->update(['status' => 'inactive']);
                    $u->delete();
                });

            $hotel->delete(); // soft delete
        });

        return response()->json(null, 204);
    }

    public function getUsers(string $hotelId): JsonResponse
    {
        $hotel = Hotel::findOrFail($hotelId);
        $users = $hotel->users()->with('roles')->get();

        return response()->json(['data' => $users->map(fn($u) => [
            'id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name,
            'email' => $u->email, 'role' => $u->primary_role, 'status' => $u->status,
            'last_login_at' => $u->last_login_at,
        ])]);
    }

    public function createUser(Request $request, string $hotelId): JsonResponse
    {
        $hotel = Hotel::findOrFail($hotelId);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:users,email'],
            'role'       => ['required', 'in:hotel_admin,receptionist'],
            'password'   => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $user = DB::transaction(function () use ($validated, $hotel) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'email'      => $validated['email'],
                'password'   => Hash::make($validated['password']),
                'status'     => 'active',
                'email_verified_at' => now(),
            ]);

            $user->assignRole($validated['role']);
            $hotel->users()->attach($user->id, ['granted_at' => now()]);

            AuditLogger::log('user.created', $user, [], $user->only(['email', 'first_name', 'last_name']));
            return $user;
        });

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email, 'role' => $user->primary_role]], 201);
    }

    public function dashboard(): JsonResponse
    {
        $expiringSoon = \App\Models\Subscription::with(['organization', 'hotel', 'plan'])
            ->where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->orderBy('expires_at')
            ->limit(10)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id, 'name' => $s->organization?->name ?? $s->hotel?->name ?? '—',
                'plan' => $s->plan?->name, 'expires_at' => $s->expires_at,
            ]);

        $failedPayments = \App\Models\Payment::with('hotel')
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($p) => ['id' => $p->id, 'hotel_name' => $p->hotel?->name, 'amount' => $p->amount, 'created_at' => $p->created_at]);

        $recentlySuspended = Hotel::where('status', 'suspended')
            ->where('updated_at', '>=', now()->subDays(7))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'name', 'updated_at']);

        $trialsExpiringSoon = \App\Models\Subscription::with('organization')
            ->where('status', 'trial')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->orderBy('expires_at')
            ->limit(10)
            ->get()
            ->map(fn($s) => ['id' => $s->id, 'name' => $s->organization?->name ?? '—', 'expires_at' => $s->expires_at]);

        // Conversion = orgs that once had a trial subscription and now hold any
        // active (paid) one — checked at the org level rather than assuming the
        // same subscription row flips status in place, since an admin manually
        // upgrading a customer may instead create a fresh subscription row.
        $orgsWithTrial = \App\Models\Organization::whereHas('subscriptions', fn($q) => $q->whereRaw("metadata->>'trial' = 'true'"))->pluck('id');
        $convertedTrialOrgs = $orgsWithTrial->isNotEmpty()
            ? \App\Models\Organization::whereIn('id', $orgsWithTrial)->whereHas('subscriptions', fn($q) => $q->where('status', 'active'))->count()
            : 0;

        // 30-day check-in volume, one point per day (missing days filled with 0).
        $rawDaily = \App\Models\CheckIn::where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')->pluck('count', 'day');
        $checkInsChart = collect(range(0, 29))->map(function (int $i) use ($rawDaily) {
            $day = now()->subDays(29 - $i)->format('Y-m-d');
            return ['date' => $day, 'count' => (int) ($rawDaily[$day] ?? 0)];
        });

        // MRR = somme des abonnements actifs au prix effectif (négocié si présent),
        // annuels / 12. Un seul abonnement compté par client (le plus récent) :
        // un vieil abonnement resté « active » à côté du courant ne doit pas
        // gonfler le chiffre. Les essais (trial) ne rapportent rien → exclus.
        $activeSubs = \App\Models\Subscription::with(['plan', 'organization:id,name', 'hotel:id,name'])
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->get()
            ->unique(fn($sub) => $sub->organization_id ?? 'hotel:' . $sub->hotel_id)
            ->values();
        $mrrBreakdown = $activeSubs->map(function ($sub) {
            $price = $sub->custom_price ?? ($sub->billing_cycle === 'yearly' ? $sub->plan?->effective_price_yearly : $sub->plan?->price_monthly);
            $monthly = $price === null ? 0.0
                : ($sub->billing_cycle === 'yearly' ? (float) $price / 12 : (float) $price);
            return [
                'customer'      => $sub->organization?->name ?? $sub->hotel?->name ?? '—',
                'plan'          => $sub->plan?->name ?? '—',
                'billing_cycle' => $sub->billing_cycle,
                'negotiated'    => $sub->custom_price !== null,
                'monthly_value' => round($monthly, 3),
            ];
        });
        $mrr = $mrrBreakdown->sum('monthly_value');

        // Top 5 établissements by check-in volume this month.
        $topHotels = Hotel::withCount(['checkIns' => fn($q) => $q->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)])
            ->orderByDesc('check_ins_count')
            ->limit(5)->get(['id', 'name'])
            ->map(fn($h) => ['id' => $h->id, 'name' => $h->name, 'check_ins_count' => $h->check_ins_count]);

        // Recent signups (self-service trials + admin-created hébergeurs alike).
        $recentSignups = \App\Models\Organization::orderByDesc('created_at')
            ->limit(8)->get(['id', 'name', 'created_at'])
            ->map(fn($o) => ['id' => $o->id, 'name' => $o->name, 'created_at' => $o->created_at]);

        return response()->json([
            'data' => [
                'hotels' => [
                    'total'     => Hotel::count(),
                    'active'    => Hotel::where('status', 'active')->count(),
                    'suspended' => Hotel::where('status', 'suspended')->count(),
                    'pending'   => Hotel::where('status', 'pending')->count(),
                ],
                'check_ins' => [
                    'today'      => \App\Models\CheckIn::whereDate('created_at', today())->count(),
                    'this_month' => \App\Models\CheckIn::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
                ],
                'trials' => [
                    'in_progress'     => \App\Models\Subscription::where('status', 'trial')->count(),
                    'expiring_soon'   => $trialsExpiringSoon,
                    'conversion_rate' => $orgsWithTrial->isNotEmpty() ? round($convertedTrialOrgs / $orgsWithTrial->count() * 100) : null,
                ],
                'alerts' => [
                    'expiring_subscriptions' => $expiringSoon,
                    'failed_payments'        => $failedPayments,
                    'recently_suspended'     => $recentlySuspended,
                ],
                'mrr'              => round($mrr, 3),
                'mrr_breakdown'    => $mrrBreakdown,
                'check_ins_chart'  => $checkInsChart,
                'top_hotels'       => $topHotels,
                'recent_signups'   => $recentSignups,
            ],
        ]);
    }

    /** Global search across hébergeurs, établissements et utilisateurs — for the admin topbar. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => ['organizations' => [], 'hotels' => [], 'users' => [], 'check_ins' => [], 'invoices' => []]]);
        }

        $organizations = \App\Models\Organization::where('name', 'ilike', "%{$q}%")
            ->limit(5)->get(['id', 'name'])
            ->map(fn($o) => ['id' => $o->id, 'label' => $o->name, 'type' => 'organization']);

        $hotels = Hotel::where('name', 'ilike', "%{$q}%")
            ->limit(5)->get(['id', 'name'])
            ->map(fn($h) => ['id' => $h->id, 'label' => $h->name, 'type' => 'hotel']);

        $users = \App\Models\User::where(fn($query) => $query
                ->where('first_name', 'ilike', "%{$q}%")
                ->orWhere('last_name', 'ilike', "%{$q}%")
                ->orWhere('email', 'ilike', "%{$q}%"))
            ->limit(5)->get(['id', 'first_name', 'last_name', 'email'])
            ->map(fn($u) => ['id' => $u->id, 'label' => trim("{$u->first_name} {$u->last_name}")." ({$u->email})", 'type' => 'user']);

        // Matches a check-in reference (e.g. QYD-20260706-0008) — since the admin
        // panel has no check-in detail view of its own, resolve to that check-in's
        // établissement so the result reuses the existing hotel-detail route.
        $checkIns = \App\Models\CheckIn::where('reference', 'ilike', "%{$q}%")
            ->with('hotel:id,name')
            ->limit(5)->get(['id', 'reference', 'hotel_id'])
            ->filter(fn($c) => $c->hotel !== null)
            ->map(fn($c) => ['id' => $c->hotel_id, 'label' => "{$c->reference} — {$c->hotel->name}", 'type' => 'check_in'])
            ->values();

        // Factures par numéro (INV-2026-0001, ou juste « 0001 » / « 2026 »).
        $invoices = \App\Models\Invoice::where('invoice_number', 'ilike', "%{$q}%")
            ->with('subscription.organization:id,name')
            ->limit(5)->get(['id', 'invoice_number', 'subscription_id', 'total_amount', 'currency', 'status'])
            ->map(fn($inv) => [
                'id'     => $inv->id,
                'label'  => $inv->invoice_number
                    . ($inv->subscription?->organization ? " — {$inv->subscription->organization->name}" : ''),
                'type'   => 'invoice',
                'status' => $inv->status,
            ]);

        return response()->json(['data' => [
            'organizations' => $organizations,
            'hotels'        => $hotels,
            'users'         => $users,
            'check_ins'     => $checkIns,
            'invoices'      => $invoices,
        ]]);
    }
}
