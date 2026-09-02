<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsappBillableMessage;
use App\Models\WhatsappMessageCost;
use App\Services\Whatsapp\WhatsappCostRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoints admin de lecture des couts Meta / WhatsApp (messages template
 * livres : fiches de police en utility, codes de connexion en authentication).
 *
 * Meme forme que AiCostController, delibrement : memes periodes, meme
 * exposition de `hotel_id` sous le nom `establishment_id`, memes montants en
 * chaine decimale USD. Deux vues de couts qui se lisent differemment seraient
 * deux vues qu'on ne peut pas comparer.
 *
 * Une seule difference de fond, et elle est structurelle : les montants ont
 * DEUX SOURCES. `meta` porte les montants reels du compte quand la commande
 * whatsapp:sync-costs a pu les lire, `estimate` porte le calcul local sinon.
 * Chaque reponse dit laquelle elle sert — un cout dont on ignore la provenance
 * ne vaut rien pour une decision de marge.
 *
 * SQL : les fragments de date utilisent la syntaxe PostgreSQL.
 */
class MetaCostController extends Controller
{
    /** Ordre d'affichage. Toujours les quatre, meme a zero (cf. AiCostController). */
    private const CATEGORIES = WhatsappBillableMessage::CATEGORIES;

    public function __construct(private WhatsappCostRecorder $costs) {}

    /** GET /admin/meta-costs/summary?period=current_month|last_month|last_30d */
    public function summary(Request $request): JsonResponse
    {
        $period = $this->period($request);
        [$from, $to] = $this->range($period);

        $source = $this->costs->preferredSource($from, $to);

        $rows = WhatsappMessageCost::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('source', $source)
            ->selectRaw('category, COALESCE(SUM(messages),0) as n, COALESCE(SUM(cost_usd),0) as cost')
            ->groupBy('category')
            ->get();

        $categories = [];
        $totalCost = 0.0;
        $totalMessages = 0;
        foreach (self::CATEGORIES as $category) {
            $row = $rows->firstWhere('category', $category);
            $messages = (int) ($row->n ?? 0);
            $cost = (float) ($row->cost ?? 0);
            $totalCost += $cost;
            $totalMessages += $messages;

            $categories[] = [
                'category' => $category,
                'messages' => $messages,
                'cost_usd' => number_format($cost, 6, '.', ''),
                'unit_price_usd' => number_format($this->costs->rateFor($category), 6, '.', ''),
            ];
        }

        // Mois precedent, pour la comparaison demandee par l'ecran. Toujours
        // servi dans la meme source que la periode courante : comparer un mois
        // reel a un mois estime donnerait une variation qui ne mesure que le
        // changement de source.
        [$prevFrom, $prevTo] = $this->range('last_month');
        $previous = (float) WhatsappMessageCost::query()
            ->whereBetween('date', [$prevFrom->toDateString(), $prevTo->toDateString()])
            ->where('source', $source)
            ->sum('cost_usd');

        $syncedAt = $this->costs->lastMetaSyncAt();

        return response()->json([
            'data' => [
                'period' => $period,
                'source' => $source,
                'total_cost_usd' => number_format($totalCost, 6, '.', ''),
                'total_messages' => $totalMessages,
                'avg_cost_per_message_usd' => number_format($totalMessages > 0 ? $totalCost / $totalMessages : 0, 6, '.', ''),
                'previous_month_cost_usd' => number_format($previous, 6, '.', ''),
                'categories' => $categories,
                // Conversion d'AFFICHAGE : le stockage reste en USD, comme la
                // facture Meta. Le taux accompagne le montant pour que l'ecran
                // n'ait pas a le supposer.
                'usd_to_tnd' => number_format($this->costs->usdToTnd(), 4, '.', ''),
                'total_cost_tnd' => number_format($totalCost * $this->costs->usdToTnd(), 3, '.', ''),
                'last_meta_sync_at' => $syncedAt?->toIso8601String(),
                'pricing_configured' => $this->pricingConfigured(),
            ],
        ]);
    }

