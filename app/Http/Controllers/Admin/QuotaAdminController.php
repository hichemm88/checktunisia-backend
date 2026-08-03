<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OverageCharge;
use App\Services\Subscription\CheckinQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pilotage commercial des quotas de check-ins (grille V2) — l'outil
 * d'upsell : comptes à ≥80 % de leur quota ce mois-ci et comptes en
 * dépassement, triés par volume. Les comptes illimités n'apparaissent pas.
 */
class QuotaAdminController extends Controller
{
    /**
     * Vue globale « Quotas » : consommation du mois en cours de chaque
     * organisation à quota fini. ?filter=warning (≥80 %) | overage (>100 %).
     */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');

        $rows = [];
        Organization::whereHas('subscriptions', fn ($q) => $q->whereIn('status', ['active', 'trial']))
            ->with('activeSubscription.plan')
            ->chunkById(100, function ($orgs) use (&$rows) {
                foreach ($orgs as $org) {
                    $status = CheckinQuota::status($org);
                    if ($status['unlimited']) {
                        continue;
                    }
                    $sub = $org->activeSubscription;
                    $rows[] = array_merge($status, [
                        'organization_id'   => $org->id,
                        'name'              => $org->name,
                        'contact_email'     => $org->contact_email,
                        'plan'              => $sub?->plan?->name,
                        'plan_slug'         => $sub?->plan?->slug,
                        'billing_cycle'     => $sub?->billing_cycle,
                        'upsell_flagged_at' => $org->upsell_flagged_at,
                    ]);
                }
            });

        $rows = array_values(array_filter($rows, fn ($r) => match ($filter) {
            'warning' => $r['percent'] !== null && $r['percent'] >= 80 && $r['used'] <= $r['quota'],
            'overage' => $r['used'] > $r['quota'],
            default   => true,
        }));

        usort($rows, fn ($a, $b) => $b['used'] <=> $a['used']);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month'         => now()->format('Y-m'),
                'total'         => count($rows),
                'warning_count' => count(array_filter($rows, fn ($r) => $r['percent'] >= 80 && $r['used'] <= $r['quota'])),
                'overage_count' => count(array_filter($rows, fn ($r) => $r['used'] > $r['quota'])),
            ],
        ]);
    }

    /** Dépassements clôturés (overage_charges), filtrables par mois/statut — historique + suivi de facturation. */
    public function overages(Request $request): JsonResponse
    {
        $query = OverageCharge::with(['organization', 'subscription.plan', 'invoice'])
            ->orderByDesc('period')->orderByDesc('amount');

        if ($request->filled('month'))  $query->where('period', Carbon::createFromFormat('Y-m', $request->month)->startOfMonth()->toDateString());
        if ($request->filled('status')) $query->where('status', $request->status);

        $charges = $query->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => collect($charges->items())->map(fn (OverageCharge $c) => [
                'id'             => $c->id,
                'period'         => $c->period->format('Y-m'),
                'organization'   => $c->organization ? ['id' => $c->organization->id, 'name' => $c->organization->name] : null,
                'plan'           => $c->subscription?->plan?->name,
                'checkins_count' => $c->checkins_count,
                'quota'          => $c->quota,
                'overage_count'  => $c->overage_count,
                'bundle_size'    => $c->bundle_size,
                'bundle_count'   => $c->bundle_count,
                'unit_price'     => $c->unit_price,
                'amount'         => $c->amount,
                'status'         => $c->status,
                'invoice_number' => $c->invoice?->invoice_number,
                'invoice_status' => $c->invoice?->status,
            ]),
            'meta' => ['total' => $charges->total(), 'current_page' => $charges->currentPage(), 'per_page' => $charges->perPage()],
        ]);
    }

    /** Export CSV des dépassements d'un mois — repli manuel si la facturation automatique doit être vérifiée. */
    public function export(Request $request)
    {
        $month  = $request->query('month', now()->subMonthNoOverflow()->format('Y-m'));
        $period = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();

        $charges = OverageCharge::with(['organization', 'subscription.plan', 'invoice'])
            ->where('period', $period)
            ->orderByDesc('amount')
            ->get();

        return response()->streamDownload(function () use ($charges) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 pour Excel.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Hébergeur', 'Email', 'Plan', 'Check-ins', 'Quota', 'Dépassement', 'Tranches', 'Prix tranche (TND)', 'Montant (TND)', 'Statut', 'Facture'], ';');
            foreach ($charges as $c) {
                fputcsv($out, [
                    $c->organization?->name,
                    $c->organization?->contact_email,
                    $c->subscription?->plan?->name,
                    $c->checkins_count,
                    $c->quota,
                    $c->overage_count,
                    $c->bundle_count,
                    number_format((float) $c->unit_price, 3, '.', ''),
                    number_format((float) $c->amount, 3, '.', ''),
                    $c->status,
                    $c->invoice?->invoice_number,
                ], ';');
            }
            fclose($out);
        }, "depassements-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
