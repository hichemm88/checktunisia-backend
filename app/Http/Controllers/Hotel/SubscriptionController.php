<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns the current subscription for the authenticated user's organisation.
 *
 * Route is outside the 'tenant' middleware group — subscription is org-level
 * and must be readable even before a first property has been created.
 */
class SubscriptionController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            $sub = $org->activeSubscription()->with('plan')->first();
        } else {
            // Legacy fallback: user has no org yet — try hotel pivot
            $hotel = $user->hotel();
            $sub   = $hotel
                ? Subscription::where('hotel_id', $hotel->id)
                    ->where('status', 'active')
                    ->with('plan')
                    ->latest('started_at')
                    ->first()
                : null;
        }

        if (!$sub) {
            return response()->json(['data' => ['status' => 'none']]);
        }

        return response()->json([
            'data' => [
                'id'             => $sub->id,
                'plan'           => $sub->plan,
                'status'         => $sub->status,
                'billing_cycle'  => $sub->billing_cycle,
                'started_at'     => $sub->started_at,
                'expires_at'     => $sub->expires_at,
                'auto_renew'     => $sub->auto_renew,
                'days_remaining' => $sub->days_remaining,
                // Fonctionnalités effectives (pack + overrides négociés) avec
                // usage — même payload pour web et mobile.
                'entitlements'   => $org ? \App\Services\Subscription\PlanEntitlements::summary($org) : null,
            ],
        ]);
    }

    /** Invoice history for the authenticated user's own organisation (or hotel, legacy). */
    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->scopedInvoices($request)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $invoices->map(fn(Invoice $inv) => [
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
            ]),
            'meta' => ['total' => $invoices->total(), 'current_page' => $invoices->currentPage(), 'per_page' => $invoices->perPage()],
        ]);
    }

    public function downloadInvoicePdf(Request $request, string $id)
    {
        $invoice = $this->scopedInvoices($request)
            ->with(['subscription.organization', 'subscription.plan'])
            ->findOrFail($id);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'org'     => $invoice->subscription?->organization,
            'plan'    => $invoice->subscription?->plan,
            'issuer'  => \App\Models\PlatformSetting::get(),
        ])->download("facture-{$invoice->invoice_number}.pdf");
    }

    /** Invoices belonging to the caller's own organization (or hotel, legacy) — never another tenant's. */
    private function scopedInvoices(Request $request)
    {
        $user = $request->user();
        $org  = $user->organization;

        if ($org) {
            return Invoice::whereHas('subscription', fn($q) => $q->where('organization_id', $org->id));
        }

        $hotel = $user->hotel();
        return Invoice::where('hotel_id', $hotel?->id ?? '00000000-0000-0000-0000-000000000000');
    }
}
