<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\FicheSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * Correctif du 23/11/2026 : ouvrir puis fermer le widget sans soumettre
 * bloquait la fiche ~15 min pour tout le monde. Verrou désormais vivant
 * (2 min sans action réelle), libéré à la fermeture, transférable.
 */
class WidgetLiveLockTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private array $fx;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = $this->setUpLinkedPartner();
        ['plaintext' => $this->fx['plaintext']] = $this->issuePartnerKey($this->fx['partner'], ApiKey::MODE_TEST);
        $this->alice = $this->receptionist('Alice', 'Mansour');
        $this->bob = $this->receptionist('Bob', 'Gharbi');
    }

    private function receptionist(string $first, string $last): User
    {
        return User::factory()->receptionist($this->fx['hotel'])->create([
            'organization_id' => $this->fx['org']->id,
            'status' => 'active',
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    /** Demande un widget_url pour $user (comme le PMS) et renvoie le JWT. */
    private function jwtFor(User $user): string
    {
        $prefill = [['first_name' => 'G1', 'last_name' => 'TEST'], ['first_name' => 'G2', 'last_name' => 'TEST']];
        $url = $this->withHeaders($this->bearer($this->fx['plaintext']))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($this->fx['hotel'], 'BK-LOCK', $prefill,
                ['operator' => ['user_id' => $user->id, 'name' => $user->first_name]]))
            ->assertSuccessful()
            ->json('widget_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q['token'];
    }

    private function open(User $user, bool $takeover = false): TestResponse
    {
        return $this->getJson('/widget/v1/bootstrap?token='.$this->jwtFor($user).($takeover ? '&takeover=1' : ''))->assertOk();
    }

    private function addGuest(string $widgetToken, string $first): TestResponse
    {
        return $this->withHeaders($this->bearer($widgetToken))
            ->postJson('/widget/v1/guests', $this->widgetGuestPayload([
                'first_name' => $first,
                'document' => ['type' => 'passport', 'document_number' => 'TN'.random_int(10000000, 99999999), 'issuing_country_code' => 'TUN'],
            ]));
    }

    private function lockSeconds(): int
    {
        return (int) config('partner_api.widget_session.lock_seconds');
    }

    public function test_closing_without_submitting_frees_the_fiche_immediately(): void
    {
        $aliceToken = $this->open($this->alice)->json('widget_token');
        $this->addGuest($aliceToken, 'Amel')->assertCreated();

        $this->withHeaders($this->bearer($aliceToken))->deleteJson('/widget/v1/lock')->assertNoContent();

        // Bob ouvre aussitôt : pas de verrou, la saisie d'Alice est là.
        $boot = $this->open($this->bob);
        $this->assertNotEmpty($boot->json('widget_token'));
        $this->assertNull($boot->json('locked_by'));
        $this->assertCount(1, $boot->json('existing_guests'));
        $this->assertSame('Amel', $boot->json('existing_guests.0.first_name'));
    }

    public function test_the_lock_expires_after_two_minutes_without_a_real_action(): void
    {
        $this->assertSame(120, $this->lockSeconds());
        $this->open($this->alice);

        $this->travel($this->lockSeconds() - 10)->seconds();
        $this->assertSame('Alice Mansour', $this->open($this->bob)->json('locked_by'));

        $this->travel(11)->seconds();
        $this->assertNotEmpty($this->open($this->bob)->json('widget_token'));
    }

    public function test_a_status_check_does_not_renew_the_lock(): void
    {
        $aliceToken = $this->open($this->alice)->json('widget_token');

        $this->travel($this->lockSeconds() - 20)->seconds();
        $this->withHeaders($this->bearer($aliceToken))->getJson('/widget/v1/lock')->assertOk();
        $this->travel(30)->seconds();

        $this->assertNotEmpty($this->open($this->bob)->json('widget_token'));
    }

    public function test_a_real_action_renews_the_lock(): void
    {
        $aliceToken = $this->open($this->alice)->json('widget_token');

        $this->travel($this->lockSeconds() - 20)->seconds();
        $this->withHeaders($this->bearer($aliceToken))->postJson('/widget/v1/lock')->assertOk();
        $this->travel(30)->seconds();

        $this->assertSame('Alice Mansour', $this->open($this->bob)->json('locked_by'));
    }

    public function test_a_colleague_takes_over_and_keeps_the_guests_already_entered(): void
    {
        $aliceToken = $this->open($this->alice)->json('widget_token');
        $this->addGuest($aliceToken, 'Amel')->assertCreated();

        $locked = $this->open($this->bob);
        $this->assertNull($locked->json('widget_token'));
        $this->assertSame('Alice Mansour', $locked->json('locked_by'));

        $boot = $this->open($this->bob, takeover: true);
        $bobToken = $boot->json('widget_token');
        $this->assertNotEmpty($bobToken);
        $this->assertCount(1, $boot->json('existing_guests'));

        $session = FicheSession::where('booking_reference', 'BK-LOCK')->firstOrFail();
        $this->assertSame($this->bob->id, $session->operator_user_id);
        $this->assertTrue(AuditLog::where('action', 'fiche_session.lock_taken_over')->where('subject_id', $session->id)->exists());

        // Alice voit « Saisie reprise par Bob Gharbi » et ne peut plus soumettre.
        $this->withHeaders($this->bearer($aliceToken))->getJson('/widget/v1/lock')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'session_taken_over')
            ->assertJsonPath('error.taken_over_by', 'Bob Gharbi');
        $this->withHeaders($this->bearer($aliceToken))->postJson('/widget/v1/submit')
            ->assertStatus(409)->assertJsonPath('error.code', 'session_taken_over');

        // Bob termine avec la saisie d'Alice.
        $this->addGuest($bobToken, 'Sami')->assertCreated();
        $this->withHeaders($this->bearer($bobToken))->postJson('/widget/v1/submit')->assertOk();
        $this->assertSame(2, $session->fresh()->checkIn->guests()->count());
    }

    public function test_the_same_account_on_another_device_is_never_blocked(): void
    {
        $firstDevice = $this->open($this->alice)->json('widget_token');
        $this->addGuest($firstDevice, 'Amel')->assertCreated();

        $boot = $this->open($this->alice);
        $this->assertNotEmpty($boot->json('widget_token'));
        $this->assertNull($boot->json('locked_by'));
        $this->assertCount(1, $boot->json('existing_guests'));

        // L'ancien appareil cède la main au nouveau.
        $this->withHeaders($this->bearer($firstDevice))->getJson('/widget/v1/lock')
            ->assertStatus(409)->assertJsonPath('error.code', 'session_taken_over');
    }

    public function test_a_partner_can_release_the_lock(): void
    {
        $sessionId = $this->open($this->alice)->json('session_id');

        $this->withHeaders($this->bearer($this->fx['plaintext']))
            ->deleteJson("/v1/fiche-sessions/{$sessionId}/lock")->assertNoContent();
        // Idempotent.
        $this->withHeaders($this->bearer($this->fx['plaintext']))
            ->deleteJson("/v1/fiche-sessions/{$sessionId}/lock")->assertNoContent();

        $this->assertNotEmpty($this->open($this->bob)->json('widget_token'));
    }

    public function test_a_partner_cannot_release_a_session_of_the_other_key_mode_or_an_unknown_one(): void
    {
        $sessionId = $this->open($this->alice)->json('session_id');
        ['plaintext' => $live] = $this->issuePartnerKey($this->fx['partner'], ApiKey::MODE_LIVE);

        $this->withHeaders($this->bearer($live))
            ->deleteJson("/v1/fiche-sessions/{$sessionId}/lock")->assertNotFound();
        $this->withHeaders($this->bearer($this->fx['plaintext']))
            ->deleteJson('/v1/fiche-sessions/00000000-0000-0000-0000-000000000000/lock')->assertNotFound();

        $this->assertSame('Alice Mansour', $this->open($this->bob)->json('locked_by'));
    }
}
