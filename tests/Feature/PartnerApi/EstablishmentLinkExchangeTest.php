<?php

namespace Tests\Feature\PartnerApi;

use App\Services\PartnerApi\EstablishmentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §1 — échange du code de liaison établissement ↔ partenaire
 * (POST /v1/establishment-links) : succès, expiration, réutilisation, code
 * inconnu, révocation, et anti brute-force (5/min).
 */
class EstablishmentLinkExchangeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    public function test_valid_code_exchanges_for_a_link(): void
    {
        $this->seedWidgetJwtSecret();
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);
        $owner = $this->hotelOwner($hotel);

        ['plaintext' => $code] = app(EstablishmentLinkService::class)->generate($hotel, $owner);

        $response = $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/establishment-links', ['link_code' => $code])
            ->assertCreated();

        $response->assertJsonPath('establishment_id', $hotel->id);
        $response->assertJsonPath('establishment_name', $hotel->name);
        $this->assertNotNull($response->json('linked_at'));

        $this->assertDatabaseHas('establishment_partner_links', [
            'hotel_id' => $hotel->id,
            'partner_id' => $partner->id,
            'revoked_at' => null,
        ]);
    }

    public function test_expired_code_returns_422_link_code_expired(): void
    {
        $this->seedWidgetJwtSecret();
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);
        $owner = $this->hotelOwner($hotel);

        ['code' => $codeModel, 'plaintext' => $code] = app(EstablishmentLinkService::class)->generate($hotel, $owner);
        $codeModel->update(['expires_at' => now()->subHour()]);

        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/establishment-links', ['link_code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'link_code_expired');

        $this->assertDatabaseMissing('establishment_partner_links', [
            'hotel_id' => $hotel->id,
            'partner_id' => $partner->id,
        ]);
    }

    public function test_already_consumed_code_returns_422_link_code_already_used(): void
    {
        $this->seedWidgetJwtSecret();
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        $otherPartner = $this->makePartner(['name' => 'Other Partner']);
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);
        ['plaintext' => $otherApiKey] = $this->issuePartnerKey($otherPartner);
        $owner = $this->hotelOwner($hotel);

        ['plaintext' => $code] = app(EstablishmentLinkService::class)->generate($hotel, $owner);

        // First exchange succeeds…
        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/establishment-links', ['link_code' => $code])
            ->assertCreated();

        // …a second exchange of the SAME code (even by a different partner) is refused.
        $this->withHeaders($this->bearer($otherApiKey))
            ->postJson('/v1/establishment-links', ['link_code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'link_code_already_used');
    }

    public function test_unknown_garbage_code_returns_422_invalid_link_code(): void
    {
        $this->seedWidgetJwtSecret();
        $partner = $this->makePartner();
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);

        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/establishment-links', ['link_code' => 'TOTALLYBOGUS'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_link_code');
    }

    public function test_revoking_a_link_blocks_subsequent_fiche_session_creation(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['hotel' => $hotel, 'partner' => $partner, 'plaintext' => $apiKey, 'link' => $link] = $fixture;

        // Access works before revocation.
        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, 'BK-REVOKE-1'))
            ->assertSuccessful();

        app(EstablishmentLinkService::class)->revoke($link, $this->hotelOwner($hotel));

        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, 'BK-REVOKE-2'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');
    }

    public function test_rate_limiter_returns_429_after_5_requests_per_minute(): void
    {
        $this->seedWidgetJwtSecret();
        $partner = $this->makePartner();
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->bearer($apiKey))
                ->postJson('/v1/establishment-links', ['link_code' => 'GARBAGE'.$i])
                ->assertStatus(422); // invalid_link_code — the limiter counts the attempt regardless.
        }

        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/establishment-links', ['link_code' => 'GARBAGE-SIXTH'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    }
}
