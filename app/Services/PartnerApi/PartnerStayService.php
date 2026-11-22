<?php

namespace App\Services\PartnerApi;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\CheckIn;
use App\Models\Room;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\CheckIn\CheckInService;
use App\Services\CheckIn\RoomOccupied;
use Illuminate\Support\Facades\DB;

/**
 * Séjours des fiches créées par l'API (v1.1) : le partenaire (PMS) est la
 * seule source de vérité des départs et des chambres — plus personne ne
 * saisit de départ dans Qayed pour ces établissements.
 *
 * Tout départ passe par CheckInService::checkout(), donc avec EXACTEMENT les
 * effets d'un départ saisi dans Qayed : statut « completed » + date de départ
 * réelle, journal d'audit `check_in.checked_out`, notification push aux
 * managers. Un départ n'envoie rien à l'autorité, ne touche pas au relais
 * WhatsApp ni au quota (constaté dans checkout(), inchangé ici).
 *
 * La chambre n'a pas d'état stocké à « libérer » : son occupation est dérivée
 * des séjours ouverts (draft/active) qui la référencent. Clore un séjour la
 * libère donc, sauf si un autre séjour y est encore ouvert — l'état est par
 * construction toujours recalculé à partir des séjours.
 */
class PartnerStayService
{
    public const TIMEZONE = 'Africa/Tunis';

    public function __construct(
        private CheckInService $checkIns,
        private PartnerIntegrationActor $actor,
    ) {}

    public static function today(): string
    {
        return now(self::TIMEZONE)->toDateString();
    }

    /**
     * Départ automatique quotidien : séjours actifs issus d'une fiche API dont
     * la date de départ prévue est atteinte (aujourd'hui compris), clos à
     * cette date prévue. Les fiches saisies dans Qayed ne sont jamais
     * concernées.
     */
    public function autoCheckoutDue(): int
    {
        $count = 0;

        CheckIn::query()
            ->where('status', 'active')
            ->whereNull('actual_check_out_date')
            ->where('metadata->source', 'api')
            ->whereDate('expected_check_out_date', '<=', self::today())
            ->with('hotel.organization')
            ->orderBy('expected_check_out_date')
            ->each(function (CheckIn $checkIn) use (&$count) {
                try {
                    $this->checkIns->checkout($checkIn, $checkIn->expected_check_out_date->toDateString(), $this->systemActor($checkIn));
                    $count++;
                } catch (\DomainException) {
                    // Clos entre la lecture et le verrou : rien à faire.
                }
            });

        return $count;
    }

    /**
     * Filet de sécurité : toute déclaration visant une chambre où un séjour
     * est encore ouvert clôt ce séjour d'abord — à sa date de départ prévue
     * si elle est passée, sinon à la date du jour. Jamais de refus.
     */
    public function closeOpenStaysInRoom(Room $room, ?string $exceptCheckInId, User $actor): void
    {
        CheckIn::where('room_id', $room->id)
            ->whereIn('status', ['draft', 'active'])
            ->when($exceptCheckInId, fn ($q) => $q->where('id', '!=', $exceptCheckInId))
            ->get()
            ->each(function (CheckIn $previous) use ($actor) {
                $date = min($previous->expected_check_out_date?->toDateString() ?? self::today(), self::today());
                $date = max($date, $previous->check_in_date->toDateString());

                $this->checkIns->checkout($previous, $date, $actor);
            });
    }

    /**
     * PATCH /v1/fiches/{id} : prolongation, départ anticipé, changement de
     * chambre, sans widget. Un séjour déjà clos automatiquement et prolongé
     * jusqu'à aujourd'hui ou plus tard est rouvert (même chemin que
     * « Annuler le départ » côté Qayed).
     *
     * @param  array{departure_date?:string, room_id?:string, room?:string|null}  $data
     */
    public function update(CheckIn $checkIn, array $data): CheckIn
    {
        if ($checkIn->status === 'cancelled') {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'Cette fiche a été annulée.');
        }

        $departure = $data['departure_date'] ?? null;
        if ($departure !== null && $departure < $checkIn->check_in_date->toDateString()) {
            throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, "departure_date ne peut pas précéder la date d'arrivée ({$checkIn->check_in_date->toDateString()}).");
        }

        $room = null;
        if (! empty($data['room_id'])) {
            $room = Room::where('hotel_id', $checkIn->hotel_id)->whereKey($data['room_id'])->first();
            if (! $room) {
                throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, "room_id inconnu pour cet établissement : {$data['room_id']}.");
            }
        }

        return DB::transaction(function () use ($checkIn, $data, $departure, $room) {
            $actor = $this->systemActor($checkIn);
            $old = $checkIn->only(['expected_check_out_date', 'room_id', 'status']) + ['room_label' => $checkIn->metadata['room_label'] ?? null];

            if ($room) {
                $this->closeOpenStaysInRoom($room, $checkIn->id, $actor);
                $checkIn->update(['room_id' => $room->id]);
            }

            if ($checkIn->status === 'completed' && $departure !== null && $departure >= self::today()) {
                try {
                    $checkIn = $this->checkIns->revertCheckout($checkIn, $actor);
                } catch (RoomOccupied) {
                    throw new PartnerApiException(ErrorCodes::VALIDATION_ERROR, 'Ce séjour est déjà clos et sa chambre a été réattribuée : indiquez room_id pour le prolonger dans une autre chambre.');
                }
            } elseif ($checkIn->status === 'completed' && $departure !== null) {
                $checkIn->update(['actual_check_out_date' => $departure]);
            }

            if ($departure !== null) {
                $checkIn->update(['expected_check_out_date' => $departure]);
            }

            if (array_key_exists('room', $data)) {
                $checkIn->update(['metadata' => array_merge($checkIn->metadata ?? [], ['room_label' => $data['room']])]);
            }

            $checkIn = $checkIn->fresh();
            AuditLogger::log('check_in.updated_by_partner', $checkIn, $old, $checkIn->only(['expected_check_out_date', 'room_id', 'status']) + [
                'room_label' => $checkIn->metadata['room_label'] ?? null,
                'partner' => $checkIn->metadata['partner_name'] ?? null,
            ], $actor, $checkIn->hotel_id);

            return $checkIn;
        });
    }

    private function systemActor(CheckIn $checkIn): User
    {
        return $this->actor->forOrganization($checkIn->hotel->organization);
    }
}
