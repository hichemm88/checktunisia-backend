<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\WatchlistHit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel  = app('tenant');
        $today  = today();

        // ── Today metrics ─────────────────────────────────────────────────────
        $arrivalsExpected = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        $arrivalsDone = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->where('status', 'active')
            ->count();

        $activeCheckIns = CheckIn::where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->count();

        // Headcount (adults + children) across active stays — a single check-in
        // can house a whole family, so counting check-in rows undercounts the
        // real number of people currently on-site.
        $currentlyPresent = (int) CheckIn::where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->selectRaw('COALESCE(SUM(adults_count + children_count), 0) as total')
            ->value('total');

        $departuresToday = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('expected_check_out_date', $today)
            ->where('status', 'active')
            ->count();

        // Départs de demain — pour préparer les checkouts à l'avance (stat conformité).
        $departuresTomorrow = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('expected_check_out_date', $today->copy()->addDay())
            ->where('status', 'active')
            ->count();

        // Brouillons non soumis — une fiche en brouillon est une fiche non transmise
        // aux autorités : stat prioritaire de conformité (toutes dates confondues).
        $draftsPending = CheckIn::where('hotel_id', $hotel->id)
            ->where('status', 'draft')
            ->count();

        // ── Month total ───────────────────────────────────────────────────────
        $monthTotal = CheckIn::where('hotel_id', $hotel->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        // ── Occupancy rate ────────────────────────────────────────────────────
        // Rooms occupied ÷ total rooms — headcount is irrelevant here since a
        // room can hold several guests but only counts as one occupied unit.
        $occupancyRate = $hotel->room_count > 0
            ? round(($activeCheckIns / $hotel->room_count) * 100)
            : 0;

        // ── Weekly trend (last 7 days) ────────────────────────────────────────
        $weeklyRaw = CheckIn::where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', '>=', $today->copy()->subDays(6))
            ->whereDate('check_in_date', '<=', $today)
            ->select(DB::raw('DATE(check_in_date) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->pluck('count', 'date');

        $weekly = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $weekly[] = [
                'date'  => $d->format('Y-m-d'),
                'label' => $d->locale('fr')->isoFormat('ddd D'),
                'count' => (int) ($weeklyRaw[$d->format('Y-m-d')] ?? 0),
            ];
        }

        // ── Occupancy — 7-day window (j-4 → j+2) ──────────────────────────────
        // Fenêtre courante = [today-4, today+2] ; la navigation semaine par semaine
        // (endpoint occupancy) réutilise le même calcul, décalé de 7 jours.
        $occupancy = $this->buildOccupancy($hotel, $today->copy()->subDays(4), $today->copy()->addDays(2), $activeCheckIns);

        // ── Document expiry alerts (next 30 days) ─────────────────────────────
        $expiryAlerts = TravelDocument::join('guests', 'travel_documents.guest_id', '=', 'guests.id')
            ->join('check_in_guests', 'guests.id', '=', 'check_in_guests.guest_id')
            ->join('check_ins', 'check_in_guests.check_in_id', '=', 'check_ins.id')
            ->where('check_ins.hotel_id', $hotel->id)
            ->where('check_ins.status', 'active')
            // Plain query-builder joins bypass Eloquent's SoftDeletingScope, so a deleted
            // check-in (or guest) would otherwise keep surfacing here forever.
            ->whereNull('check_ins.deleted_at')
            ->whereNull('guests.deleted_at')
            ->whereNotNull('travel_documents.expiry_date')
            ->whereDate('travel_documents.expiry_date', '>=', $today)
            ->whereDate('travel_documents.expiry_date', '<=', $today->copy()->addDays(30))
            ->orderBy('travel_documents.expiry_date')
            ->limit(5)
            ->select([
                'guests.first_name', 'guests.last_name',
                'travel_documents.document_number', 'travel_documents.expiry_date',
                'check_ins.id as check_in_id', 'check_ins.reference',
            ])
            ->get()
            ->map(fn($row) => [
                'guest_name'      => trim("{$row->first_name} {$row->last_name}"),
                'document_number' => $row->document_number,
                'expiry_date'     => $row->expiry_date,
                'days_until_expiry' => (int) Carbon::parse($row->expiry_date)->diffInDays($today, false) * -1,
                'check_in_id'     => $row->check_in_id,
                'reference'       => $row->reference,
            ]);

        // ── Arrivals today — actionable list (§4) ─────────────────────────────
        // BUGFIX : la liste ne comptait que les brouillons, alors qu'une fiche déjà
        // ACTIVE dont l'arrivée est aujourd'hui est bel et bien une arrivée du jour
        // (le compteur du hero, lui, comptait déjà tous les statuts hors annulé/no-show,
        // d'où le décalage « 1 arrivée » au-dessus mais « 0 » dans la liste). On aligne
        // donc la liste sur le même filtre que $arrivalsExpected. Le champ `status`
        // permet au front de router : brouillon → reprise du check-in, actif → fiche.
        $arrivalsList = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->whereDate('check_in_date', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => [
                'id'                      => $c->id,
                'reference'               => $c->reference,
                'status'                  => $c->status,
                'guest_name'              => $c->guests->first()?->full_name,
                'booking_reference'       => $c->booking_reference,
                'room'                    => $c->room?->number,
                'room_id'                 => $c->room_id,
                'check_in_date'           => $c->check_in_date,
                'expected_check_out_date' => $c->expected_check_out_date,
                'adults_count'            => $c->adults_count,
                'children_count'          => $c->children_count,
            ]);

        // ── Departures today (active stays leaving today) — actionable (§4) ───
        $departuresList = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->whereDate('expected_check_out_date', $today)
            ->where('status', 'active')
            ->orderBy('expected_check_out_date')
            ->get()
            ->map(fn ($c) => [
                'id'          => $c->id,
                'reference'   => $c->reference,
                'guest_name'  => $c->guests->first()?->full_name,
                'room'        => $c->room?->number,
            ]);

        // ── Present guests (active stays) — for the tappable occupancy ring (§4) ─
        $presentGuests = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->where('status', 'active')
            ->get()
            ->map(fn ($c) => [
                'id'         => $c->id,
                'guest_name' => $c->guests->first()?->full_name,
                'room'       => $c->room?->number,
            ]);

        // ── Per-property recap for the multi-establishment switcher (§5) ──────
        // Shown on the home screen for any user attached to more than one property, both roles.
        $accessible = $request->user()->hotels()->orderBy('hotels.created_at')->get();
        $propertiesSummary = $accessible->map(function ($h) use ($hotel) {
            $active  = CheckIn::where('hotel_id', $h->id)->where('status', 'active')->count();
            $present = (int) CheckIn::where('hotel_id', $h->id)->where('status', 'active')
                ->selectRaw('COALESCE(SUM(adults_count + children_count), 0) as t')->value('t');
            return [
                'id'             => $h->id,
                'name'           => $h->name,
                'occupancy_rate' => $h->room_count > 0 ? (int) round($active / $h->room_count * 100) : 0,
                'present'        => $present,
                'is_active'      => $h->id === $hotel->id,
            ];
        });

        // ── Other establishments the user works at (arrivals/departures today) — §4 ─
        $otherHotelIds = $accessible->where('id', '!=', $hotel->id)->pluck('id');

        $otherArrivals = $otherHotelIds->isEmpty() ? 0 : CheckIn::whereIn('hotel_id', $otherHotelIds)
            ->whereDate('check_in_date', $today)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();
        $otherDepartures = $otherHotelIds->isEmpty() ? 0 : CheckIn::whereIn('hotel_id', $otherHotelIds)
            ->whereDate('expected_check_out_date', $today)
            ->where('status', 'active')
            ->count();

        // ── Recent check-ins (today) ──────────────────────────────────────────
        $recentCheckIns = CheckIn::with(['room', 'guests'])
            ->where('hotel_id', $hotel->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($c) => [
                'id'            => $c->id,
                'reference'     => $c->reference,
                'room'          => $c->room?->number,
                'status'        => $c->status,
                // Préférer le voyageur marqué principal, sinon retomber sur le premier
                // (même logique que l'Historique). Sans ce fallback, une fiche dont
                // aucun voyageur n'a is_primary=true s'affichait « Sans nom » à
                // l'Accueil alors que l'Historique montrait bien le nom.
                'primary_guest' => ($c->guests->firstWhere('pivot.is_primary', true) ?? $c->guests->first())?->full_name,
                'check_in_date' => $c->check_in_date,
            ]);

        // ── Aperçu du mois (analytique, Manager) ──────────────────────────────
        // Non actionnable : reste discret sous le graphe, jamais dans la grille
        // de stats de conformité.
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd   = $today->copy()->endOfMonth();

        // Top nationalité — parmi les séjours arrivant ce mois (hors annulé/no-show).
        $topNat = DB::table('check_in_guests')
            ->join('check_ins', 'check_in_guests.check_in_id', '=', 'check_ins.id')
            ->join('guests', 'check_in_guests.guest_id', '=', 'guests.id')
            ->where('check_ins.hotel_id', $hotel->id)
            ->whereNull('check_ins.deleted_at')
            ->whereNull('guests.deleted_at')
            ->whereNotIn('check_ins.status', ['cancelled', 'no_show'])
            ->whereBetween('check_ins.check_in_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->select('guests.nationality_code', DB::raw('COUNT(*) as c'))
            ->groupBy('guests.nationality_code')
            ->orderByDesc('c')
            ->first();

        // Durée moyenne de séjour (nuits) — séjours arrivant ce mois (hors annulé/
        // no-show). Nuits = départ − arrivée, en utilisant le départ réel s'il est
        // connu, sinon le départ prévu. Calcul en PHP sur des dates locales pures
        // (Africa/Tunis, pas de DST en Tunisie → écart = multiple exact de 86400 s).
        $stays = CheckIn::where('hotel_id', $hotel->id)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->whereBetween('check_in_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get(['check_in_date', 'expected_check_out_date', 'actual_check_out_date']);

        $avgStayNights = null;
        if ($stays->isNotEmpty()) {
            $sumNights = $stays->sum(function ($c) {
                $in  = Carbon::parse($c->check_in_date)->startOfDay();
                $out = Carbon::parse($c->actual_check_out_date ?? $c->expected_check_out_date)->startOfDay();
                return max(0, (int) round(($out->getTimestamp() - $in->getTimestamp()) / 86400));
            });
            $avgStayNights = round($sumNights / $stays->count(), 1);
        }

        // ── Watchlist hits pending acknowledgement ────────────────────────────
        $pendingWatchlistHits = WatchlistHit::where('hotel_id', $hotel->id)
            ->whereNull('acknowledged_at')
            ->count();

        $sub = $hotel->activeSubscription;

        return response()->json([
            'data' => [
                'today' => [
                    'arrivals_expected'   => $arrivalsExpected,
                    'arrivals_done'       => $arrivalsDone,
                    'currently_present'   => $currentlyPresent,
                    'departures_today'    => $departuresToday,
                    'departures_tomorrow' => $departuresTomorrow,
                    'drafts_pending'      => $draftsPending,
                    'occupancy_rate'      => $occupancyRate,
                ],
                'month' => [
                    'check_ins_total' => $monthTotal,
                ],
                'room_count'     => $hotel->room_count,
                'weekly_trend'   => $weekly,
                'occupancy_7d'   => $occupancy,
                'arrivals_today'   => $arrivalsList,
                'departures_today_list' => $departuresList,
                'present_guests' => $presentGuests,
                'properties_summary' => $propertiesSummary,
                'other_properties' => [
                    'arrivals'   => $otherArrivals,
                    'departures' => $otherDepartures,
                ],
                'expiry_alerts'  => $expiryAlerts,
                'subscription' => $sub ? [
                    'status'         => $sub->status,
                    'expires_at'     => $sub->expires_at,
                    'days_remaining' => $sub->days_remaining,
                    'plan'           => $sub->plan?->name,
                ] : ['status' => 'none'],
                'recent_check_ins'         => $recentCheckIns,
                'pending_watchlist_hits'   => $pendingWatchlistHits,
                'month_insights' => [
                    'top_nationality' => $topNat
                        ? ['code' => $topNat->nationality_code, 'count' => (int) $topNat->c]
                        : null,
                    'avg_stay_nights' => $avgStayNights,
                    'stay_sample'     => $stays->count(),
                ],
            ],
        ]);
    }

    /**
     * Occupation sur une fenêtre glissante de 7 jours — navigable semaine par semaine.
     * offset = 0 → fenêtre courante [today-4, today+2] (avec projection j+1/j+2).
     * offset < 0 → semaines passées (décalage de 7 jours), aucune projection.
     */
    public function occupancy(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel  = app('tenant');
        $today  = today();

        // Semaines vers le passé uniquement (pas de navigation dans le futur).
        $offset = min((int) $request->integer('offset', 0), 0);

        $end   = $today->copy()->addDays(2)->addDays($offset * 7);
        $start = $end->copy()->subDays(6);

        return response()->json([
            'data' => [
                'occupancy'  => $this->buildOccupancy($hotel, $start, $end),
                'start'      => $start->format('Y-m-d'),
                'end'        => $end->format('Y-m-d'),
                'offset'     => $offset,
                'is_current' => $offset === 0,
            ],
        ]);
    }

    /**
     * Calcule le taux d'occupation jour par jour sur [start, end] (bornes incluses).
     * Source de vérité unique du graphe d'occupation (dashboard + navigation).
     * Une nuit J est occupée par un séjour si check_in_date <= J < départ
     * (le jour du départ n'est pas une nuit occupée). Les nuits passées utilisent
     * la date de départ réelle si disponible ; aujourd'hui reprend exactement le
     * nombre de séjours actifs (= carte « Taux d'occupation ») ; le futur projette
     * les séjours actifs en cours.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildOccupancy(Hotel $hotel, Carbon $start, Carbon $end, ?int $activeCheckIns = null): array
    {
        $today = today();
        $activeCheckIns ??= CheckIn::where('hotel_id', $hotel->id)->where('status', 'active')->count();
        $roomCount = max($hotel->room_count, 1);

        $stays = CheckIn::where('hotel_id', $hotel->id)
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('check_in_date', '<=', $end)
            ->get(['check_in_date', 'expected_check_out_date', 'actual_check_out_date']);

        $days = (int) $start->diffInDays($end);
        $occupancy = [];
        for ($i = 0; $i <= $days; $i++) {
            $d = $start->copy()->addDays($i);

            if ($d->isSameDay($today)) {
                $units = $activeCheckIns; // matches the KPI card by construction
            } else {
                $units = $stays->filter(function ($s) use ($d, $today) {
                    $in = Carbon::parse($s->check_in_date)->startOfDay();
                    $rawOut = ($d->lt($today) && $s->actual_check_out_date)
                        ? $s->actual_check_out_date
                        : $s->expected_check_out_date;
                    $out = Carbon::parse($rawOut)->startOfDay();
                    return $in->lte($d) && $out->gt($d);
                })->count();
            }

            $occupancy[] = [
                'date'      => $d->format('Y-m-d'),
                'label'     => $d->locale('fr')->isoFormat('ddd D'),
                'rate'      => (int) min(round(($units / $roomCount) * 100), 100),
                'is_today'  => $d->isSameDay($today),
                'is_future' => $d->gt($today),
            ];
        }

        return $occupancy;
    }
}
