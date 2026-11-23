<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\CheckIn\CheckInService;
use App\Services\Subscription\PlanEntitlements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cœur du modèle Stripe Checkout (§2) : une fiche_session par booking, jamais
 * deux fiches pour le même (établissement, booking_ref).
 *
 * IMPORTANT (voir API-V1-DECISIONS.md, D1) : le quota de check-ins n'est
 * JAMAIS bloquant, y compris ici — même règle légale que le flux natif
 * (PlanEntitlements::assertWithinLimit exempte explicitement
 * checkins_per_month). Le seul gate commercial bloquant est `api_access`.
 */
class FicheSessionService
{
    public function __construct(
        private CheckInService $checkInService,
        private EstablishmentLinkService $links,
        private PartnerIntegrationActor $actor,
        private WidgetTokenService $tokens,
        private PartnerStayService $stays,
    ) {}

    /** @return array{session: FicheSession, created: bool, operator_matched: bool} */
    public function createOrReuse(ApiPartner $partner, ApiKey $apiKey, array $data): array
    {
        $result = $this->createOrReuseSession($partner, $apiKey, $data);
        // Opérateur de CETTE demande (et non celui qui tient éventuellement la
        // saisie en cours, voir FicheSession::isHeldByAnother()).
        $result['operator_matched'] = $result['session']->requested_operator_user_id !== null;

        return $result;
    }

