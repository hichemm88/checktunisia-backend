<?php

namespace Tests\Feature\PartnerApi\Concerns;

use App\Models\ApiPartner;
use App\Models\CheckIn;
use App\Models\CheckInGuest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WhatsappSendLog;

/**
 * Fixtures v1.2 (liste des fiches, delivery_status) : check-ins posés
 * directement en base, sans passer par le widget — ces tests portent sur la
 * lecture et sur le crochet des envois, pas sur la saisie.
 */
trait BuildsPartnerFiches
{
    /** Fiche saisie directement dans Qayed (aucune métadonnée API). */
    protected function makeNativeFiche(Hotel $hotel, array $attrs = [], ?Guest $primary = null): CheckIn
    {
        $user = User::factory()->create();

        $checkIn = CheckIn::factory()->create(array_merge([
            'hotel_id' => $hotel->id,
            'status' => 'active',
            'created_by' => $user->id,
            'completed_by' => $user->id,
            'completed_at' => now(),
            'booking_reference' => null,
            'metadata' => [],
        ], $attrs));

        $this->attachGuest($checkIn, $primary ?? Guest::factory()->named('Sami', 'Trabelsi')->create(), true);

        return $checkIn;
    }

    /** Fiche « créée via l'API » par $partner, posée en base. */
    protected function makeApiFiche(Hotel $hotel, ApiPartner $partner, string $bookingRef, bool $testMode = false, array $attrs = []): CheckIn
    {
        return $this->makeNativeFiche($hotel, array_merge([
            'booking_reference' => $bookingRef,
            'booking_source' => 'api',
            'metadata' => [
                'source' => 'api',
                'partner_id' => $partner->id,
                'partner_name' => $partner->name,
                'room_label' => 'Suite Jasmin',
                'test_mode' => $testMode,
            ],
        ], $attrs));
    }

    protected function attachGuest(CheckIn $checkIn, Guest $guest, bool $primary = false): Guest
    {
        CheckInGuest::create([
            'check_in_id' => $checkIn->id,
            'guest_id' => $guest->id,
            'is_primary' => $primary,
            'added_by' => $checkIn->created_by,
        ]);

        return $guest;
    }

    protected function giveDocument(Guest $guest, string $number): TravelDocument
    {
        return TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => $number,
            'issuing_country_code' => 'TUN',
        ]);
    }

    protected function makeSendLog(CheckIn $checkIn, string $status = WhatsappSendLog::STATUS_PENDING, ?Guest $guest = null, string $recipient = '21600000000@c.us'): WhatsappSendLog
    {
        $guest ??= $checkIn->guests()->first();

        return WhatsappSendLog::create([
            'hotel_id' => $checkIn->hotel_id,
            'check_in_id' => $checkIn->id,
            'guest_id' => $guest?->id,
            'recipient' => $recipient,
            'caption' => 'fiche de test',
            'status' => $status,
            'attempts' => 1,
            'queued_at' => now(),
            'next_attempt_at' => now(),
        ]);
    }

    protected function enableDeliveryModule(): void
    {
        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'web',
            'whatsapp.recipient' => '21600000000@c.us',
        ]);
    }

    protected function disableDeliveryModule(): void
    {
        config(['whatsapp.enabled' => false]);
    }
}
