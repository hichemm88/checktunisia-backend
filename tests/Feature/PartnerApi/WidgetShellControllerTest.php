<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\ApiPartner;
use App\Models\Hotel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * §3/§8 — GET /widget/fiche (page HTML du widget, WidgetShellController).
 *
 * Régression : cette route est restée cassée en production (500) pendant un
 * moment sans qu'aucun test ne le détecte — show() déclarait un type de
 * retour `View` alors que `response()->view(...)->header(...)` renvoie une
 * `Illuminate\Http\Response`, ce que PHP rejette par une TypeError avant même
 * d'atteindre la vue. Ce fichier couvre précisément ce chemin.
 */
class WidgetShellControllerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    /** @return string widget_url créé via le vrai endpoint POST /v1/fiche-sessions */
    private function createWidgetUrl(ApiPartner $partner, string $plaintext, Hotel $hotel, string $bookingRef): string
    {
        return $this->withHeaders($this->bearer($plaintext))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, $bookingRef))
            ->assertSuccessful()
            ->json('widget_url');
    }

    public function test_widget_shell_returns_200_html_response_not_a_view_typeerror(): void
    {
        ['org' => $org, 'hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner(['allowed_widget_origins' => ['https://diarna.kasbahost.com']]);
        ['plaintext' => $plaintext] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        $this->linkEstablishment($partner, $hotel);

        $widgetUrl = $this->createWidgetUrl($partner, $plaintext, $hotel, 'DIARNA-SHELL-001');
        $token = $this->tokenFromWidgetUrl($widgetUrl);

        $response = $this->get('/widget/fiche?token='.$token);

        $response->assertOk();
        $this->assertInstanceOf(Response::class, $response->baseResponse);
        $response->assertSee('qayed-widget-root', false);
    }

    public function test_widget_shell_csp_includes_the_partners_origin_not_just_self(): void
    {
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner(['allowed_widget_origins' => ['https://diarna.kasbahost.com']]);
        ['plaintext' => $plaintext] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        $this->linkEstablishment($partner, $hotel);

        $widgetUrl = $this->createWidgetUrl($partner, $plaintext, $hotel, 'DIARNA-SHELL-002');
        $token = $this->tokenFromWidgetUrl($widgetUrl);

        $response = $this->get('/widget/fiche?token='.$token);

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://diarna.kasbahost.com', $csp, 'The partner origin must be present, not just \'self\' — otherwise the widget can only ever embed on qayed.tn itself.');
        $this->assertStringStartsWith('frame-ancestors', $csp);
    }

    public function test_widget_shell_falls_back_to_none_when_partner_has_no_allowed_origins(): void
    {
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner(['allowed_widget_origins' => []]);
        ['plaintext' => $plaintext] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        $this->linkEstablishment($partner, $hotel);

        $widgetUrl = $this->createWidgetUrl($partner, $plaintext, $hotel, 'DIARNA-SHELL-003');
        $token = $this->tokenFromWidgetUrl($widgetUrl);

        $response = $this->get('/widget/fiche?token='.$token);

        $response->assertOk(); // the shell still loads — only the CSP is maximally restrictive
        $this->assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_widget_shell_does_not_500_on_a_garbage_or_expired_token(): void
    {
        $this->seedWidgetJwtSecret();

        $response = $this->get('/widget/fiche?token=not-a-real-jwt-at-all');

        // No PHP exception, no Laravel debug/error page — the shell loads and
        // lets the React bootstrap call surface the real error to the user.
        $response->assertOk();
        $this->assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_widget_bootstrap_from_a_disallowed_origin_is_still_refused(): void
    {
        // Defense-in-depth check on the JSON endpoint is unaffected by this
        // patch — the CSP header alone is browser-enforced only.
        ['hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner(['allowed_widget_origins' => ['https://diarna.kasbahost.com']]);
        ['plaintext' => $plaintext] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        $this->linkEstablishment($partner, $hotel);

        $widgetUrl = $this->createWidgetUrl($partner, $plaintext, $hotel, 'DIARNA-SHELL-004');
        $token = $this->tokenFromWidgetUrl($widgetUrl);

        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/widget/v1/bootstrap?token='.$token)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'frame_disallowed');
    }

    private function tokenFromWidgetUrl(string $widgetUrl): string
    {
        $query = parse_url($widgetUrl, PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return $params['token'];
    }
}