    /** @return array{session: FicheSession, created: bool} */
    private function createOrReuseSession(ApiPartner $partner, ApiKey $apiKey, array $data): array
    {
        $this->links->assertLinked($partner, $data['establishment_id']);

        $hotel = Hotel::findOrFail($data['establishment_id']);
        $org = $hotel->organization;

        if (! $org || ! PlanEntitlements::allows($org, 'api_access')) {
            throw new PartnerApiException(ErrorCodes::API_ACCESS_DISABLED);
        }

        // Réceptionniste désigné par le partenaire (operator.user_id, choisi
        // dans GET /v1/operators). Inconnu, désactivé ou d'un autre hébergeur :
        // null, repli silencieux sur l'utilisateur d'intégration.
        $operator = $this->actor->operatorFor($hotel, $data['operator']['user_id'] ?? null);
        $data['operator_user_id'] = $operator?->id;
        $data['operator_name'] = $data['operator']['name'] ?? null;

        return DB::transaction(function () use ($partner, $apiKey, $hotel, $data, $operator) {
            // Verrou applicatif : deux requêtes concurrentes pour le même
            // (établissement, booking_ref) ne doivent jamais produire deux
            // sessions "create" distinctes (double-clic côté PMS partenaire).
            $existing = FicheSession::where('hotel_id', $hotel->id)
                ->where('booking_reference', $data['booking_ref'])
                ->where('partner_id', $partner->id)
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            if ($existing && $existing->isPendingAndUsable()) {
                // Le JWT précédemment émis a pu être consommé (widget déjà ouvert
                // une fois, jamais soumis — modale refermée, page rechargée). Le
                // "usage unique" porte sur le JWT effectivement remis à
                // l'appelant, pas sur la session : une nouvelle demande de
                // widget_url doit toujours pouvoir en obtenir un utilisable.
                //
                // v1.2 : si une AUTRE personne a la fiche ouverte en ce moment,
                // sa saisie n'est pas coupée (son token de session reste
                // valable) ; le nouveau lien affichera « Fiche en cours de
                // saisie par … » tant qu'elle n'a pas fini ou abandonné.
                $heldByAnother = $existing->isHeldByAnother($data['operator_user_id'], $data['operator_name']);

                if ($existing->jwt_consumed_at !== null) {
                    $existing->update(array_merge([
                        'jwt_jti' => WidgetTokenService::newJti(),
                        'jwt_consumed_at' => null,
                        'expires_at' => now()->addMinutes((int) config('partner_api.jwt.ttl_minutes', 15)),
                    ], $heldByAnother ? [] : [
                        'widget_session_token_hash' => null,
                        'widget_session_expires_at' => null,
                    ]));
                }

                // Le réceptionniste qui rouvre la fiche peut être un autre que
                // celui de la première demande : la dernière demande fait foi,
                // sauf pendant qu'une autre personne tient la saisie (il ne
                // devient l'opérateur qu'en ouvrant le widget, au bootstrap).
                $existing->update(array_merge([
                    'requested_operator_user_id' => $data['operator_user_id'],
                    'requested_operator_name' => $data['operator_name'],
                    'locale' => $data['locale'] ?? $existing->locale,
                ], $heldByAnother ? [] : [
                    'operator_user_id' => $data['operator_user_id'],
                    'operator_name' => $data['operator_name'],
                ]));

                if ($existing->mode === FicheSession::MODE_CREATE) {
                    $this->maybeAssignRoom($hotel, $existing->check_in_id, $data);
                }

                return ['session' => $existing, 'created' => false];
            }

            // Une fiche API existe déjà pour ce (établissement, booking_ref),
            // quel que soit son statut : c'est elle qu'il faut réutiliser, JAMAIS
            // en créer une seconde — l'index unique partiel (migration
            // ..._add_partial_unique_booking_reference_to_check_ins) l'interdirait
            // de toute façon, mais en 500 plutôt qu'en réponse propre. Trois cas :
            //  - active/completed → mode amend (fiche déjà soumise) ;
            //  - draft            → session précédente jamais soumise, dont le
            //                       lien a expiré avant ouverture : on relie une
            //                       fiche session FRAÎCHE à ce MÊME brouillon,
            //                       sans reconsommer de quota ni renvoyer de
            //                       WhatsApp (rien de tout ça n'a encore eu lieu) ;
            //  - sinon (aucune)   → vraie création.
            //
            // v1.2 : aussi une fiche saisie dans Qayed que CE partenaire a
            // rattachée à sa réservation (PATCH /v1/fiches/{id} booking_ref) —
            // elle est rouverte en complément, jamais doublée.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["booking_ref|{$hotel->id}|{$data['booking_ref']}"]);

            $existingCheckIn = CheckIn::where('hotel_id', $hotel->id)
                ->where('booking_reference', $data['booking_ref'])
                ->where(fn ($q) => $q->where('metadata->source', 'api')
                    // Une clé de test ne rouvre jamais une fiche réelle rattachée.
                    ->when(! $apiKey->isTestMode(), fn ($w) => $w->orWhere('metadata->partner_id', $partner->id)))
                ->orderByRaw("CASE WHEN metadata->>'source' = 'api' THEN 0 ELSE 1 END")
                ->first();

            if ($existingCheckIn && in_array($existingCheckIn->status, ['active', 'completed'], true)) {
                $session = $this->makeSession($partner, $apiKey, $hotel, $data, FicheSession::MODE_AMEND, $existingCheckIn->id);

                return ['session' => $session, 'created' => false];
            }

            if ($existingCheckIn && $existingCheckIn->status === 'draft') {
                // Le brouillon a pu être créé sans room_id (partenaire n'ayant
                // pas encore sa table de correspondance) ; une nouvelle
                // demande qui EN apporte un corrige l'affectation avant que
                // la fiche ne soit finalisée — jamais après (voir amend
                // ci-dessus, qui ne touche pas au brouillon déjà clos).
                $this->maybeAssignRoom($hotel, $existingCheckIn->id, $data);

                $session = $this->makeSession($partner, $apiKey, $hotel, $data, FicheSession::MODE_CREATE, $existingCheckIn->id);

                return ['session' => $session, 'created' => false];
            }

            $isTest = $apiKey->isTestMode();
            if ($operator) {
                $actor = $operator;
            } else {
                $actor = $this->actor->forOrganization($hotel->organization);
                $this->actor->ensureAttachedTo($actor, $hotel);
            }
            // Journal d'audit de la création attribué au même utilisateur
            // qu'une fiche saisie dans Qayed (aucune session Sanctum ici).
            Auth::setUser($actor);

            $room = empty($data['room_id']) ? null : $this->resolveRoom($hotel, $data['room_id']);
            if ($room) {
                // Filet de sécurité (v1.1) : un séjour resté ouvert dans cette
                // chambre est clos, jamais un refus.
                $this->stays->closeOpenStaysInRoom($room, null, $actor);
            }

            $checkIn = $this->checkInService->create($hotel, $actor, [
                'room_id' => $room?->id,
                'check_in_date' => $data['arrival_date'],
                'expected_check_out_date' => $data['departure_date'],
                'booking_reference' => $data['booking_ref'],
                'booking_source' => 'api',
                'adults_count' => max(1, count($data['guests'] ?? [])),
                'notes' => null,
            ]);

            $checkIn->update(['metadata' => array_merge($checkIn->metadata ?? [], [
                'source' => 'api',
                'partner_id' => $partner->id,
                'partner_name' => $partner->name,
                'room_label' => $data['room'] ?? null,
                'test_mode' => $isTest,
            ])]);

            $session = $this->makeSession($partner, $apiKey, $hotel, $data, FicheSession::MODE_CREATE, $checkIn->id);

            return ['session' => $session, 'created' => true];
        });
    }

