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
                SendExpoPushJob::dispatch($tokens, $title, $body, [
                    'check_in_id' => $checkIn->id,
                    'type'        => $type,
                ])->afterCommit();
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

        return match ($type) {
            self::TYPE_CHECK_OUT => [
                "🔁 Check-out — {$hotelName}",
                "{$room} · par {$actorName} · {$time}",
            ],
            self::TYPE_FICHE_UPDATED => [
                "✏️ Fiche modifiée — {$hotelName}",
                "{$guestName} · par {$actorName}",
            ],
            self::TYPE_FICHE_CANCELLED => [
                "❌ Fiche annulée — {$hotelName}",
                "par {$actorName}",
            ],
            self::TYPE_FICHE_PENDING => [
                "⚠️ Fiche non validée — {$hotelName}",
                "{$room} · en attente",
            ],
            default => [ // TYPE_CHECK_IN
                "✅ Check-in — {$hotelName}",
                "{$room} · {$people} pers. · par {$actorName} · {$time}",
            ],
        };
    }
}
