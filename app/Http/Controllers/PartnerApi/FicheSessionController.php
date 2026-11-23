<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\FicheDeliveryStatus;
use App\Services\PartnerApi\FicheSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FicheSessionController extends Controller
{
    public function __construct(
        private FicheSessionService $sessions,
        private FicheDeliveryStatus $deliveryStatus,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'establishment_id' => ['required', 'uuid'],
            'booking_ref' => ['required', 'string', 'max:100'],
            'arrival_date' => ['required', 'date'],
            'departure_date' => ['required', 'date', 'after_or_equal:arrival_date'],
            'room' => ['nullable', 'string', 'max:100'],
            // Libellé partenaire brut, conservé pour affichage — 'room_id'
            // (GET /v1/establishments/{id}/rooms) est ce qui rattache
            // réellement la fiche à une chambre Qayed.
            'room_id' => ['nullable', 'uuid'],
            'guests' => ['present', 'array', 'max:20'],
            'guests.*.first_name' => ['nullable', 'string', 'max:100'],
            'guests.*.last_name' => ['nullable', 'string', 'max:100'],
            'guests.*.nationality' => ['nullable', 'string', 'max:3'],
            'guests.*.id_document_number' => ['nullable', 'string', 'max:100'],
            'guests.*.id_document_type' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
            // v1.1 — réceptionniste choisi par le manager côté partenaire dans
            // GET /v1/operators. Format libre volontairement : un identifiant
            // invalide ou étranger ne bloque jamais la déclaration (repli sur
            // l'utilisateur d'intégration, operator_matched=false).
            'operator' => ['nullable', 'array'],
            'operator.user_id' => ['nullable', 'string', 'max:64'],
            'operator.name' => ['nullable', 'string', 'max:150'],
            'locale' => ['nullable', 'in:fr,ar,en'],
        ]);

        $partner = app(ApiPartner::class);
        $apiKey = app(ApiKey::class);

        ['session' => $session, 'created' => $created, 'operator_matched' => $operatorMatched] = $this->sessions->createOrReuse($partner, $apiKey, [
            'establishment_id' => $validated['establishment_id'],
            'booking_ref' => $validated['booking_ref'],
            'arrival_date' => $validated['arrival_date'],
            'departure_date' => $validated['departure_date'],
            'room' => $validated['room'] ?? null,
            'room_id' => $validated['room_id'] ?? null,
            'guests' => $validated['guests'],
            'metadata' => $validated['metadata'] ?? [],
            'operator' => $validated['operator'] ?? [],
            'locale' => $validated['locale'] ?? null,
        ]);

        return response()->json([
            'session_id' => $session->id,
            'widget_url' => $this->sessions->widgetUrl($session),
            'expires_at' => $session->expires_at->toIso8601String(),
            'mode' => $session->mode,
            'operator_matched' => $operatorMatched,
        ], $created ? 201 : 200);
    }

    public function show(string $sessionId): JsonResponse
    {
        $partner = app(ApiPartner::class);
        $session = $this->sessions->findForPartner($partner, $sessionId);

        return response()->json($this->statusPayload($session));
    }

    /**
     * Libère le verrou de saisie d'une session (le partenaire a fermé sa
     * modale) : une autre personne peut ouvrir la fiche tout de suite. La
     * saisie déjà faite est conservée. Idempotent. Une clé de test n'agit que
     * sur ses sessions de test, une clé live que sur les sessions live.
     */
    public function releaseLock(string $sessionId): Response
    {
        $session = $this->sessions->findForPartner(app(ApiPartner::class), $sessionId);

        if ($session->is_test !== app(ApiKey::class)->isTestMode()) {
            throw new PartnerApiException(ErrorCodes::SESSION_NOT_FOUND);
        }

        $session->releaseLock();

        return response()->noContent();
    }

    /**
     * Permet à un partenaire de savoir si une fiche existe déjà pour une
     * réservation SANS avoir gardé le session_id d'origine — utile pour
     * décider, à l'affichage d'une vignette d'arrivée, si le bouton « Fiche
     * police » doit rouvrir le widget ou juste indiquer que c'est déjà fait.
     */
    public function showByBookingRef(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'establishment_id' => ['required', 'uuid'],
            'booking_ref' => ['required', 'string', 'max:100'],
        ]);

        $partner = app(ApiPartner::class);
        $session = $this->sessions->findLatestForBooking($partner, $validated['establishment_id'], $validated['booking_ref']);

        return response()->json($this->statusPayload($session));
    }

    private function statusPayload(FicheSession $session): array
    {
        return [
            'session_id' => $session->id,
            'status' => $session->publicStatus(),
            'fiche_id' => $session->status === FicheSession::STATUS_SUBMITTED ? $session->check_in_id : null,
            'booking_ref' => $session->booking_reference,
            'created_at' => $session->created_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            // v1.1 — lisibles en mode test (où aucun webhook n'est émis) comme
            // en live. guest_count = voyageurs effectivement déclarés sur la
            // fiche (un brouillon jamais validé n'en déclare aucun).
            'guest_count' => $this->sessions->declaredGuestCount($session),
            'expected_guest_count' => $session->expectedGuestCount(),
            // v1.2 — statut d'envoi à l'autorité de la fiche rattachée (voir
            // FicheDeliveryStatus) ; not_applicable en mode test, pour un
            // brouillon, ou sans fiche rattachée.
            'delivery_status' => $this->deliveryStatusFor($session),
        ];
    }

    private function deliveryStatusFor(FicheSession $session): string
    {
        if ($session->is_test || ! $session->check_in_id) {
            return FicheDeliveryStatus::NOT_APPLICABLE;
        }

        $checkIn = CheckIn::with('hotel.organization')->find($session->check_in_id);

        return $checkIn ? $this->deliveryStatus->forCheckIn($checkIn) : FicheDeliveryStatus::NOT_APPLICABLE;
    }
}
