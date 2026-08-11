<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\PaymentGatewayResolver;
use App\Services\Payment\PaymentSettlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Hotel payment flow via la passerelle en ligne du moment (Konnect ; Flouci
 * pour les paiements antérieurs à la bascule).
 *
 * POST /hotel/payments/initiate     — ouvre une session de paiement pour une facture
 * GET  /hotel/payments/{id}/verify  — constate le sort du paiement au retour
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentSettlement $settlement,
    ) {}

    /**
     * Ouvre une session de paiement en ligne pour une facture en attente.
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

        // Un canal fermé se dit fermé — il ne se casse pas.
        //
        // Sans ce garde, une passerelle non configurée partait quand même
        // appeler le prestataire et rendait « Service de paiement
        // indisponible, réessayez dans quelques instants » : une panne
        // définitive annoncée comme passagère, sans indiquer au client que sa
        // facture est réglable par virement. L'API doit dire la même chose que
        // l'écran, et c'est elle qui fait autorité.
        //
        // Le garde interroge le CANAL, pas un prestataire nommé : le jour où
        // celui-ci change, il n'y a rien à retoucher ici.
        $gateway  = $this->gateways->forNewPayment();
        $provider = \App\Models\PlatformSetting::get()->onlineProvider();

        if ($gateway === null || $provider === null) {
            return response()->json([
                'data'   => null,
                'errors' => [[
                    'code'    => 'PAYMENT_METHOD_UNAVAILABLE',
                    'message' => "Le paiement en ligne n'est pas ouvert. Réglez cette facture par virement depuis l'écran Factures.",
                    'field'   => null,
                ]],
            ], 503);
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

        // Convert TND to millimes (les deux passerelles comptent en millimes)
        $amountMillimes = (int) round((float) $invoice->total_amount * 1000);
        $trackingId     = Str::uuid()->toString();
        $user           = $request->user();

        try {
            $result = $gateway->createPayment($amountMillimes, $trackingId, [
                // Préremplissage de la page hébergée : le client ne ressaisit
                // pas ce que nous connaissons déjà. `order_id` porte le numéro
                // de facture — c'est par lui qu'on rapproche un encaissement
                // dans le tableau de bord du prestataire.
                'order_id'    => $invoice->invoice_number,
                'description' => 'Qayed — facture '.$invoice->invoice_number,
                'first_name'  => $user?->first_name,
                'last_name'   => $user?->last_name,
                'email'       => $user?->email,
                'phone'       => $user?->phone,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'errors' => [['code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'Service de paiement indisponible. Réessayez dans quelques instants.', 'field' => null]],
            ], 502);
        }

        $payment = Payment::create([
            'invoice_id'           => $invoice->id,
            'hotel_id'             => $invoice->hotel_id,
            'provider'             => $provider,
            'provider_payment_id'  => $result['payment_id'],
            'provider_tracking_id' => $trackingId,
            'status'               => 'pending',
            'amount'               => $invoice->total_amount,
            'currency'             => $invoice->currency,
            'payment_url'          => $result['payment_url'],
            'expires_at'           => now()->addSeconds($this->lifespanSeconds($provider)),
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
     * Constate le sort d'un paiement au retour de la page hébergée.
     * Le front appelle cette route en atterrissant sur success_url / fail_url.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $invoiceIds = $this->scopedInvoices($request)->pluck('id');

        // Deux identifiants ouvrent la même vérification, à dessein.
        //
        // Le prestataire ne connaît que le SIEN : il renvoie le client sur
        // `successUrl?payment_ref=<sa référence>` (Flouci : `?payment_id=`),
        // jamais avec le nôtre. Ne chercher que sur notre clé primaire faisait
        // échouer tous les retours de paiement — la facture restait impayée,
        // partait en relance, puis suspendait un client qui avait pourtant
        // réglé.
        //
        // Le test `Str::isUuid` n'est pas cosmétique : `payments.id` est une
        // colonne uuid PostgreSQL, et lui comparer une chaîne quelconque lève
        // une erreur SQL (22P02) rendue en 500 au lieu d'un 404.
        //
        // Le périmètre reste celui de l'appelant (`$invoiceIds`) : un
        // identifiant de fournisseur n'est pas un secret, il ne donne accès à
        // rien qui ne soit déjà au tenant.
        $payment = Payment::whereIn('invoice_id', $invoiceIds)
            ->where(function ($q) use ($id) {
                $q->where('provider_payment_id', $id);
                if (Str::isUuid($id)) {
                    $q->orWhere('id', $id);
                }
            })
            ->firstOrFail();

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

        $gateway = $this->gateways->forPayment($payment);

        // Un paiement hors ligne (virement) n'a personne à interroger : on rend
        // son état tel qu'il est plutôt que d'appeler une passerelle qui ne le
        // connaît pas. Même chose pour un prestataire retiré du service.
        if ($gateway === null) {
            return response()->json(['data' => [
                'status'     => $payment->status,
                'payment_id' => $payment->id,
            ]]);
        }

        try {
            $result = $gateway->verifyPayment($payment->provider_payment_id);
        } catch (\RuntimeException) {
            return response()->json([
                'errors' => [['code' => 'PAYMENT_GATEWAY_ERROR', 'message' => 'Impossible de vérifier le paiement.', 'field' => null]],
            ], 502);
        }

        // L'encaissement lui-même vit dans PaymentSettlement : le webhook du
        // prestataire emprunte EXACTEMENT le même chemin, sans copie.
        $status = $this->settlement->apply($payment, $result, $request->user());

        return response()->json([
            'data' => [
                'status'     => $status,
                'payment_id' => $payment->id,
            ],
        ]);
    }

    /**
     * Durée de validité du lien de paiement, selon le prestataire.
     * Konnect compte en minutes, Flouci comptait en secondes.
     */
    private function lifespanSeconds(string $provider): int
    {
        return $provider === 'konnect'
            ? ((int) config('konnect.lifespan_minutes', 15)) * 60
            : (int) config('flouci.timeout_secs', 900);
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

        // Alerte admin plateforme : virement à valider (non bloquant).
        $invoice->loadMissing('hotel');
        \App\Services\Notifications\AdminNotifier::notify(
            'Virement déclaré à valider — '.($invoice->hotel?->name ?? 'facture '.$invoice->invoice_number),
            '<h2 style="margin:0 0 12px">Paiement par virement déclaré</h2>'
            .'<p style="color:#b7791f;font-weight:600">À valider dans Admin → Paiements.</p>'
            .'<table style="font-size:14px;border-collapse:collapse">'
            .\App\Services\Notifications\AdminNotifier::row('Établissement', $invoice->hotel?->name)
            .\App\Services\Notifications\AdminNotifier::row('Facture', $invoice->invoice_number)
            .\App\Services\Notifications\AdminNotifier::row('Montant', number_format((float) $payment->amount, 3, ',', ' ').' '.$payment->currency)
            .\App\Services\Notifications\AdminNotifier::row('Référence déclarée', $v['reference'])
            .\App\Services\Notifications\AdminNotifier::row('Date déclarée', (string) $v['date'])
            .'</table>',
        );

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
