<?php

namespace Tests\Feature\PartnerApi;

use App\Models\FicheSession;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §3 — GET /widget/v1/bootstrap : jeton JWT usage unique (strict, sans fenêtre
 * de grâce), expiration, signature falsifiée, origine autorisée.
 */
class WidgetJwtAndBootstrapTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private function createSessionAndJwt(array $fixture, string $bookingRef = 'BK-BOOT'): array
    {
        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef))
            ->assertCreated();

        $query = parse_url($create->json('widget_url'), PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return ['session' => FicheSession::findOrFail($create->json('session_id')), 'jwt' => $params['token']];
    }

    public function test_bootstrap_with_valid_fresh_jwt_succeeds_and_returns_a_widget_token(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['session' => $session, 'jwt' => $jwt] = $this->createSessionAndJwt($fixture);

        $response = $this->getJson('/widget/v1/bootstrap?token='.$jwt)->assertOk();

        $this->assertSame($session->id, $response->json('session_id'));
        $this->assertNotEmpty($response->json('widget_token'));
        $this->assertSame('create', $response->json('mode'));

        $session->refresh();
        $this->assertNotNull($session->jwt_consumed_at);
        $this->assertNotNull($session->widget_session_token_hash);
    }

    public function test_reusing_the_exact_same_jwt_a_second_time_fails_strictly_no_grace_window(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['jwt' => $jwt] = $this->createSessionAndJwt($fixture);

        $this->getJson('/widget/v1/bootstrap?token='.$jwt)->assertOk();

        // Same JWT string, replayed immediately (well within its own TTL) —
        // still refused: usage is tracked by jwt_consumed_at, not by time.
        $this->getJson('/widget/v1/bootstrap?token='.$jwt)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'session_expired');
    }

    public function test_expired_jwt_fails_with_session_expired(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['session' => $session] = $this->createSessionAndJwt($fixture, 'BK-EXPIRED-JWT');

        // Craft a JWT with the exact same claims the app would issue, but with
        // an `exp` already in the past — exercises WidgetTokenService::decode()'s
        // ExpiredException branch directly, independent of wall-clock tricks
        // (WidgetTokenService::issue() uses time(), which Carbon::setTestNow
        // does not affect).
        $expiredJwt = JWT::encode([
            'session_id' => $session->id,
            'establishment_id' => $session->hotel_id,
            'partner_id' => $session->partner_id,
            'iat' => time() - 3600,
            'exp' => time() - 60,
            'jti' => $session->jwt_jti,
        ], config('partner_api.jwt.secret'), 'HS256');

        $this->getJson('/widget/v1/bootstrap?token='.$expiredJwt)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'session_expired');
    }

    public function test_jwt_with_tampered_signature_fails_with_invalid_signature(): void
    {
        $fixture = $this->setUpLinkedPartner();
        ['session' => $session] = $this->createSessionAndJwt($fixture, 'BK-TAMPERED');

        // Same payload, signed with the WRONG secret — decode() must reject it
        // via SignatureInvalidException, distinctly from an expired token.
        $tampered = JWT::encode([
            'session_id' => $session->id,
            'establishment_id' => $session->hotel_id,
            'partner_id' => $session->partner_id,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => $session->jwt_jti,
        ], 'a-completely-different-secret-value', 'HS256');

        $this->getJson('/widget/v1/bootstrap?token='.$tampered)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_signature');
    }

    public function test_bootstrap_from_disallowed_origin_returns_403_frame_disallowed(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $fixture['partner']->update(['allowed_widget_origins' => ['https://allowed.example.tn']]);
        ['jwt' => $jwt] = $this->createSessionAndJwt($fixture, 'BK-BAD-ORIGIN');

        $this->withHeaders(['Origin' => 'https://evil.example.com'])
            ->getJson('/widget/v1/bootstrap?token='.$jwt)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'frame_disallowed');
    }

    public function test_bootstrap_with_no_origin_or_referer_header_is_allowed(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $fixture['partner']->update(['allowed_widget_origins' => ['https://allowed.example.tn']]);
        ['jwt' => $jwt] = $this->createSessionAndJwt($fixture, 'BK-NO-ORIGIN');

        // No Origin/Referer at all — assertOriginAllowed() only rejects when an
        // origin IS present and doesn't match (server-to-server clients rarely
        // send one).
        $this->getJson('/widget/v1/bootstrap?token='.$jwt)->assertOk();
    }

    public function test_bootstrap_from_allowed_origin_succeeds(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $fixture['partner']->update(['allowed_widget_origins' => ['https://allowed.example.tn']]);
        ['jwt' => $jwt] = $this->createSessionAndJwt($fixture, 'BK-GOOD-ORIGIN');

        $this->withHeaders(['Origin' => 'https://allowed.example.tn'])
            ->getJson('/widget/v1/bootstrap?token='.$jwt)
            ->assertOk();
    }
}
