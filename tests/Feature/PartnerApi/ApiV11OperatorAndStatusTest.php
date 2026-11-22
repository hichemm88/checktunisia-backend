<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\FicheSession;
use App\Models\Hotel;
use App\Models\Room;
use App\Models\User;
use App\Services\PartnerApi\PartnerIntegrationActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * API v1.1 : opérateur (GET /v1/operators + operator.user_id), statut lisible
 * sans webhook (guest_count / expected_guest_count), soumission partielle
 * puis complément, chambre « occupée » non bloquante, liaison directe admin.
 */
class ApiV11OperatorAndStatusTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    /** Fixture en mode TEST (aucun webhook) — c'est le cas que le partenaire doit pouvoir valider. */
    private function testModeFixture(): array
    {
        $fixture = $this->setUpLinkedPartner();
        ['plaintext' => $fixture['plaintext']] = $this->issuePartnerKey($fixture['partner'], ApiKey::MODE_TEST);

        return $fixture;
    }

    private function receptionist(array $fixture, array $attrs = []): User
    {
        return User::factory()->receptionist($fixture['hotel'])->create(array_merge([
            'organization_id' => $fixture['org']->id,
            'status' => 'active',
        ], $attrs));
    }

    private function create(array $fixture, string $ref, array $overrides = [], int $guests = 3): \Illuminate\Testing\TestResponse
    {
        $prefill = array_map(fn ($i) => ['first_name' => "G{$i}", 'last_name' => 'TEST'], range(1, $guests));

        return $this->withHeaders($this->bearer($fixture['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($fixture['hotel'], $ref, $prefill, $overrides));
    }

    private function widgetToken(string $widgetUrl): string
    {
        parse_str((string) parse_url($widgetUrl, PHP_URL_QUERY), $q);

        return $this->getJson('/widget/v1/bootstrap?token='.$q['token'])->assertOk()->json('widget_token');
    }

    private function addGuests(string $widgetToken, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->withHeaders($this->bearer($widgetToken))
                ->postJson('/widget/v1/guests', $this->widgetGuestPayload([
                    'first_name' => 'Voyageur'.$i,
                    'is_primary' => $i === 0,
                    'document' => ['type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN'],
                ]))
                ->assertCreated();
        }
    }

    public function test_operators_lists_active_users_of_the_linked_host_without_contact_details(): void
    {
        $fixture = $this->testModeFixture();
        $alice = $this->receptionist($fixture, ['first_name' => 'Alice', 'last_name' => 'Ben Ali']);
        $this->receptionist($fixture, ['first_name' => 'Inactive', 'status' => 'inactive']);
        app(PartnerIntegrationActor::class)->forOrganization($fixture['org']);
        $other = $this->makeOrgWithHotel();
        User::factory()->receptionist($other['hotel'])->create(['organization_id' => $other['org']->id, 'first_name' => 'Stranger']);

        $data = $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/operators')
            ->assertOk()
            ->json('data');

        $this->assertSame([['id' => $alice->id, 'name' => 'Alice Ben Ali', 'role' => 'receptionist']], $data);
    }

    public function test_valid_operator_user_id_attributes_the_fiche_exactly_like_a_native_one(): void
    {
        $fixture = $this->testModeFixture();
        $alice = $this->receptionist($fixture);

        $created = $this->create($fixture, 'BK-OP-1', ['operator' => ['user_id' => $alice->id, 'name' => 'Alice']])
            ->assertCreated()
            ->assertJsonPath('operator_matched', true);

        $token = $this->widgetToken($created->json('widget_url'));
        $this->addGuests($token, 3);
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk();

        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
        $this->assertSame($alice->id, $checkIn->created_by);
        $this->assertSame($alice->id, $checkIn->completed_by);
        $this->assertSame($alice->id, AuditLog::where('action', 'check_in.completed')->where('subject_id', $checkIn->id)->value('actor_id'));
        $this->assertSame(3, AuditLog::where('action', 'guest.added')->where('actor_id', $alice->id)->count());

        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiche-sessions/'.$created->json('session_id'))
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('guest_count', 3)
            ->assertJsonPath('expected_guest_count', 3);
    }

    public function test_user_of_another_host_is_silently_refused_and_the_name_is_kept_in_the_audit(): void
    {
        $fixture = $this->testModeFixture();
        $other = $this->makeOrgWithHotel();
        $stranger = User::factory()->receptionist($other['hotel'])->create(['organization_id' => $other['org']->id]);

        $created = $this->create($fixture, 'BK-OP-2', ['operator' => ['user_id' => $stranger->id, 'name' => 'Karim PMS']])
            ->assertCreated()
            ->assertJsonPath('operator_matched', false);

        $token = $this->widgetToken($created->json('widget_url'));
        $this->addGuests($token, 1);
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk();

        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
        $integration = app(PartnerIntegrationActor::class)->forOrganization($fixture['org']);
        $this->assertSame($integration->id, $checkIn->created_by);
        $this->assertSame($integration->id, $checkIn->completed_by);

        $audit = AuditLog::where('action', 'check_in.partner_operator')->where('subject_id', $checkIn->id)->firstOrFail();
        $this->assertSame('Karim PMS', $audit->new_values['operator_name']);
    }

    public function test_deactivated_or_malformed_operator_never_blocks_the_session(): void
    {
        $fixture = $this->testModeFixture();
        $gone = $this->receptionist($fixture, ['status' => 'inactive']);

        $this->create($fixture, 'BK-OP-3', ['operator' => ['user_id' => $gone->id]])
            ->assertCreated()
            ->assertJsonPath('operator_matched', false);

        $this->create($fixture, 'BK-OP-4', ['operator' => ['user_id' => 'pas-un-uuid']])
            ->assertCreated()
            ->assertJsonPath('operator_matched', false);
    }

    public function test_operator_deactivated_after_session_creation_falls_back_to_integration_at_submit(): void
    {
        $fixture = $this->testModeFixture();
        $alice = $this->receptionist($fixture);

        $created = $this->create($fixture, 'BK-OP-5', ['operator' => ['user_id' => $alice->id]])->assertJsonPath('operator_matched', true);
        $token = $this->widgetToken($created->json('widget_url'));
        $this->addGuests($token, 1);
        $alice->update(['status' => 'inactive']);
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk();

        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
        $this->assertSame(app(PartnerIntegrationActor::class)->forOrganization($fixture['org'])->id, $checkIn->completed_by);
    }

    public function test_partial_submission_then_complement_keeps_a_single_fiche(): void
    {
        $fixture = $this->testModeFixture();

        $first = $this->create($fixture, 'BK-PARTIAL')->assertCreated();
        $token = $this->widgetToken($first->json('widget_url'));
        $this->addGuests($token, 2);
        $this->withHeaders($this->bearer($token))->postJson('/widget/v1/submit')->assertOk()->assertJsonPath('guest_count', 2);

        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiche-sessions/by-booking-ref?establishment_id='.$fixture['hotel']->id.'&booking_ref=BK-PARTIAL')
            ->assertJsonPath('guest_count', 2)
            ->assertJsonPath('expected_guest_count', 3);

        $again = $this->create($fixture, 'BK-PARTIAL')->assertOk()->assertJsonPath('mode', 'amend');
        $token2 = $this->widgetToken($again->json('widget_url'));
        $this->withHeaders($this->bearer($token2))->postJson('/widget/v1/guests', $this->widgetGuestPayload([
            'first_name' => 'Late', 'is_primary' => false,
            'document' => ['type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN'],
        ]))->assertCreated();
        $this->withHeaders($this->bearer($token2))->postJson('/widget/v1/submit')->assertOk();

        $this->assertSame(1, CheckIn::where('booking_reference', 'BK-PARTIAL')->count());
        $this->withHeaders($this->bearer($fixture['plaintext']))
            ->getJson('/v1/fiche-sessions/'.$again->json('session_id'))
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('guest_count', 3);
    }

    public function test_an_occupied_room_never_blocks_the_declaration(): void
    {
        $fixture = $this->testModeFixture();
        $room = Room::factory()->for($fixture['hotel'])->create(['status' => 'occupied']);
        CheckIn::factory()->for($fixture['hotel'])->create(['room_id' => $room->id, 'status' => 'active']);

        $created = $this->create($fixture, 'BK-ROOM-OCC', ['room_id' => $room->id])->assertCreated();

        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
        $this->assertSame($room->id, $checkIn->room_id);

        parse_str((string) parse_url($created->json('widget_url'), PHP_URL_QUERY), $q);
        $this->getJson('/widget/v1/bootstrap?token='.$q['token'])
            ->assertOk()
            ->assertJsonPath('room', $room->number);
    }

    public function test_bootstrap_carries_locale_expected_count_and_plain_dates(): void
    {
        $fixture = $this->testModeFixture();
        $created = $this->create($fixture, 'BK-BOOT', ['locale' => 'ar', 'arrival_date' => '2026-12-01', 'departure_date' => '2026-12-04'], 2)->assertCreated();

        parse_str((string) parse_url($created->json('widget_url'), PHP_URL_QUERY), $q);
        $this->getJson('/widget/v1/bootstrap?token='.$q['token'])
            ->assertOk()
            ->assertJsonPath('locale', 'ar')
            ->assertJsonPath('expected_guest_count', 2)
            ->assertJsonPath('arrival_date', '2026-12-01')
            ->assertJsonPath('departure_date', '2026-12-04');

        $this->create($fixture, 'BK-BOOT-2', ['locale' => 'de'])->assertStatus(422);
    }

    public function test_dates_corrected_in_the_widget_are_applied_at_submit(): void
    {
        $fixture = $this->testModeFixture();
        $created = $this->create($fixture, 'BK-DATES', ['arrival_date' => '2026-12-01', 'departure_date' => '2026-12-04'], 1)->assertCreated();
        $token = $this->widgetToken($created->json('widget_url'));
        $this->addGuests($token, 1);

        $this->withHeaders($this->bearer($token))
            ->postJson('/widget/v1/submit', ['arrival_date' => '2026-12-02', 'departure_date' => '2026-12-05'])
            ->assertOk();

        $checkIn = CheckIn::findOrFail(FicheSession::findOrFail($created->json('session_id'))->check_in_id);
        $this->assertSame('2026-12-02', $checkIn->check_in_date->toDateString());
        $this->assertSame('2026-12-05', $checkIn->expected_check_out_date->toDateString());
    }

    public function test_widget_page_drops_x_frame_options_when_a_partner_frame_policy_applies(): void
    {
        $fixture = $this->testModeFixture();
        $fixture['partner']->update(['allowed_widget_origins' => ['https://diarna.kasbahost.com']]);
        $created = $this->create($fixture, 'BK-FRAME')->assertCreated();

        $page = $this->get(parse_url($created->json('widget_url'), PHP_URL_PATH).'?'.parse_url($created->json('widget_url'), PHP_URL_QUERY))->assertOk();
        $this->assertStringContainsString('https://diarna.kasbahost.com', (string) $page->headers->get('Content-Security-Policy'));
        $this->assertNull($page->headers->get('X-Frame-Options'));

        // Toute autre réponse garde l'en-tête.
        $this->assertSame('DENY', $this->getJson('/v1/operators')->headers->get('X-Frame-Options'));
    }

    public function test_admin_links_an_establishment_in_one_click_and_it_is_immediately_listed(): void
    {
        $fixture = $this->testModeFixture();
        $admin = User::factory()->platformAdmin()->create();
        $kenza = Hotel::factory()->create(['organization_id' => $fixture['org']->id, 'name' => 'Dar Kenza May']);

        $linkable = $this->actingAs($admin)
            ->getJson("/api/v1/admin/partners/{$fixture['partner']->id}/linkable-hotels?q=kenza")
            ->assertOk()
            ->json('data');
        $this->assertSame([$kenza->id], array_column($linkable, 'id'));

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$fixture['partner']->id}/links", ['hotel_id' => $kenza->id])
            ->assertCreated()
            ->assertJsonPath('data.hotel_name', 'Dar Kenza May');

        $this->assertDatabaseHas('audit_logs', ['action' => 'partner.link_created', 'actor_id' => $admin->id]);

        app('auth')->forgetGuards();
        $ids = array_column($this->withHeaders($this->bearer($fixture['plaintext']))->getJson('/v1/establishments')->assertOk()->json('data'), 'establishment_id');
        $this->assertContains($kenza->id, $ids);
        $this->assertNotContains($kenza->id, array_column(
            $this->actingAs($admin)->getJson("/api/v1/admin/partners/{$fixture['partner']->id}/linkable-hotels")->json('data'), 'id'
        ));
    }
}
