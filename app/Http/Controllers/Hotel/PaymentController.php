<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\FlouciService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Hotel payment flow via Flouci.
 *
 * POST /hotel/payments/initiate     — create a Flouci payment for an invoice
 * GET  /hotel/payments/{id}/verify  — verify payment status after redirect
 */
class PaymentController extends Controller
{
    public function __construct(private readonly FlouciService $flouci) {}

    /**
     * Initiate a Flouci payment session for a pending invoice.
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'invoice_id' => ['required', 'uuid'],
        ]);

        $invoice = $this->scopedInvoices($request)
            ->where('id', $request->invoice_id)
            ->whereIn('status', ['sent', 'overdue'])
            ->firstOrFail();

        if ($invoice->isPaid()) {
            return response()->json([
                'errors' => [['code' => 'ALREADY_PAID', 'message' => 'Cette facture est déjà réglée.', 'field' => null]],
            ], 422);
        }

        // Check if a valid pending payment already exists
        $existing = Payment::where('invoice_id', $invoice->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return response()->json([
                'data' => [
                    'payment_id'  => $existing->id,
                    'payment_url' => $existing->payment_url,
                    'expires_at'  => $existing->expires_at,
                    'amount'      => $existing->amount,
                    'currency'    => $existing->currency,
                ],
            ]);
        }

        // Convert TND to millimes (Flouci expects integer millimes)
        $amountMillimes = (int) round((float) $invoice->total_amount * 1000);
        $trackingId     = Str::uuid()->toString();

        try {
            $result = $this->flouci->createPayment($amountMillimes, $trackingId);
        } catch (\RuntimeException $e) {
            return response()->json([
                'errors' => [['code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'Service de paiement indisponible. Réessayez dans quelques instants.', 'field' => null]],
            ], 502);
        }

        $payment = Payment::create([
            'invoice_id'           => $invoice->id,
            'hotel_id'             => $invoice->hotel_id,
            'provider'             => 'flouci',
            'provider_payment_id'  => $result['payment_id'],
            'provider_tracking_id' => $trackingId,
            'status'               => 'pending',
            'amount'               => $invoice->total_amount,
            'currency'             => $invoice->currency,
            'payment_url'          => $result['payment_url'],
            'expires_at'           => now()->addSeconds((int) config('flouci.timeout_secs', 900)),
        ]);

        AuditLogger::log('payment.initiated', $invoice, actor: $request->user());

        return response()->json([
            'data' => [
                'payment_id'  => $payment->id,
                'payment_url' => $result['payment_url'],
                'expires_at'  => $payment->expires_at,
                'amount'      => $invoice->total_amount,
                'currency'    => $invoice->currency,
            ],
        ], 201);
    }

    /**
     * Verify a payment after the user returns from Flouci's hosted page.
     * Frontend calls this after landing on success_url / fail_url.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $invoiceIds = $this->scopedInvoices($request)->pluck('id');
        $payment    = Payment::where('id', $id)->whereIn('invoice_id', $invoiceIds)->firstOrFail();

        // Already resolved — return cached status
        if (in_array($payment->status, ['completed', 'failed', 'expired'])) {
            return response()->json(['data' => [
                'status'     => $payment->status,
                'payment_id' => $payment->id,
            ]]);
        }

        // Check expiry
        if ($payment->isExpired()) {
            $payment->update(['status' => 'expired']);
            return response()->json(['data' => ['status' => 'expired', 'payment_id' => $payment->id]]);
        }

        try {
            $result = $this->flouci->verifyPayment($payment->provider_payment_id);
        } catch (\RuntimeException) {
            return response()->json([
                'errors' => [['code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'Impossible de vérifier le paiement.', 'field' => null]],
            ], 502);
        }

        if ($result['success']) {
            $payment->update([
                'status'            => 'completed',
                'completed_at'      => now(),
                'provider_response' => $result['raw'],
            ]);

            // Mark invoice as paid + record payment reference
            $payment->invoice()->update([
                'status'            => 'paid',
                'paid_at'           => now(),
                'payment_method'    => 'flouci',
                'payment_reference' => $payment->provider_payment_id,
            ]);

            AuditLogger::log('payment.completed', $payment->invoice, actor: $request->user());

            // Réactivation/prolongation automatique de l'abonnement + email
            // « Paiement reçu » — même circuit que le virement validé.
            app(\App\Services\Billing\BillingService::class)
                ->handleInvoicePaid($payment->invoice()->first(), $request->user()?->id);
        } else {
            $payment->update([
                'status'            => 'failed',
                'provider_response' => $result['raw'],
            ]);
        }

        return response()->json([
            'data' => [
                'status'     => $payment->status,
                'payment_id' => $payment->id,
            ],
        ]);
    }

    /**
     * Hébergeur declares a bank transfer for a pending invoice — creates a
     * Payment(provider=virement, status=pending) awaiting admin validation.
     */
    public function declareVirement(Request $request): JsonResponse
    {
        $v = $request->validate([
            'invoice_id' => ['required', 'uuid'],
            'reference'  => ['required', 'string', 'max:100'],
            'date'       => ['required', 'date', 'before_or_equal:today'],
        ]);

        $user    = $request->user();
        $invoice = $this->scopedInvoices($request)->findOrFail($v['invoice_id']);

        if ($invoice->isPaid()) {
            return response()->json([
                'errors' => [['code' => 'ALREADY_PAID', 'message' => 'Cette facture est déjà réglée.', 'field' => null]],
            ], 422);
        }

        $existing = Payment::where('invoice_id', $invoice->id)->where('status', 'pending')->first();
        if ($existing) {
            return response()->json([
                'errors' => [['code' => 'ALREADY_DECLARED', 'message' => 'Un virement est déjà déclaré pour cette facture, en attente de validation.', 'field' => null]],
            ], 422);
        }

        $payment = Payment::create([
            'invoice_id'         => $invoice->id,
            'hotel_id'           => $invoice->hotel_id,
            'provider'           => 'virement',
            'declared_reference' => $v['reference'],
            'declared_at'        => $v['date'],
            'status'             => 'pending',
            'amount'             => $invoice->total_amount,
            'currency'           => $invoice->currency,
        ]);

        AuditLogger::log('payment.virement_declared', $invoice, newValues: ['reference' => $v['reference']], actor: $user);

        return response()->json(['data' => ['id' => $payment->id, 'status' => $payment->status]], 201);
    }

    /**
     * Invoices belonging to the caller's own organization (or hotel, legacy) —
     * never another tenant's. Admin-created invoices are org-level
     * (hotel_id null), so this must not scope by hotel_id directly.
     */
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
