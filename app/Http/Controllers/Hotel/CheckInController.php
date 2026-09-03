<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\Room;
use App\Services\CheckIn\CheckInService;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CheckInController extends Controller
{
    public function __construct(private CheckInService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = app('tenant');

        $query = CheckIn::with(['room', 'creator', 'guests.documents'])
            ->where('hotel_id', $hotel->id)
            ->withCount('guests');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('check_in_date', $request->date);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('check_in_date', [$request->date_from, $request->date_to]);
        }

        if ($request->filled('room_number')) {
            $query->whereHas('room', fn($q) => $q->where('number', $request->room_number));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('guests', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%");
            });
        }

        // Compteur de brouillons pour le badge « Brouillon » — indépendant du filtre
        // de statut actif (mais respecte le tenant) : une fiche en brouillon est une
        // fiche non transmise, ça doit se voir sans changer de filtre.
        $draftCount = CheckIn::where('hotel_id', $hotel->id)->where('status', 'draft')->count();

        $results = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $results->map(fn(CheckIn $c) => $this->summarize($c)),
            'meta' => [
                'total'        => $results->total(),
                'current_page' => $results->currentPage(),
                'per_page'     => $results->perPage(),
                'draft_count'  => $draftCount,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Hotel $hotel */
        $hotel = app('tenant');

        $validated = $request->validate([
            'room_id'                 => ['nullable', 'uuid', Rule::exists('rooms', 'id')->where('hotel_id', $hotel->id)],
            'check_in_date'           => ['required', 'date'],
            'expected_check_out_date' => ['required', 'date', 'after:check_in_date'],
            'booking_reference'       => ['nullable', 'string', 'max:100'],
            'booking_source'          => ['nullable', 'string', 'in:direct,booking,airbnb,expedia,phone,other'],
            'adults_count'            => ['integer', 'min:1', 'max:50'],
            'children_count'          => ['integer', 'min:0', 'max:20'],
            'notes'                   => ['nullable', 'string', 'max:1000'],
        ]);

        // Le test d'occupation et la création DOIVENT être dans la même
        // transaction, derrière un verrou sur la chambre : deux réceptionnistes
        // attribuant la 204 au même instant passaient tous les deux le test
        // `exists()` et créaient deux séjours actifs sur la même chambre.
        $conflict = null;
        $checkIn = DB::transaction(function () use ($hotel, $request, $validated, &$conflict) {
            if ($roomId = $validated['room_id'] ?? null) {
                Room::whereKey($roomId)->lockForUpdate()->first();

                if ($conflict = $this->roomConflictError($roomId)) {
                    return null;
                }
            }

            return $this->service->create($hotel, $request->user(), $validated);
        });

        if ($conflict) {
            return $conflict;
        }

        return response()->json(['data' => $this->detail($checkIn)], 201);
    }

    /**
     * A room can only have one draft/active check-in at a time — the receptionist
     * must check out the current guest before starting a new stay on the same room.
     * Returns the 422 response if the room is occupied, null otherwise.
     */
    private function roomConflictError(string $roomId, ?string $excludeCheckInId = null): ?JsonResponse
    {
        $occupied = CheckIn::where('room_id', $roomId)
            ->whereIn('status', ['draft', 'active'])
            ->when($excludeCheckInId, fn($q) => $q->where('id', '!=', $excludeCheckInId))
            ->exists();

        if (!$occupied) {
            return null;
        }

        return response()->json([
            'data'   => null,
            'errors' => [['code' => 'ROOM_OCCUPIED', 'message' => 'Cette chambre a déjà un check-in en cours. Effectuez le check-out avant d\'en commencer un nouveau.', 'field' => 'room_id']],
        ], 422);
    }

    public function show(string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);
        return response()->json(['data' => $this->detail($checkIn->load(['room', 'guests.documents', 'creator', 'completedBy']))]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        if (!$checkIn->canBeModified()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'CHECK_IN_ALREADY_COMPLETED', 'message' => 'Completed check-ins cannot be modified.', 'field' => null]],
            ], 409);
        }

        $validated = $request->validate([
            'room_id'                 => ['nullable', 'uuid', Rule::exists('rooms', 'id')->where('hotel_id', $checkIn->hotel_id)],
            /*
             | Les trois bornes ci-dessous existent à la création et avaient
             | disparu ici. Une règle de validation ne doit jamais s'affaiblir
             | entre la création et la modification — le formulaire n'envoie
             | pas ces valeurs, mais rien d'autre ne les empêchait.
             |
             | `after:` porte sur la date d'arrivée DÉJÀ ENREGISTRÉE, et non
             | sur un champ de la requête : l'arrivée ne se modifie pas ici.
             | Une durée négative n'est pas une donnée bizarre — c'est une
             | occupation fausse, un quota faux et une facture fausse.
             |
             | Les plafonds ne sont pas cosmétiques non plus : les colonnes
             | sont des `smallint`, et une valeur au-delà remontait en erreur
             | SQL brute (500), pas en 422.
             */
            'expected_check_out_date' => ['sometimes', 'date', 'after:'.$checkIn->check_in_date->toDateString()],
            'notes'                   => ['nullable', 'string', 'max:1000'],
            'adults_count'            => ['sometimes', 'integer', 'min:1', 'max:50'],
            'children_count'          => ['sometimes', 'integer', 'min:0', 'max:20'],
        ]);

        // Même verrou qu'à la création : changer de chambre passe par le même
        // test d'occupation, donc par la même course.
        $conflict = null;
        $old = $checkIn->toArray();

        DB::transaction(function () use ($checkIn, $validated, &$conflict) {
            if (($validated['room_id'] ?? null) && $validated['room_id'] !== $checkIn->room_id) {
                Room::whereKey($validated['room_id'])->lockForUpdate()->first();

                if ($conflict = $this->roomConflictError($validated['room_id'], $checkIn->id)) {
                    return;
                }
            }

            $checkIn->update($validated);
        });

        if ($conflict) {
            return $conflict;
        }

        \App\Services\Audit\AuditLogger::log('check_in.updated', $checkIn, $old, $checkIn->fresh()->toArray(), hotelId: $checkIn->hotel_id);

        app(PushNotificationService::class)
            ->notifyCheckInEvent($checkIn, PushNotificationService::TYPE_FICHE_UPDATED, $request->user());

        return response()->json(['data' => $this->detail($checkIn->fresh()->load(['room', 'guests.documents']))]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        try {
            $result = $this->service->complete($checkIn, $request->user());
        } catch (\DomainException $e) {
            return response()->json([
                'errors' => [['code' => 'INVALID_STATUS', 'message' => $e->getMessage()]],
            ], 422);
        }

        // Alertes quota (80 % / 100 %) après la réponse, sur la FINALISATION —
        // pas sur la création du brouillon : seule une fiche déclarée consomme
        // du quota. La finalisation n'est JAMAIS bloquée ni ralentie par le
        // quota (obligation légale de déclaration ; le dépassement est facturé,
        // pas empêché).
        if ($org = $checkIn->hotel?->organization) {
            dispatch(fn () => \App\Services\Subscription\QuotaAlertService::evaluateSafely($org))->afterResponse();
        }

        return response()->json(['data' => [
            'id'           => $result->id,
            'reference'    => $result->reference,
            'status'       => $result->status,
            'completed_at' => $result->completed_at,
        ]]);
    }

    public function checkout(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        $validated = $request->validate([
            'actual_check_out_date' => ['required', 'date', 'after_or_equal:' . $checkIn->check_in_date],
        ]);

        try {
            $result = $this->service->checkout($checkIn, $validated['actual_check_out_date'], $request->user());
        } catch (\DomainException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'INVALID_STATUS', 'message' => $e->getMessage(), 'field' => 'status']],
            ], 409);
        }

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status, 'actual_check_out_date' => $result->actual_check_out_date]]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $result = $this->service->cancel($checkIn, $validated['reason'], $request->user());
        } catch (\DomainException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'INVALID_STATUS', 'message' => $e->getMessage(), 'field' => 'status']],
            ], 409);
        }

        return response()->json(['data' => ['id' => $result->id, 'status' => $result->status]]);
    }

    /**
     * Manager établissement : annule un départ enregistré par erreur
     * (Terminé → Actif). Réservé à hotel_admin via la route. Renvoie 409 si le
     * séjour n'est pas dans l'état « Terminé ».
     */
    public function revertCheckout(Request $request, string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        try {
            $result = $this->service->revertCheckout($checkIn, $request->user());
        } catch (\App\Services\CheckIn\RoomOccupied $e) {
            // 422 et non 409 : la demande est coherente, c'est l'etat de la
            // CHAMBRE qui s'y oppose. La reception doit deplacer le client
            // suivant, pas reessayer — d'ou le meme code que le conflit de
            // chambre a la creation, que l'ecran sait deja presenter.
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'ROOM_OCCUPIED', 'message' => $e->getMessage(), 'field' => 'room_id']],
            ], 422);
        } catch (\DomainException $e) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'INVALID_STATUS', 'message' => $e->getMessage(), 'field' => 'status']],
            ], 409);
        }

        return response()->json(['data' => [
            'id' => $result->id,
            'status' => $result->status,
            'actual_check_out_date' => $result->actual_check_out_date,
        ]]);
    }

    /** Admin-only: delete any check-in regardless of status (soft delete — recoverable, kept for audit/compliance). */
    public function destroy(string $id): JsonResponse
    {
        $checkIn = $this->findForTenant($id);

        // La composition du séjour est journalisée AVANT le détachement : les
        // lignes check_in_guests, elles, sont supprimées pour de bon (le
        // check-in n'est qu'archivé), et sans cette trace on perdait la seule
        // preuve de qui séjournait sur cette fiche.
        \App\Services\Audit\AuditLogger::log(
            'check_in.deleted',
            $checkIn,
            $checkIn->toArray() + [
                'guests' => $checkIn->guests->map(fn ($g) => [
                    'id' => $g->id,
                    'first_name' => $g->first_name,
                    'last_name' => $g->last_name,
                    'is_primary' => (bool) $g->pivot?->is_primary,
                ])->all(),
            ],
            [],
            hotelId: $checkIn->hotel_id,
        );

        // Les voyageurs sont des entités PARTAGÉES (réutilisées par numéro de
        // document entre plusieurs check-ins/établissements). On ne détache donc
        // QUE le lien de CE check-in — un ->delete() soft-supprimait le voyageur
        // lui-même, le faisant disparaître de ses autres fiches.
        $checkIn->guests()->detach();
        $checkIn->delete();

        return response()->json(null, 204);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function findForTenant(string $id): CheckIn
    {
        return CheckIn::where('id', $id)
            ->where('hotel_id', app('tenant')->id)
            ->firstOrFail();
    }

    private function summarize(CheckIn $c): array
    {
        $primary = $c->guests->first();
        return [
            'id'                      => $c->id,
            'reference'               => $c->reference,
            'room'                    => $c->room ? ['id' => $c->room->id, 'number' => $c->room->number] : null,
            'check_in_date'           => $c->check_in_date,
            'expected_check_out_date' => $c->expected_check_out_date,
            'status'                  => $c->status,
            'guests_count'            => $c->guests_count,
            'document_expired'        => $this->hasExpiredDocument($c),
            'primary_guest'           => $primary ? [
                'first_name'      => $primary->first_name,
                'last_name'       => $primary->last_name,
                'nationality_code' => $primary->nationality_code,
            ] : null,
            'created_at' => $c->created_at,
        ];
    }

    /**
     * True when any traveller's document was already expired on the stay's arrival date (§3).
     * Derived from the persisted expiry_date, so the flag is stable over time.
     */
    private function hasExpiredDocument(CheckIn $c): bool
    {
        $arrival = $c->check_in_date;

        return $c->guests->contains(function ($g) use ($arrival) {
            return $g->documents->contains(function ($d) use ($arrival) {
                return $d->expiry_date && $d->expiry_date < $arrival;
            });
        });
    }

    private function detail(CheckIn $c): array
    {
        return [
            'id'                      => $c->id,
            'reference'               => $c->reference,
            'room'                    => $c->room ? ['id' => $c->room->id, 'number' => $c->room->number, 'floor' => $c->room->floor, 'type' => $c->room->type] : null,
            'booking_reference'       => $c->booking_reference,
            'booking_source'          => $c->booking_source,
            'check_in_date'           => $c->check_in_date,
            'expected_check_out_date' => $c->expected_check_out_date,
            'actual_check_out_date'   => $c->actual_check_out_date,
            'status'                  => $c->status,
            'adults_count'            => $c->adults_count,
            'children_count'          => $c->children_count,
            'notes'                   => $c->notes,
            'document_expired'        => $this->hasExpiredDocument($c),
            'guests'                  => $c->guests->map(fn($g) => $this->formatGuest($g, $c->id)),
            'created_by'              => $c->creator ? ['id' => $c->creator->id, 'first_name' => $c->creator->first_name, 'last_name' => $c->creator->last_name] : null,
            'completed_by'            => $c->completedBy ? ['id' => $c->completedBy->id, 'first_name' => $c->completedBy->first_name] : null,
            'completed_at'            => $c->completed_at,
            'created_at'              => $c->created_at,
        ];
    }

    private function formatGuest($guest, string $checkInId): array
    {
        $doc = $guest->documents->first();
        return [
            'id'              => $guest->id,
            'first_name'      => $guest->first_name,
            'last_name'       => $guest->last_name,
            'date_of_birth'   => $guest->date_of_birth,
            'sex'             => $guest->sex,
            'nationality_code' => $guest->nationality_code,
            'is_primary'      => (bool) $guest->pivot?->is_primary,
            'document'        => $doc ? [
                'id'                  => $doc->id,
                'type'                => $doc->type,
                'document_number'     => $doc->document_number,
                'issuing_country_code' => $doc->issuing_country_code,
                'expiry_date'         => $doc->expiry_date,
                'is_verified'         => $doc->is_verified,
            ] : null,
        ];
    }
}
