<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
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

    // ─── Hébergeur-scoped (subscriptions/invoices are org-level) ─────────────────

    public function indexForHost(Request $request, string $hostId): JsonResponse {
        Organization::findOrFail($hostId);
        $subs = Subscription::with('plan')->where('organization_id', $hostId)
            ->orderByDesc('created_at')->paginate($request->integer('per_page', 50));
        return response()->json([
            'data' => $subs->items(),
            'meta' => ['total' => $subs->total(), 'current_page' => $subs->currentPage(), 'per_page' => $subs->perPage()],
        ]);
    }

    public function storeForHost(Request $request, string $hostId): JsonResponse {
        $org = Organization::findOrFail($hostId);
        $v = $request->validate([
            'plan_id'       => ['required', 'exists:subscription_plans,id'],
            'billing_cycle' => ['in:monthly,yearly'],
            'started_at'    => ['required', 'date'],
            'expires_at'    => ['required', 'date', 'after:started_at'],
            'auto_renew'    => ['boolean'],
            'custom_price'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $sub = DB::transaction(function () use ($v, $org, $request) {
            $sub = Subscription::create(array_merge($v, [
                'organization_id' => $org->id,
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
            Cache::forget("org_subscription_active:{$org->id}");
            AuditLogger::log('subscription.activated', $sub);
            return $sub;
        });

        return response()->json(['data' => $sub->load('plan')], 201);
    }

    public function updateForHost(Request $request, string $hostId, string $id): JsonResponse {
        $sub = Subscription::where('organization_id', $hostId)->findOrFail($id);

        $v = $request->validate([
            'status'           => ['sometimes', 'in:active,suspended,cancelled,expired'],
            'expires_at'       => ['sometimes', 'date'],
            'plan_id'          => ['sometimes', 'exists:subscription_plans,id'],
            'suspended_reason' => ['nullable', 'string'],
            'custom_price'     => ['nullable', 'numeric', 'min:0'],
        ]);

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

        Cache::forget("org_subscription_active:{$hostId}");

        return response()->json(['data' => $sub->fresh()->load('plan')]);
    }

    public function invoicesForHost(Request $request, string $hostId): JsonResponse {
        Organization::findOrFail($hostId);
        $invoices = Invoice::whereHas('subscription', fn($q) => $q->where('organization_id', $hostId))
            ->orderByDesc('created_at')->paginate($request->integer('per_page', 50));
        return response()->json([
            'data' => $invoices->items(),
            'meta' => ['total' => $invoices->total(), 'current_page' => $invoices->currentPage(), 'per_page' => $invoices->perPage()],
        ]);
    }

    public function createInvoiceForHost(Request $request, string $hostId): JsonResponse {
        Organization::findOrFail($hostId);
        $v = $request->validate([
            'subscription_id' => ['required', 'uuid'],
            'amount'          => ['nullable', 'numeric', 'min:0'],
            'tax_amount'      => ['numeric', 'min:0'],
            'due_at'          => ['nullable', 'date'],
            'notes'           => ['nullable', 'string'],
        ]);

        $sub = Subscription::where('organization_id', $hostId)->with('plan')->findOrFail($v['subscription_id']);

        if (!isset($v['amount'])) {
            $v['amount'] = $sub->custom_price
                ?? ($sub->billing_cycle === 'yearly' ? $sub->plan->price_yearly : $sub->plan->price_monthly)
                ?? 0;
        }
        $v['tax_amount']     = $v['tax_amount'] ?? 0;
        $v['total_amount']   = $v['amount'] + $v['tax_amount'];
        $v['hotel_id']       = null; // org-level invoice — no specific établissement
        $v['invoice_number'] = 'INV-' . date('Y') . '-' . str_pad(Invoice::whereYear('created_at', now()->year)->count() + 1, 4, '0', STR_PAD_LEFT);
        $v['created_by']     = $request->user()->id;
        $invoice = Invoice::create($v);

        return response()->json(['data' => $invoice], 201);
    }

    public function updateInvoiceForHost(Request $request, string $hostId, string $id): JsonResponse {
        $invoice = Invoice::whereHas('subscription', fn($q) => $q->where('organization_id', $hostId))->findOrFail($id);
        $wasPaid = $invoice->status === 'paid';

        $v = $request->validate([
            'status'            => ['sometimes', 'in:draft,sent,paid,overdue,void'],
            'paid_at'           => ['nullable', 'date'],
            'payment_method'    => ['nullable', 'string'],
            'payment_reference' => ['nullable', 'string'],
        ]);
        $invoice->update($v);
        AuditLogger::log('invoice.updated', $invoice);

        if (!$wasPaid && $invoice->fresh()->status === 'paid') {
            $this->notifyPaymentReceived($invoice);
        }

        return response()->json(['data' => $invoice->fresh()]);
    }

    /** Platform-wide invoice list for the Facturation tab, filterable by hébergeur/statut/période. */
    public function allInvoices(Request $request): JsonResponse {
        $query = Invoice::with(['subscription.organization', 'subscription.plan', 'hotel'])
            ->orderByDesc('created_at');

        if ($request->filled('status'))          $query->where('status', $request->status);
        if ($request->filled('organization_id')) $query->whereHas('subscription', fn($q) => $q->where('organization_id', $request->organization_id));
        if ($request->filled('from'))            $query->whereDate('created_at', '>=', $request->from);
        if ($request->filled('to'))              $query->whereDate('created_at', '<=', $request->to);

        $invoices = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $invoices->map(fn($inv) => [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'amount'         => $inv->amount,
                'tax_amount'     => $inv->tax_amount,
                'total_amount'   => $inv->total_amount,
                'currency'       => $inv->currency,
                'status'         => $inv->status,
                'due_at'         => $inv->due_at,
                'paid_at'        => $inv->paid_at,
                'created_at'     => $inv->created_at,
                'organization'   => $inv->subscription?->organization
                    ? ['id' => $inv->subscription->organization->id, 'name' => $inv->subscription->organization->name]
                    : null,
                'hotel_name'     => $inv->hotel?->name,
            ]),
            'meta' => ['total' => $invoices->total(), 'current_page' => $invoices->currentPage(), 'per_page' => $invoices->perPage()],
        ]);
    }

    /** Streams the invoice as a PDF download. */
    public function downloadInvoicePdf(string $hostId, string $id) {
        $invoice = Invoice::whereHas('subscription', fn($q) => $q->where('organization_id', $hostId))
            ->with(['subscription.organization', 'subscription.plan'])
            ->findOrFail($id);

        $org = $invoice->subscription?->organization;

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'org'     => $org,
            'plan'    => $invoice->subscription?->plan,
        ])->download("facture-{$invoice->invoice_number}.pdf");
    }

    /** Shared by invoice update paths that transition an invoice to "paid". */
    private function notifyPaymentReceived(Invoice $invoice): void
    {
        $invoice->loadMissing(['hotel', 'subscription.organization', 'subscription.plan']);
        $sub  = $invoice->subscription;
        $org  = $sub?->organization ?? $invoice->hotel?->organization;
        $hotel = $invoice->hotel;

        $to = $org?->contact_email
            ?? $hotel?->contacts()->where('type', 'email')->where('is_primary', true)->first()?->value;

        \App\Services\Email\SystemMailer::send('payment_received', $to, [
            'name'       => $org?->name ?? $hotel?->name ?? 'Client Qayed',
            'plan_name'  => $sub?->plan?->name ?? '—',
            'expires_at' => $sub?->expires_at?->format('d/m/Y') ?? '—',
            'credentials_box' => \App\Services\Email\SystemMailer::amountBox(
                number_format((float) $invoice->total_amount, 3).' '.$invoice->currency,
                $invoice->invoice_number,
            ),
        ]);
    }
}
