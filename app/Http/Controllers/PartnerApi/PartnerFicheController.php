<?php

namespace App\Http\Controllers\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\WhatsappSendLog;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\EstablishmentLinkService;
use App\Services\PartnerApi\FicheDeliveryStatus;
use App\Services\PartnerApi\PartnerStayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PartnerFicheController extends Controller
{
    private const LIST_DEFAULT_LIMIT = 50;

    private const LIST_MAX_LIMIT = 100;

    public function __construct(
        private EstablishmentLinkService $links,
        private FicheDeliveryStatus $deliveryStatus,
    ) {}

    /**
     * v1.2 — fiches d'un établissement lié : saisies via l'API (par CE
     * partenaire) OU directement dans Qayed. Contrat complet : listFiches dans
     * docs/api/openapi.yaml.
     *
     *  - Brouillons exclus : un brouillon n'est pas encore une fiche (même
     *    sémantique que GET /v1/fiche-sessions, qui ne révèle fiche_id qu'une
     *    fois la session soumise).
     *  - Mode de la clé : une clé live voit les fiches réelles (saisies dans
     *    Qayed + ses fiches API live) ; une clé test ne voit QUE ses fiches API
     *    créées en mode test — jamais de donnée réelle dans un bac à sable, et
     *    jamais de fiche de test mêlée au registre réel.
     *  - Fiches API d'un AUTRE partenaire lié au même établissement : exclues
     *    (leur booking_ref appartient à l'autre logiciel).
     *  - Pagination par curseur opaque, ordre de création décroissant (stable :
     *    une fiche modifiée pendant le parcours ne change pas de place).
     *  - Aucun numéro de document, date de naissance ni image : seul le nom du
     *    voyageur principal est exposé.
     */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'establishment_id' => ['required', 'uuid'],
            'arrival_from' => ['nullable', 'date_format:Y-m-d'],
            'arrival_to' => ['nullable', 'date_format:Y-m-d'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::LIST_MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);

        if (isset($v['arrival_from'], $v['arrival_to']) && $v['arrival_to'] < $v['arrival_from']) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'arrival_to doit être postérieure ou égale à arrival_from.');
        }

        $cursor = isset($v['cursor']) ? $this->decodeCursor($v['cursor']) : null;

        $partner = app(ApiPartner::class);
        $apiKey = app(ApiKey::class);

        $this->links->assertLinked($partner, $v['establishment_id']);

        $query = CheckIn::query()
            ->where('hotel_id', $v['establishment_id'])
            ->where('status', '!=', 'draft')
            ->with(['guests', 'room:id,number', 'completedBy:id,is_system_actor', 'creator:id,is_system_actor', 'hotel.organization']);

        $this->scopeToKeyMode($query, $partner, $apiKey);

        if (isset($v['arrival_from'])) {
            $query->where('check_in_date', '>=', $v['arrival_from']);
        }

        if (isset($v['arrival_to'])) {
            $query->where('check_in_date', '<=', $v['arrival_to']);
        }

        if (isset($v['updated_since'])) {
            $since = Carbon::parse($v['updated_since'])->setTimezone(config('app.timezone'));

            // Une fiche dont seul l'envoi à l'autorité a progressé est aussi
            // « modifiée » : delivery_status fait partie de l'élément renvoyé.
            $query->where(fn (Builder $w) => $w
                ->where('check_ins.updated_at', '>=', $since)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('whatsapp_send_log')
                    ->whereColumn('whatsapp_send_log.check_in_id', 'check_ins.id')
                    ->where('whatsapp_send_log.is_test', false)
                    ->where('whatsapp_send_log.updated_at', '>=', $since)));
        }

        // Pagination par clé (created_at, id) décroissante. Les valeurs BRUTES
        // de la base voyagent dans le curseur : aucune conversion de fuseau
        // entre deux pages.
        if ($cursor !== null) {
            $query->where(fn (Builder $w) => $w
                ->where('check_ins.created_at', '<', $cursor['c'])
                ->orWhere(fn (Builder $eq) => $eq
                    ->where('check_ins.created_at', '=', $cursor['c'])
                    ->where('check_ins.id', '<', $cursor['i'])));
        }

        $limit = (int) ($v['limit'] ?? self::LIST_DEFAULT_LIMIT);

        $rows = $query
            ->orderByDesc('check_ins.created_at')
            ->orderByDesc('check_ins.id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();
        $statuses = $this->deliveryStatus->forCheckIns($items);

        return response()->json([
            'data' => $items->map(fn (CheckIn $c) => $this->listItem($c, $statuses[$c->id]))->values(),
            'next_cursor' => $hasMore && $last ? $this->encodeCursor($last) : null,
            'has_more' => $hasMore,
        ]);
    }

    private function encodeCursor(CheckIn $checkIn): string
    {
        $json = json_encode(['c' => (string) $checkIn->getRawOriginal('created_at'), 'i' => $checkIn->id]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{c: string, i: string} */
    private function decodeCursor(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        $valid = is_array($data)
            && is_string($data['c'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $data['c'])
            && is_string($data['i'] ?? null) && Str::isUuid($data['i']);

        if (! $valid) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'cursor invalide : renvoyez tel quel le next_cursor d\'une réponse précédente.');
        }

        return ['c' => $data['c'], 'i' => $data['i']];
    }

    private function scopeToKeyMode(Builder $query, ApiPartner $partner, ApiKey $apiKey): void
    {
        if ($apiKey->isTestMode()) {
            $query->whereRaw("metadata->>'source' = 'api'")
                ->whereRaw("metadata->>'partner_id' = ?", [$partner->id])
                ->whereRaw("COALESCE(metadata->>'test_mode', 'false') = 'true'");

            return;
        }

        $query->whereRaw("COALESCE(metadata->>'test_mode', 'false') <> 'true'")
            ->where(fn (Builder $w) => $w
                ->whereRaw("COALESCE(metadata->>'source', '') <> 'api'")
                ->orWhereRaw("metadata->>'partner_id' = ?", [$partner->id]));
    }

    private function listItem(CheckIn $checkIn, string $deliveryStatus): array
    {
        $fromApi = ($checkIn->metadata['source'] ?? null) === 'api';
        $primary = $checkIn->guests->first(fn ($g) => (bool) ($g->pivot->is_primary ?? false)) ?? $checkIn->guests->first();
        $operator = $checkIn->completedBy ?? $checkIn->creator;

        return [
            'fiche_id' => $checkIn->id,
            'establishment_id' => $checkIn->hotel_id,
            'booking_ref' => $fromApi ? $checkIn->booking_reference : null,
            'room_id' => $checkIn->room_id,
            'room' => $fromApi
                ? ($checkIn->metadata['room_label'] ?? $checkIn->room?->number)
                : $checkIn->room?->number,
            'arrival_date' => $checkIn->check_in_date?->toDateString(),
            'departure_date' => $checkIn->expected_check_out_date?->toDateString(),
            'guest_count' => $checkIn->guests->count(),
            'primary_guest_name' => $primary ? (trim("{$primary->first_name} {$primary->last_name}") ?: null) : null,
            'status' => $checkIn->status,
            'source' => $fromApi ? 'api' : 'qayed',
            // Utilisateur Qayed ayant validé la fiche (à défaut, l'ayant créée) ;
            // null pour l'utilisateur technique d'intégration.
            'operator_user_id' => $operator && ! $operator->is_system_actor ? $operator->id : null,
            'submitted_at' => $checkIn->completed_at?->toIso8601String(),
            'delivery_status' => $deliveryStatus,
        ];
    }

    public function show(string $ficheId): JsonResponse
    {
        return response()->json($this->payload($this->findForPartner($ficheId)));
    }

    /**
     * v1.1 — prolongation, départ anticipé, changement de chambre, sans
     * widget (voir PartnerStayService::update).
     */
    public function update(Request $request, string $ficheId, PartnerStayService $stays): JsonResponse
    {
        $data = $request->validate([
            'departure_date' => ['sometimes', 'date'],
            'room_id' => ['sometimes', 'uuid'],
            'room' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($data === []) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'Indiquez au moins departure_date, room_id ou room.');
        }

        if (isset($data['departure_date'])) {
            $data['departure_date'] = substr((string) $data['departure_date'], 0, 10);
        }

        $checkIn = $stays->update($this->findForPartner($ficheId), $data);

        return response()->json($this->payload($checkIn->load('guests')));
    }

    private function findForPartner(string $ficheId): CheckIn
    {
        $partner = app(ApiPartner::class);

        $checkIn = CheckIn::with(['guests', 'hotel.organization'])->find($ficheId);

        if (! $checkIn || ($checkIn->metadata['partner_id'] ?? null) !== $partner->id) {
            throw new PartnerApiException(ErrorCodes::FICHE_NOT_FOUND);
        }

        // Établissement toujours lié : une révocation ne doit pas laisser une
        // fiche déjà soumise consultable indéfiniment par un partenaire coupé.
        $this->links->assertLinked($partner, $checkIn->hotel_id);

        return $checkIn;
    }

    private function payload(CheckIn $checkIn): array
    {
        return [
            'fiche_id' => $checkIn->id,
            'booking_ref' => $checkIn->booking_reference,
            'establishment_id' => $checkIn->hotel_id,
            'guest_count' => $checkIn->guests->count(),
            'submitted_at' => $checkIn->completed_at?->toIso8601String(),
            'authority_status' => $this->authorityStatusFor($checkIn),
            'reference_number' => $checkIn->reference,
            // v1.1 — séjour tel que Qayed le voit (PATCH le met à jour).
            'arrival_date' => $checkIn->check_in_date?->toDateString(),
            'departure_date' => $checkIn->expected_check_out_date?->toDateString(),
            'checked_out_at' => $checkIn->actual_check_out_date?->toDateString(),
            'room_id' => $checkIn->room_id,
            'stay_status' => $checkIn->status,
            // v1.2 — voir FicheDeliveryStatus.
            'delivery_status' => $this->deliveryStatus->forCheckIn($checkIn),
        ];
    }

    /**
     * Statut de transmission DÉRIVÉ du relais WhatsApp provisoire — PAS un
     * accusé de réception gouvernemental (aucune intégration MI n'existe
     * aujourd'hui). Voir API-V1-DECISIONS.md. Isolé ici pour être remplaçable
     * sans casser le contrat une fois l'homologation MI faite.
     */
    private function authorityStatusFor(CheckIn $checkIn): string
    {
        $logs = WhatsappSendLog::where('check_in_id', $checkIn->id)->get();

        if ($logs->isEmpty()) {
            return 'pending';
        }

        $delivered = $logs->contains(fn (WhatsappSendLog $l) => in_array($l->delivery_status, [
            WhatsappSendLog::DELIVERY_DELIVERED, WhatsappSendLog::DELIVERY_READ,
        ], true) || $l->status === WhatsappSendLog::STATUS_SENT);

        if ($delivered) {
            return 'relayed';
        }

        $allDead = $logs->every(fn (WhatsappSendLog $l) => in_array($l->status, [
            WhatsappSendLog::STATUS_FAILED, WhatsappSendLog::STATUS_CANCELLED,
        ], true));

        return $allDead ? 'unavailable' : 'pending';
    }
}
