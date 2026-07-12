<?php

namespace App\Services\Notifications;

use App\Jobs\SendExpoPushJob;
use App\Models\AppNotification;
use App\Models\CheckIn;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Fires notifications to the MANAGERS of a check-in's property whenever a receptionist acts
 * (§6). Writes one persisted row per recipient (the notification centre's source of truth)
 * and dispatches a queued push. Wrapped so it can NEVER break the underlying check-in
 * operation (§6.3 — asynchronous, silent failure logged).
 */
class PushNotificationService
{
    public const TYPE_CHECK_IN        = 'check_in';
    public const TYPE_CHECK_OUT       = 'check_out';
    public const TYPE_FICHE_UPDATED   = 'fiche_updated';
    public const TYPE_FICHE_CANCELLED = 'fiche_cancelled';
    public const TYPE_FICHE_PENDING   = 'fiche_pending';
    public const TYPE_MANAGER_MESSAGE = 'manager_message';
    public const TYPE_DEPARTURE_DUE   = 'departure_due';

    /**
     * A manager broadcasts a free-text message to the receptionists of a property (or, if no
     * property is given, all of the manager's properties). Writes a persisted notification per
     * recipient and sends a push. Returns the number of recipients. Never throws.
     */
    public function notifyReceptionists(
        User $actor,
        string $message,
        ?string $propertyId = null,
        ?array $recipientIds = null,
    ): int {
        try {
            $hotels = $actor->hotels()->orderBy('hotels.created_at')->get();
            if ($propertyId) {
                $hotels = $hotels->where('id', $propertyId)->values();
            }
            if ($hotels->isEmpty()) {
                return 0;
            }

            $actorName = trim("{$actor->first_name} {$actor->last_name}");
            $title = "Message — {$actorName}";

            // Build a UNIQUE recipient set across the manager's properties so a receptionist
            // assigned to several of them is notified once. Each recipient keeps their first
            // property as the notification's establishment context. An explicit recipientIds
            // list narrows the audience; null/empty preserves the "all receptionists" default.
            $wanted = !empty($recipientIds) ? array_flip($recipientIds) : null;
            $recipients = []; // user_id => ['user' => User, 'hotel' => Hotel]

            foreach ($hotels as $hotel) {
                $receps = $hotel->users()
                    ->whereHas('roles', fn ($q) => $q->where('name', 'receptionist'))
                    ->get();

                foreach ($receps as $recipient) {
                    if ($wanted !== null && !isset($wanted[$recipient->id])) {
                        continue;
                    }
                    if (!isset($recipients[$recipient->id])) {
                        $recipients[$recipient->id] = ['user' => $recipient, 'hotel' => $hotel];
                    }
                }
            }

            if (empty($recipients)) {
                return 0;
            }

            foreach ($recipients as $entry) {
                AppNotification::create([
                    'user_id'  => $entry['user']->id,
                    'hotel_id' => $entry['hotel']->id,
                    'actor_id' => $actor->id,
                    'type'     => self::TYPE_MANAGER_MESSAGE,
                    'title'    => $title,
                    'body'     => $message,
                    'data'     => ['actor_name' => $actorName, 'property_name' => $entry['hotel']->name],
                ]);
            }

            $tokens = DeviceToken::whereIn('user_id', array_keys($recipients))->pluck('token')->all();
            if (!empty($tokens)) {
                dispatch(new SendExpoPushJob(array_values(array_unique($tokens)), $title, $message, [
                    'type' => self::TYPE_MANAGER_MESSAGE,
                ]))->afterResponse();
            }

            return count($recipients);
        } catch (\Throwable $e) {
            Log::warning('notifyReceptionists failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Notify managers of a check-in event. Never throws.
     */
    public function notifyCheckInEvent(CheckIn $checkIn, string $type, ?User $actor = null): void
    {
        try {
            $hotel = $checkIn->hotel; // relation (loaded on demand)
            if (!$hotel) {
                return;
            }

            // Recipients = managers (hotel_admin) attached to the property, minus the actor.
            $recipients = $hotel->users()
                ->whereHas('roles', fn ($q) => $q->where('name', 'hotel_admin'))
                ->when($actor, fn ($q) => $q->where('users.id', '!=', $actor->id))
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            [$title, $body] = $this->format($checkIn, $type, $actor, $hotel->name);
            $actorName = $actor ? trim("{$actor->first_name} {$actor->last_name}") : null;

            $payload = [
                'actor_name'    => $actorName,
                'property_name' => $hotel->name,
                'check_in_id'   => $checkIn->id,
                'reference'     => $checkIn->reference,
            ];

            foreach ($recipients as $recipient) {
                AppNotification::create([
                    'user_id'     => $recipient->id,
                    'hotel_id'    => $hotel->id,
                    'check_in_id' => $checkIn->id,
                    'actor_id'    => $actor?->id,
                    'type'        => $type,
                    'title'       => $title,
                    'body'        => $body,
                    'data'        => $payload,
                ]);
            }

            // Push to the recipients' registered devices (queued, after the DB commits).
            $tokens = DeviceToken::whereIn('user_id', $recipients->pluck('id'))
                ->pluck('token')
                ->all();

            if (!empty($tokens)) {
                // Run after the response is flushed — no queue worker needed in prod.
                // property_id/property_name let the app switch to the right establishment
                // when the notification points at a stay outside the active property.
                dispatch(new SendExpoPushJob($tokens, $title, $body, [
                    'check_in_id'   => $checkIn->id,
                    'property_id'   => $hotel->id,
                    'property_name' => $hotel->name,
                    'type'          => $type,
                ]))->afterResponse();
            }
        } catch (\Throwable $e) {
            Log::warning('PushNotificationService failed', [
                'check_in' => $checkIn->id ?? null,
                'type'     => $type,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify an establishment's staff (managers AND receptionists) that an active stay's
     * expected departure is today and no check-out has been recorded (§8). Deep-links to the
     * stay so the check-out can be confirmed. Never throws.
     */
    public function notifyDepartureDue(CheckIn $checkIn): void
    {
        try {
            $hotel = $checkIn->hotel;
            if (!$hotel) {
                return;
            }

            $recipients = $hotel->users()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hotel_admin', 'receptionist']))
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $room  = $checkIn->room?->number ? "Ch. {$checkIn->room->number}" : 'Sans chambre';
            $guest = optional($checkIn->guests->first());
            $name  = $guest->exists ? trim("{$guest->first_name} {$guest->last_name}") : $checkIn->reference;

            $title = "Départ non enregistré — {$hotel->name}";
            $body  = "{$room} · {$name} · départ prévu aujourd'hui. Confirmer le check-out ?";

            foreach ($recipients as $recipient) {
                AppNotification::create([
                    'user_id'     => $recipient->id,
                    'hotel_id'    => $hotel->id,
                    'check_in_id' => $checkIn->id,
                    'actor_id'    => null,
                    'type'        => self::TYPE_DEPARTURE_DUE,
                    'title'       => $title,
                    'body'        => $body,
                    'data'        => ['property_name' => $hotel->name, 'check_in_id' => $checkIn->id],
                ]);
            }

            $tokens = DeviceToken::whereIn('user_id', $recipients->pluck('id'))->pluck('token')->all();
            if (!empty($tokens)) {
                dispatch(new SendExpoPushJob(array_values(array_unique($tokens)), $title, $body, [
                    'check_in_id'   => $checkIn->id,
                    'property_id'   => $hotel->id,
                    'property_name' => $hotel->name,
                    'type'          => self::TYPE_DEPARTURE_DUE,
                ]))->afterResponse();
            }
        } catch (\Throwable $e) {
            Log::warning('notifyDepartureDue failed', ['check_in' => $checkIn->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /**
     * French, seal-sober notification copy (§6.1). Returns [title, body].
     *
     * @return array{0:string,1:string}
     */
    private function format(CheckIn $checkIn, string $type, ?User $actor, string $hotelName): array
    {
        $actorName = $actor ? trim("{$actor->first_name} {$actor->last_name}") : 'un membre';
        $room      = $checkIn->room?->number ? "Ch. {$checkIn->room->number}" : 'Sans chambre';
        $time      = now()->format('H\hi');
        $people    = (int) ($checkIn->adults_count ?? 0) + (int) ($checkIn->children_count ?? 0);
        $guest     = optional($checkIn->guests->first());
        $guestName = $guest ? trim("{$guest->first_name} {$guest->last_name}") : $checkIn->reference;

        // No emojis — the in-app centre carries the event type via a coloured icon pill, and
        // pushes carry it in the title text alone (§6, sober Qayed identity).
        return match ($type) {
            self::TYPE_CHECK_OUT => [
                "Check-out — {$hotelName}",
                "{$room} · par {$actorName} · {$time}",
            ],
            self::TYPE_FICHE_UPDATED => [
                "Fiche modifiée — {$hotelName}",
                "{$guestName} · par {$actorName}",
            ],
            self::TYPE_FICHE_CANCELLED => [
                "Fiche annulée — {$hotelName}",
                "par {$actorName}",
            ],
            self::TYPE_FICHE_PENDING => [
                "Fiche non validée — {$hotelName}",
                "{$room} · en attente",
            ],
            default => [ // TYPE_CHECK_IN
                "Check-in — {$hotelName}",
                "{$room} · {$people} pers. · par {$actorName} · {$time}",
            ],
        };
    }
}
