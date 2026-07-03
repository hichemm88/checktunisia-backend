<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SubscriptionAdminController extends Controller {

    /**
     * List subscriptions for a hotel.
     * If the hotel belongs to an org, also include the org-level subscriptions
     * so platform admins can see and manage them from any property page.
     */
    public function index(string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);

        $query = Subscription::with('plan')->orderByDesc('created_at');

        if ($hotel->organization_id) {
            // Return all subscriptions tied to this org (covers all properties)
            $query->where('organization_id', $hotel->organization_id);
        } else {
            $query->where('hotel_id', $hotel->id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        $v = $request->validate([
            'plan_id'       => ['required', 'exists:subscription_plans,id'],
            'billing_cycle' => ['in:monthly,yearly'],
            'started_at'    => ['required', 'date'],
            'expires_at'    => ['required', 'date', 'after:started_at'],
            'auto_renew'    => ['boolean'],
        ]);

        $sub = DB::transaction(function () use ($v, $hotel, $request) {
            $sub = Subscription::create(array_merge($v, [
                'hotel_id'        => $hotel->id,
                'organization_id' => $hotel->organization_id, // link to org if exists
                'status'          => 'active',
                'created_by'      => $request->user()->id,
            ]));
            SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'activated',
                'new_status'      => 'active',
                'performed_by'    => $request->user()->id,
                'created_at'      => now(),
            ]);
            $this->clearSubscriptionCache($hotel);
            AuditLogger::log('subscription.activated', $sub);
            return $sub;
        });

        return response()->json(['data' => $sub->load('plan')], 201);
    }

    public function update(Request $request, string $hotelId, string $id): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);

        // Find the subscription by ID, scoped to hotel or its org
        $sub = $hotel->organization_id
            ? Subscription::where('organization_id', $hotel->organization_id)->findOrFail($id)
            : Subscription::where('hotel_id', $hotelId)->findOrFail($id);

        $v = $request->validate([
            'status'           => ['sometimes', 'in:active,suspended,cancelled,expired'],
            'expires_at'       => ['sometimes', 'date'],
            'plan_id'          => ['sometimes', 'exists:subscription_plans,id'],
            'suspended_reason' => ['nullable', 'string'],
        ]);

        // Auto-set suspended_at when suspending, clear it when reactivating
        if (isset($v['status'])) {
            if ($v['status'] === 'suspended' && !$sub->suspended_at) {
                $v['suspended_at'] = now();
            } elseif ($v['status'] === 'active') {
                $v['suspended_at']     = null;
                $v['suspended_reason'] = null;
            }
        }

        $old = $sub->only(['status', 'plan_id']);
        $sub->update($v);

        SubscriptionEvent::create([
            'subscription_id' => $sub->id,
            'event_type'      => 'updated',
            'previous_status' => $old['status'],
            'new_status'      => $sub->status,
            'performed_by'    => $request->user()->id,
            'created_at'      => now(),
        ]);

        $this->clearSubscriptionCache($hotel);

        return response()->json(['data' => $sub->fresh()->load('plan')]);
    }

    public function invoices(string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        return response()->json(['data' => $hotel->invoices()->orderByDesc('created_at')->get()]);
    }

    public function createInvoice(Request $request, string $hotelId): JsonResponse {
        $hotel = Hotel::findOrFail($hotelId);
        $v = $request->validate([
            'subscription_id' => ['required', 'uuid'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'tax_amount'      => ['numeric', 'min:0'],
            'due_at'          => ['nullable', 'date'],
            'notes'           => ['nullable', 'string'],
        ]);
        $v['total_amount']    = ($v['amount'] ?? 0) + ($v['tax_amount'] ?? 0);
        $v['hotel_id']        = $hotel->id;
        $v['invoice_number']  = 'INV-' . date('Y') . '-' . str_pad(Invoice::whereYear('created_at', now()->year)->count() + 1, 4, '0', STR_PAD_LEFT);
        $v['created_by']      = $request->user()->id;
        $invoice = Invoice::create($v);
        return response()->json(['data' => $invoice], 201);
    }

    public function updateInvoice(Request $request, string $hotelId, string $id): JsonResponse {
        $invoice = Invoice::where('hotel_id', $hotelId)->findOrFail($id);
        $v = $request->validate([
            'status'            => ['sometimes', 'in:draft,sent,paid,overdue,void'],
            'paid_at'           => ['nullable', 'date'],
            'payment_method'    => ['nullable', 'string'],
            'payment_reference' => ['nullable', 'string'],
        ]);
        $invoice->update($v);
        AuditLogger::log('invoice.updated', $invoice);
        return response()->json(['data' => $invoice->fresh()]);
    }

    /** Clear both hotel-level and org-level subscription caches. */
    private function clearSubscriptionCache(Hotel $hotel): void
    {
        Cache::forget("hotel_subscription_active:{$hotel->id}");
        if ($hotel->organization_id) {
            Cache::forget("org_subscription_active:{$hotel->organization_id}");
        }
    }
}
