<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\FicheSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * API v1.2 — widget aligné sur le parcours Check-in : bundle versionné (cause
 * de l'écran « liste » du 22/11/2026), voyageurs déjà saisis renvoyés quel que
 * soit le mode, verrou « Fiche en cours de saisie par … ».
 */
class WidgetV12FlowTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private function fixture(): array
    {
        $fixture = $this->setUpLinkedPartner();
        ['plaintext' => $fixture['plaintext']] = $this->issuePartnerKey($fixture['partner'], ApiKey::MODE_TEST);

        return $fixture;
    }

    private function receptionist(array $fixture, string $first, string $last): User
    {
        return User::factory()->receptionist($fixture['hotel'])->create([
            'organization_id' => $fixture['org']->id,
            'status' => 'active',
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function requestWidget(array $fixture, string $ref, ?User $operator = null, int $guests = 2): string
    {
        $prefill = array_map(fn ($i) => ['first_name' => "G{$i}", 'last_name' => 'TEST'], range(1, $guests));
        $overrides = $operator ? ['operator' => ['user_id' => $operator->id, 'name' => $operator->first_name]] : [];

        $url = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $ref, $prefill, $overrides))
            ->assertSuccessful()
            ->json('widget_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q['token'];
    }

    private function addGuest(string $widgetToken, string $first): string
    {
        return $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload([
                'first_name' => $first,
                'document' => ['type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN'],
            ]))
            ->assertCreated()
            ->json('data.id');
    }

    public function test_shell_versions_the_bundle_and_is_never_cached(): void
    {
        $jwt = $this->requestWidget($this->fixture(), 'BK-V12-SHELL');

        $response = $this->get('/widget/fiche?token='.$jwt)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('#/widget-assets/widget-main\.js\?v=[0-9a-f]{12}#', $response->getContent());
        $this->assertMatchesRegularExpression('#/widget-assets/widget-main\.css\?v=[0-9a-f]{12}#', $response->getContent());
    }

    public function test_a_resumed_draft_returns_the_guests_already_entered(): void
    {
        $fixture = $this->fixture();

        $token = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-DRAFT'))->assertOk()->json('widget_token');
        $this->addGuest($token, 'Amel');

        // Le widget est refermé puis rouvert depuis le PMS (même personne).
        $boot = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-DRAFT'))->assertOk();

        $this->assertSame('create', $boot->json('mode'));
        $this->assertCount(1, $boot->json('existing_guests'));
        $this->assertSame('Amel', $boot->json('existing_guests.0.first_name'));
        $this->assertTrue($boot->json('existing_guests.0.removable'));
        $this->assertSame(2, $boot->json('expected_guest_count'));
        $this->assertNull($boot->json('locked_by'));
    }

    public function test_a_second_person_sees_who_is_filling_the_form_and_the_first_keeps_working(): void
    {
        $fixture = $this->fixture();
        $alice = $this->receptionist($fixture, 'Alice', 'Mansour');
        $bob = $this->receptionist($fixture, 'Bob', 'Gharbi');

        $aliceToken = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-LOCK', $alice))
            ->assertOk()->json('widget_token');

        $bobJwt = $this->requestWidget($fixture, 'BK-V12-LOCK', $bob);
        $locked = $this->getJson('/widget/v1/bootstrap?token='.$bobJwt)->assertOk();

        $this->assertNull($locked->json('widget_token'));
        $this->assertSame('Alice Mansour', $locked->json('locked_by'));
        $this->assertSame([], $locked->json('existing_guests'));

        // La saisie d'Alice n'est pas coupée, et la fiche lui reste attribuée.
        $this->addGuest($aliceToken, 'Sami');
        $session = FicheSession::where('booking_reference', 'BK-V12-LOCK')->firstOrFail();
        $this->assertSame($alice->id, $session->operator_user_id);
        $this->assertNull($session->jwt_consumed_at, 'Le lien de Bob n\'est pas consommé : il pourra réessayer.');

        // Alice abandonne : passé le délai de verrou, Bob reprend avec le même lien.
        $this->travel((int) config('partner_api.widget_session.lock_minutes') + 1)->minutes();
        $boot = $this->getJson('/widget/v1/bootstrap?token='.$bobJwt)->assertOk();

        $this->assertNotEmpty($boot->json('widget_token'));
        $this->assertCount(1, $boot->json('existing_guests'));
        $this->assertSame($bob->id, $session->fresh()->operator_user_id);
    }

    public function test_the_same_person_reopening_is_never_locked_out(): void
    {
        $fixture = $this->fixture();
        $alice = $this->receptionist($fixture, 'Alice', 'Mansour');

        $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-SELF', $alice))->assertOk();
        $again = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-SELF', $alice))->assertOk();

        $this->assertNotEmpty($again->json('widget_token'));
        $this->assertNull($again->json('locked_by'));
    }

    public function test_completing_a_submitted_fiche_cannot_remove_guests_already_declared(): void
    {
        $fixture = $this->fixture();

        $token = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-AMEND', null, 1))->assertOk()->json('widget_token');
        $declaredId = $this->addGuest($token, 'Amel');
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk();

        $this->travel(1)->minutes();
        $amend = $this->getJson('/widget/v1/bootstrap?token='.$this->requestWidget($fixture, 'BK-V12-AMEND', null, 1))->assertOk();
        $this->assertSame('amend', $amend->json('mode'));
        $this->assertFalse($amend->json('existing_guests.0.removable'));

        $amendToken = $amend->json('widget_token');
        $this->withHeaders($this->bearer($amendToken))->deleteJson('/widget/v1/guests/'.$declaredId)->assertNotFound();

        $this->travel(1)->minutes();
        $addedId = $this->addGuest($amendToken, 'Sami');
        $this->withHeaders($this->bearer($amendToken))->deleteJson('/widget/v1/guests/'.$addedId)->assertNoContent();
    }
}