    /** Applique room_id sur un brouillon encore ouvert si fourni et différent — voir les deux appelants. */
    private function maybeAssignRoom(Hotel $hotel, string $checkInId, array $data): void
    {
        if (empty($data['room_id'])) {
            return;
        }

        $checkIn = CheckIn::whereKey($checkInId)->first();

        if (! $checkIn || $checkIn->status !== 'draft' || $checkIn->room_id === $data['room_id']) {
            return;
        }

        $room = $this->resolveRoom($hotel, $data['room_id']);
        $this->stays->closeOpenStaysInRoom($room, $checkIn->id, $this->actor->forOrganization($hotel->organization));
        $checkIn->update(['room_id' => $room->id]);
    }

    /**
     * Valide `room_id` (appartient bien à cet établissement). Volontairement
     * AUCUN refus pour chambre « occupée » (v1.1) : côté API, l'occupation
     * vient le plus souvent d'un séjour précédent jamais clôturé dans Qayed,
     * et une déclaration réglementaire ne doit jamais être bloquée par un
     * état interne. Le PMS partenaire reste la source de vérité de ses
     * chambres.
     */
    private function resolveRoom(Hotel $hotel, string $roomId): Room
    {
        $room = Room::where('hotel_id', $hotel->id)->where('id', $roomId)->first();

        if (! $room) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, "room_id inconnu pour cet établissement : {$roomId}.");
        }

        return $room;
    }

    /**
     * Dernière session connue pour ce (établissement, booking_ref) — permet à
     * un partenaire de savoir si une fiche existe déjà SANS avoir gardé le
     * session_id d'origine (ex. après un redémarrage de son intégration),
     * pour ne montrer un bouton « Fiche police » actif que quand c'est
     * pertinent.
     */
    public function findLatestForBooking(ApiPartner $partner, string $hotelId, string $bookingRef): FicheSession
    {
        $session = FicheSession::where('hotel_id', $hotelId)
            ->where('partner_id', $partner->id)
            ->where('booking_reference', $bookingRef)
            ->orderByDesc('created_at')
            ->first();

        if (! $session) {
            throw new PartnerApiException(ErrorCodes::SESSION_NOT_FOUND);
        }

        return $session;
    }

    private function makeSession(ApiPartner $partner, ApiKey $apiKey, Hotel $hotel, array $data, string $mode, string $checkInId): FicheSession
    {
        $jti = WidgetTokenService::newJti();

        return FicheSession::create([
            'hotel_id' => $hotel->id,
            'partner_id' => $partner->id,
            'api_key_id' => $apiKey->id,
            'booking_reference' => $data['booking_ref'],
            'mode' => $mode,
            'status' => FicheSession::STATUS_PENDING,
            'check_in_id' => $checkInId,
            'prefill_guests' => $data['guests'] ?? [],
            'arrival_date' => $data['arrival_date'] ?? null,
            'departure_date' => $data['departure_date'] ?? null,
            'room_label' => $data['room'] ?? null,
            'metadata' => $data['metadata'] ?? [],
            'is_test' => $apiKey->isTestMode(),
            'jwt_jti' => $jti,
            'operator_user_id' => $data['operator_user_id'] ?? null,
            'operator_name' => $data['operator_name'] ?? null,
            'requested_operator_user_id' => $data['operator_user_id'] ?? null,
            'requested_operator_name' => $data['operator_name'] ?? null,
            'locale' => $data['locale'] ?? 'fr',
            'expires_at' => now()->addMinutes((int) config('partner_api.jwt.ttl_minutes', 15)),
        ]);
    }

    public function widgetUrl(FicheSession $session): string
    {
        $jwt = $this->tokens->issue($session);

        return rtrim((string) config('app.url'), '/')."/widget/fiche?token={$jwt}";
    }

    public function declaredGuestCount(FicheSession $session): int
    {
        $checkIn = CheckIn::find($session->check_in_id);

        return $checkIn && $checkIn->status !== 'draft' ? $checkIn->guests()->count() : 0;
    }

    public function findForPartner(ApiPartner $partner, string $sessionId): FicheSession
    {
        $session = FicheSession::where('id', $sessionId)->where('partner_id', $partner->id)->first();

        if (! $session) {
            throw new PartnerApiException(ErrorCodes::SESSION_NOT_FOUND);
        }

        return $session;
    }

    /** Balaie les sessions pending dont le JWT a expiré sans soumission → status=expired. */
    public function expireStaleSessions(int $limit = 200): int
    {
        $count = 0;

        FicheSession::where('status', FicheSession::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->limit($limit)
            ->get()
            ->each(function (FicheSession $session) use (&$count) {
                $session->update(['status' => FicheSession::STATUS_EXPIRED]);
                app(PartnerWebhookOutboxService::class)->enqueueForSession($session, 'session.expired', [
                    'session_id' => $session->id,
                    'booking_ref' => $session->booking_reference,
                ]);
                $count++;
            });

        return $count;
    }
}
