<?php

namespace Tests\Feature\PartnerApi;

use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use App\Models\FicheSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §2 — POST /v1/fiche-sessions (modèle "Stripe Checkout" : une session par
 * booking, jamais deux fiches pour le même (établissement, booking_ref)).
 */
class FicheSessionCreationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    public function test_empty_guests_array_is_accepted(): void
    {
        $fixture = $this->setUpLinkedPartner();

        $response = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-EMPTY', []))
            ->assertCreated();

        $this->assertSame('create', $response->json('mode'));
        $this->assertNotEmpty($response->json('session_id'));
        $this->assertNotEmpty($response->json('widget_url'));
    }

    public function test_accepts_one_two_and_five_guests(): void
    {
        $fixture = $this->setUpLinkedPartner();

        foreach ([1, 2, 5] as $count) {
            $guests = [];
            for ($i = 0; $i < $count; $i++) {
                $guests[] = ['first_name' => "Guest{$i}", 'last_name' => 'Test'];
            }

            $response = $this->withHeaders($this->bearer($fixture['plaintext']))
                ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], "BK-N{$count}", $guests))
                ->assertCreated();

            $session = FicheSession::findOrFail($response->json('session_id'));
            $checkIn = CheckIn::findOrFail($session->check_in_id);

            $this->assertSame(max(1, $count), $checkIn->adults_count);
            $this->assertCount($count, $session->prefill_guests);
        }
    }

    public function test_double_post_same_pending_booking_ref_returns_same_session_id_with_200(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $payload = $this->fichePayload($fixture['hotel'], 'BK-DOUBLE');

        $first = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertCreated();

        $second = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertOk(); // 200, NOT 201 — reused, not created.

        $this->assertSame($first->json('session_id'), $second->json('session_id'));
        $this->assertSame(1, FicheSession::where('booking_reference', 'BK-DOUBLE')->count());
    }

    /**
     * A widget link that was already opened once (jwt consumed) but never
     * submitted rotates its jti on a fresh POST — AS LONG AS the fiche_sessions
     * row itself has not yet expired (FicheSessionService::createOrReuse()
     * only takes this branch when isPendingAndUsable() is true, i.e.
     * status=pending AND expires_at is still in the future). This is narrower
     * than "can always be superseded once the JWT/session has expired" — see
     * the next test for what actually happens once expires_at has passed,
     * regardless of whether the widget was ever opened.
     */
    public function test_a_pending_session_whose_widget_was_opened_but_not_submitted_can_be_superseded_before_expiry(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $payload = $this->fichePayload($fixture['hotel'], 'BK-SUPERSEDE');

        $first = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertCreated();

        $sessionId = $first->json('session_id');
        $session = FicheSession::findOrFail($sessionId);
        $originalJti = $session->jwt_jti;

        // Simulate: widget was opened once (jwt consumed) but the guest closed
        // it without submitting — still well within the original 15-minute link.
        $session->update(['jwt_consumed_at' => now()]);

        $second = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertOk();

        // Same session row, same underlying fiche — but a rotated jti, so the
        // widget_url returned is genuinely usable again.
        $this->assertSame($sessionId, $second->json('session_id'));
        $this->assertSame(1, FicheSession::where('booking_reference', 'BK-SUPERSEDE')->count());

        $session->refresh();
        $this->assertNotSame($originalJti, $session->jwt_jti);
        $this->assertNull($session->jwt_consumed_at);
        $this->assertTrue($session->expires_at->isFuture());
    }

    /**
     * FIXED after being found while writing this suite: once fiche_sessions.expires_at
     * has passed with the widget never opened, createOrReuse() now looks up any
     * existing API CheckIn for (hotel_id, booking_reference) regardless of its
     * session's expiry. A still-`draft` CheckIn (never completed — nothing was
     * ever submitted) gets a FRESH session/jti pointing at the SAME CheckIn row,
     * never a second one — which the partial unique index on check_ins would
     * otherwise reject with a 500.
     */
    public function test_a_pending_session_never_opened_is_gracefully_superseded_after_expiry(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $payload = $this->fichePayload($fixture['hotel'], 'BK-NEVER-OPENED');

        $first = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertCreated();

        $session = FicheSession::findOrFail($first->json('session_id'));
        $this->assertNull($session->jwt_consumed_at); // widget genuinely never opened
        $originalCheckInId = $session->check_in_id;
        $session->update(['expires_at' => now()->subMinute()]);

        $second = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertOk(); // 200 — a fresh, usable session, not a new fiche.

        $this->assertSame('create', $second->json('mode'));
        $newSession = FicheSession::findOrFail($second->json('session_id'));
        $this->assertSame($originalCheckInId, $newSession->check_in_id);
        $this->assertTrue($newSession->expires_at->isFuture());

        // Still exactly one CheckIn for this booking — no orphan, no duplicate.
        $this->assertSame(1, CheckIn::where('hotel_id', $fixture['hotel']->id)
            ->where('booking_reference', 'BK-NEVER-OPENED')->count());
    }

    public function test_amend_mode_after_submission_reuses_the_same_check_in(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $payload = $this->fichePayload($fixture['hotel'], 'BK-AMEND');

        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertCreated();
        $this->assertSame('create', $create->json('mode'));

        $this->fullySubmitFiche($create->json('widget_url'));

        $checkInId = FicheSession::findOrFail($create->json('session_id'))->check_in_id;

        $amend = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $payload)
            ->assertOk();

        $this->assertSame('amend', $amend->json('mode'));
        $amendSession = FicheSession::findOrFail($amend->json('session_id'));
        $this->assertSame($checkInId, $amendSession->check_in_id);
        $this->assertSame(1, CheckIn::where('hotel_id', $fixture['hotel']->id)
            ->where('booking_reference', 'BK-AMEND')->count());
    }

    public function test_api_access_disabled_returns_403_and_creates_nothing(): void
    {
        $fixture = $this->setUpLinkedPartner(apiAccess: false);

        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], 'BK-NOACCESS'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'api_access_disabled');

        $this->assertSame(0, FicheSession::count());
        $this->assertSame(0, CheckIn::where('booking_reference', 'BK-NOACCESS')->count());
    }

    public function test_establishment_not_linked_returns_403(): void
    {
        $this->seedWidgetJwtSecret();
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        ['plaintext' => $apiKey] = $this->issuePartnerKey($partner);
        // Deliberately no link created.

        $this->withHeaders($this->bearer($apiKey))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, 'BK-UNLINKED'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');

        $this->assertSame(0, FicheSession::count());
    }

    public function test_quota_never_blocks_fiche_creation_or_submission_even_past_the_configured_limit(): void
    {
        $fixture = $this->setUpLinkedPartner();

        // A punishing quota — 1 check-in per month — configured on the plan.
        $fixture['plan']->update(['features' => array_merge($fixture['plan']->features, [
            'api_access' => true,
            'checkins_per_month' => 1,
        ])]);

        for ($i = 0; $i < 3; $i++) {
            $bookingRef = "BK-QUOTA-{$i}";
            $create = $this->withHeaders($this->bearer($fixture['plaintext']))
                ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef))
                ->assertCreated();

            $this->fullySubmitFiche($create->json('widget_url'));
        }

        // All three fiches finalized despite the quota of 1 — checkins_per_month
        // is deliberately never blocking (PlanEntitlements::assertWithinLimit).
        $this->assertSame(3, CheckinUsageEvent::count());
        $this->assertSame(3, CheckIn::where('status', 'active')->count());
    }

    /** Drives create → bootstrap → add 1 guest → submit through the real HTTP endpoints. */
    private function fullySubmitFiche(string $widgetUrl): void
    {
        $token = $this->tokenFromWidgetUrl($widgetUrl);

        $bootstrap = $this->getJson('/widget/v1/bootstrap?token='.$token)->assertOk();
        $widgetToken = $bootstrap->json('widget_token');

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();

        $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/submit')
            ->assertOk();
    }

    private function tokenFromWidgetUrl(string $widgetUrl): string
    {
        $query = parse_url($widgetUrl, PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return $params['token'];
    }
}
