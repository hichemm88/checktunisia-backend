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
                // Détail du prix : base + suppléments par établissement (ou
                // prix négocié). Même formule sur toutes les surfaces.
                'pricing'        => \App\Services\Subscription\PlanPricing::detail($sub),
                // Quota mensuel de check-ins (grille V2) + dépassement en cours.
                'quota'          => $org ? \App\Services\Subscription\CheckinQuota::status($org) : null,
                // Grandfathering : le compte conserve les conditions de
                // l'ancienne grille (affichage + demande d'upgrade).
                'is_legacy_plan' => (bool) $sub->is_legacy_plan,
            ],
        ]);
    }

    /**
     * Demande d'upgrade vers un plan supérieur de la grille publique.
     *
     * Le billing en place (Flouci par facture + renouvellements) ne gère ni
     * le changement de plan self-service ni le prorata en cours de cycle :
     * la demande notifie l'admin plateforme, qui applique le changement
     * depuis la fiche hébergeur — effet au cycle suivant (ou immédiat si
     * l'admin le décide). Choix documenté (chantier grille V2).
     */
    public function requestUpgrade(Request $request): JsonResponse
    {
        $user = $request->user();
        $org  = $user->organization;
        if (!$org) {
            return response()->json(['errors' => [['code' => 'NO_ORGANIZATION', 'message' => "Compte sans organisation — contactez support@qayed.tn."]]], 422);
        }

        $v = $request->validate([
            'plan_slug' => ['required', 'string', 'exists:subscription_plans,slug'],
            'message'   => ['nullable', 'string', 'max:500'],
        ]);

        $target = \App\Models\SubscriptionPlan::where('slug', $v['plan_slug'])
            ->where('is_active', true)
            ->where('is_public', true)
            ->first();
        if (!$target) {
            return response()->json(['errors' => [['code' => 'PLAN_NOT_AVAILABLE', 'message' => "Ce plan n'est pas souscriptible."]]], 422);
        }

        $sub = $org->activeSubscription()->with('plan')->first();
        if ($sub && $sub->plan_id === $target->id && !$sub->is_legacy_plan) {
            return response()->json(['errors' => [['code' => 'ALREADY_ON_PLAN', 'message' => 'Votre compte est déjà sur ce plan.']]], 422);
        }

        if ($sub) {
            \App\Models\SubscriptionEvent::create([
                'subscription_id' => $sub->id,
                'event_type'      => 'upgrade_requested',
                'previous_status' => $sub->status,
                'new_status'      => $sub->status,
                'notes'           => "Upgrade demandé vers {$target->name}".(isset($v['message']) && $v['message'] !== '' ? ' — '.$v['message'] : ''),
                'performed_by'    => $user->id,
                'created_at'      => now(),
            ]);
        }

        \App\Services\Audit\AuditLogger::log('subscription.upgrade_requested', $sub ?? $org, newValues: [
            'organization_id' => $org->id,
            'target_plan'     => $target->slug,
        ]);

        \App\Services\Notifications\AdminNotifier::notify(
            "Demande d'upgrade : {$org->name}",
            '<h2 style="margin:0 0 12px">Demande d\'upgrade</h2><table style="font-size:14px;border-collapse:collapse">'
            .\App\Services\Notifications\AdminNotifier::row('Hébergeur', $org->name)
            .\App\Services\Notifications\AdminNotifier::row('Email', $org->contact_email)
            .\App\Services\Notifications\AdminNotifier::row('Plan actuel', $sub?->plan?->name.($sub?->is_legacy_plan ? ' (legacy)' : ''))
            .\App\Services\Notifications\AdminNotifier::row('Plan demandé', $target->name.' — '.number_format((float) $target->price_monthly, 0).' TND/mois')
            .\App\Services\Notifications\AdminNotifier::row('Message', $v['message'] ?? null)
            .'</table><p style="margin-top:16px">À appliquer depuis la fiche hébergeur (Admin &gt; Hébergeurs).</p>',
        );

        return response()->json(['data' => [
            'status'      => 'requested',
            'target_plan' => $target->only(['id', 'name', 'slug', 'price_monthly']),
        ]]);
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
