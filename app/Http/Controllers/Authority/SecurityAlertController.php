<?php

namespace App\Http\Controllers\Authority;

use App\Http\Controllers\Controller;
use App\Models\WatchlistHit;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alertes de sécurité côté AUTORITÉ.
 *
 * Une alerte = une correspondance liste de surveillance (WatchlistHit) survenue
 * lors d'un check-in — le même événement qui déclenche l'alerte hôtel. Côté
 * autorité, les informations complètes du voyageur sont légitimes (nom,
 * document, nationalité), contrairement à l'email/écran hôtel volontairement
 * limités.
 *
 * Périmètre : le ministère voit tout le territoire ; un poste de police voit
 * uniquement les alertes des établissements de son gouvernorat (cohérent avec
 * le Tableau de bord).
 */
class SecurityAlertController extends Controller
{
    /**
     * GET /authority/security-alerts
     * Liste des alertes (défaut : actives = non prises en charge).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'   => ['nullable', 'in:new,seen,acknowledged,active,all'],
            'severity' => ['nullable', 'in:critique,eleve,moyen'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $request->input('status', 'active');

        $query = $this->scopedQuery($request)
            ->with([
                'guest.documents',
                'checkIn.room',
                'hotel.address',
                'entry.organization',
            ])
            ->orderByRaw("CASE authority_status WHEN 'new' THEN 1 WHEN 'seen' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at');

        if ($status === 'active') {
            $query->where('authority_status', '!=', 'acknowledged');
        } elseif (in_array($status, ['new', 'seen', 'acknowledged'], true)) {
            $query->where('authority_status', $status);
        }

        if ($request->filled('severity')) {
            $query->whereHas('entry', fn ($q) => $q->where('severity', $request->severity));
        }

        $hits = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => collect($hits->items())->map(fn (WatchlistHit $h) => $this->format($h)),
            'meta' => [
                'total'         => $hits->total(),
                'current_page'  => $hits->currentPage(),
                'per_page'      => $hits->perPage(),
                'last_page'     => $hits->lastPage(),
                'active_count'  => $this->activeCount($request),
            ],
        ]);
    }

    /**
     * POST /authority/security-alerts/{id}/seen
     * Trace la consultation d'une alerte (Nouvelle → Vue) + journal Activité.
     */
    public function seen(string $id, Request $request): JsonResponse
    {
        $hit = $this->scopedQuery($request)->findOrFail($id);

        if ($hit->authority_status === 'new') {
            $hit->update([
                'authority_status'  => 'seen',
                'authority_seen_at' => now(),
                'authority_seen_by' => $request->user()->id,
            ]);
        }

        AuditLogger::log('authority.security_alert_viewed', $hit, [], [
            'guest_id'    => $hit->guest_id,
            'check_in_id' => $hit->check_in_id,
            'hotel_id'    => $hit->hotel_id,
        ]);

        return response()->json(['data' => $this->format($hit->fresh([
            'guest.documents', 'checkIn.room', 'hotel.address', 'entry.organization',
        ]))]);
    }

    /**
     * POST /authority/security-alerts/{id}/acknowledge
     * Marque l'alerte « Prise en charge » + journal Activité.
     */
    public function acknowledge(string $id, Request $request): JsonResponse
    {
        $hit = $this->scopedQuery($request)->findOrFail($id);

        $old = ['authority_status' => $hit->authority_status];

        $hit->update([
            'authority_status'          => 'acknowledged',
            'authority_acknowledged_at' => now(),
            'authority_acknowledged_by' => $request->user()->id,
            // Une prise en charge implique la consultation.
            'authority_seen_at'         => $hit->authority_seen_at ?? now(),
            'authority_seen_by'         => $hit->authority_seen_by ?? $request->user()->id,
        ]);

        AuditLogger::log('authority.security_alert_acknowledged', $hit, $old, [
            'authority_status' => 'acknowledged',
            'guest_id'         => $hit->guest_id,
            'check_in_id'      => $hit->check_in_id,
        ]);

        return response()->json(['data' => $this->format($hit->fresh([
            'guest.documents', 'checkIn.room', 'hotel.address', 'entry.organization',
        ]))]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Requête de base filtrée par périmètre (ministère = national,
     * police = son gouvernorat).
     */
    private function scopedQuery(Request $request): Builder
    {
        $profile     = $this->getProfile($request);
        $isMinistry  = ($profile['org_type'] ?? null) === 'ministry';
        $governorate = $profile['governorate'] ?? null;

        $query = WatchlistHit::query();

        if (!$isMinistry && $governorate) {
            $query->whereHas('hotel.address', fn ($a) => $a->where('governorate', $governorate));
        }

        return $query;
    }

    private function activeCount(Request $request): int
    {
        return $this->scopedQuery($request)
            ->where('authority_status', '!=', 'acknowledged')
            ->count();
    }

    private function getProfile(Request $request): array
    {
        $profile = $request->user()->authorityProfile()->with('organization')->first();

        return [
            'org_type'    => $profile?->organization?->type,
            'governorate' => $profile?->organization?->governorate,
        ];
    }

    private function format(WatchlistHit $h): array
    {
        $doc = $h->guest?->documents?->first();

        return [
            'id'           => $h->id,
            'status'       => $h->authority_status,
            'hit_type'     => $h->hit_type,
            'occurred_at'  => $h->notified_hotel_at ?? $h->created_at,
            'seen_at'      => $h->authority_seen_at,
            'acknowledged_at' => $h->authority_acknowledged_at,

            // Voyageur — informations complètes légitimes côté autorité
            'guest' => [
                'id'               => $h->guest_id,
                'first_name'       => $h->guest?->first_name,
                'last_name'        => $h->guest?->last_name,
                'nationality_code' => $h->guest?->nationality_code,
                'date_of_birth'    => $h->guest?->date_of_birth?->toDateString(),
                'document_type'    => $doc?->type,
                'document_number'  => $doc?->document_number,
            ],

            // Établissement / séjour
            'hotel' => [
                'id'          => $h->hotel_id,
                'name'        => $h->hotel?->name,
                'governorate' => $h->hotel?->address?->governorate,
                'city'        => $h->hotel?->address?->city,
            ],
            'room_number'         => $h->checkIn?->room?->number,
            'check_in_id'         => $h->check_in_id,
            'check_in_reference'  => $h->checkIn?->reference,
            'check_in_date'       => $h->checkIn?->check_in_date,
            'check_out_date'      => $h->checkIn?->expected_check_out_date,

            // Signalement
            'severity'    => $h->entry?->severity,
            'reason_code' => $h->entry?->reason_code,
            'source'      => $h->entry?->source,
            'organization_name' => $h->entry?->organization?->name,
        ];
    }
}