    /**
     * GET /admin/meta-costs/by-establishment?period=...&category=all|utility|authentication|marketing|service
     *
     * TOUJOURS servi depuis l'estimation locale, meme quand les montants reels
     * existent : Meta facture un compte, pas nos clients, et ses analytics ne
     * portent aucune ventilation par etablissement. C'est la seule vue ou la
     * source ne suit pas la preference — et c'est assume, parce que le cout
     * WhatsApp par client est une donnee de marge qu'aucune autre source ne
     * peut produire.
     */
    public function byEstablishment(Request $request): JsonResponse
    {
        $period = $this->period($request);
        [$from, $to] = $this->range($period);
        $category = $this->category($request);

        $rows = WhatsappMessageCost::query()
            ->from('whatsapp_message_costs as c')
            ->leftJoin('hotels as h', 'h.id', '=', 'c.hotel_id')
            ->whereBetween('c.date', [$from->toDateString(), $to->toDateString()])
            ->where('c.source', WhatsappMessageCost::SOURCE_ESTIMATE)
            ->when($category !== 'all', fn ($q) => $q->where('c.category', $category))
            ->selectRaw("
                c.hotel_id,
                h.name as establishment_name,
                COALESCE(SUM(CASE WHEN c.category = 'utility'        THEN c.messages ELSE 0 END),0) as utility_messages,
                COALESCE(SUM(CASE WHEN c.category = 'authentication' THEN c.messages ELSE 0 END),0) as authentication_messages,
                COALESCE(SUM(CASE WHEN c.category = 'marketing'      THEN c.messages ELSE 0 END),0) as marketing_messages,
                COALESCE(SUM(CASE WHEN c.category = 'service'        THEN c.messages ELSE 0 END),0) as service_messages,
                COALESCE(SUM(c.messages),0) as messages,
                COALESCE(SUM(c.cost_usd),0)  as cost_usd
            ")
            ->groupBy('c.hotel_id', 'h.name')
            ->orderByDesc('cost_usd')
            ->get()
            ->map(function ($r) {
                $messages = (int) $r->messages;
                $cost = (float) $r->cost_usd;

                return [
                    // Nul = hors etablissement : ce sont les codes de connexion
                    // des agents, portes par le compte autorite.
                    'establishment_id' => $r->hotel_id !== null ? (string) $r->hotel_id : null,
                    'establishment_name' => $r->establishment_name,
                    'utility_messages' => (int) $r->utility_messages,
                    'authentication_messages' => (int) $r->authentication_messages,
                    'marketing_messages' => (int) $r->marketing_messages,
                    'service_messages' => (int) $r->service_messages,
                    'messages' => $messages,
                    'cost_usd' => number_format($cost, 6, '.', ''),
                    'avg_cost_per_message_usd' => number_format($messages > 0 ? $cost / $messages : 0, 6, '.', ''),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    /** GET /admin/meta-costs/daily?days=30&category=all|utility|authentication|marketing|service */
    public function daily(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', 30)));
        $category = $this->category($request);
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $source = $this->costs->preferredSource($from, $to);

        $rows = WhatsappMessageCost::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('source', $source)
            ->when($category !== 'all', fn ($q) => $q->where('category', $category))
            ->selectRaw('date as d, category, COALESCE(SUM(messages),0) as n, COALESCE(SUM(cost_usd),0) as cost')
            ->groupBy('date', 'category')
            ->get();

        // Serie continue jour par jour (0 pour les jours sans donnee), comme
        // AiCostController : un graphe a trous se lit comme une panne.
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - 1 - $i)->toDateString();
            $slice = $rows->filter(fn ($r) => Carbon::parse($r->d)->toDateString() === $date);

            $point = ['date' => $date];
            $dayCost = 0.0;
            $dayMessages = 0;
            foreach (self::CATEGORIES as $c) {
                $hit = $slice->firstWhere('category', $c);
                $cost = (float) ($hit->cost ?? 0);
                $n = (int) ($hit->n ?? 0);
                $dayCost += $cost;
                $dayMessages += $n;
                $point[$c.'_cost_usd'] = number_format($cost, 6, '.', '');
                $point[$c.'_count'] = $n;
            }
            $point['total_cost_usd'] = number_format($dayCost, 6, '.', '');
            $point['total_count'] = $dayMessages;

            $out[] = $point;
        }

        return response()->json(['data' => ['source' => $source, 'series' => $out]]);
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    private function period(Request $request): string
    {
        $p = (string) $request->query('period', 'current_month');

        return in_array($p, ['current_month', 'last_month', 'last_30d'], true) ? $p : 'current_month';
    }

    private function category(Request $request): string
    {
        $c = (string) $request->query('category', 'all');

        return in_array($c, array_merge(['all'], self::CATEGORIES), true) ? $c : 'all';
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function range(string $period): array
    {
        return match ($period) {
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_30d' => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    /**
     * Vrai seulement si les deux categories que nous envoyons reellement ont un
     * tarif non nul.
     *
     * Marketing et service sont exclus du test a dessein : nous n'envoyons pas
     * de marketing, et le tarif service est LEGITIMEMENT a 0 jusqu'au
     * 01/10/2026. Les inclure ferait afficher « tarifs non configures » en
     * permanence, et un avertissement permanent n'avertit plus de rien.
     */
    private function pricingConfigured(): bool
    {
        return $this->costs->rateFor(WhatsappBillableMessage::CATEGORY_UTILITY) > 0
            && $this->costs->rateFor(WhatsappBillableMessage::CATEGORY_AUTHENTICATION) > 0;
    }
}
