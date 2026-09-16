<?php

namespace Tests\Feature\PartnerApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * Bug reproduit en production le 2026-09-16 : un navigateur ajoute l'en-tête
 * `Origin` sur toute requête POST/DELETE, MÊME de même origine — jamais sur un
 * GET de même origine. Les appels widget après bootstrap (ajout de voyageur,
 * scan, soumission) sont des POST/DELETE faits DEPUIS l'iframe déjà chargée :
 * leur `Origin` est qayed.tn LUI-MÊME, jamais celui du partenaire qui l'a
 * embarqué. PartnerWidgetFrameAncestors vérifiait cette origine contre la
 * seule liste `allowed_widget_origins` (des domaines partenaires) — un vrai
 * navigateur obtenait donc TOUJOURS 403 sur ces routes, alors qu'aucun test
 * existant ne le voyait (aucun ne posait d'en-tête `Origin`, donc la garde
 * `$origin !== null` les court-circuitait tous silencieusement).
 */
class WidgetSelfOriginTest extends TestCase
{
    use InteractsWithPartnerApi;
    use RefreshDatabase;

    private function bootstrap(array $fixture, string $bookingRef): string
    {
        $create = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $bookingRef))
            ->assertSuccessful();

        $query = parse_url($create->json('widget_url'), PHP_URL_QUERY);
        parse_str((string) $query, $params);

        return $this->getJson('/widget/v1/bootstrap?token='.$params['token'])
            ->assertOk()
            ->json('widget_token');
    }

    public function test_adding_a_guest_with_the_widgets_own_origin_header_succeeds(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SELF-1');

        $this->withHeaders(array_merge(
            $this->bearer($widgetToken),
            ['Origin' => rtrim((string) config('app.url'), '/')],
        ))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();
    }

    public function test_a_genuinely_foreign_origin_is_still_rejected_on_the_same_route(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SELF-2');

        $this->withHeaders(array_merge($this->bearer($widgetToken), ['Origin' => 'https://evil.example']))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'frame_disallowed');
    }

    public function test_submitting_with_the_widgets_own_origin_header_succeeds(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $widgetToken = $this->bootstrap($fixture, 'BK-SELF-3');
        $selfOrigin = ['Origin' => rtrim((string) config('app.url'), '/')];

        $this->withHeaders(array_merge($this->bearer($widgetToken), $selfOrigin))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();

        $this->withHeaders(array_merge($this->bearer($widgetToken), $selfOrigin))
            ->postJson('/widget/v1/submit')
            ->assertOk();
    }

    public function test_a_partner_specific_allowed_origin_still_works_as_before(): void
    {
        $fixture = $this->setUpLinkedPartner();
        $fixture['partner']->update(['allowed_widget_origins' => ['https://app.diarna.example']]);
        $widgetToken = $this->bootstrap($fixture, 'BK-SELF-4');

        $this->withHeaders(array_merge($this->bearer($widgetToken), ['Origin' => 'https://app.diarna.example']))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload())
            ->assertCreated();
    }
}
