<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WatchlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authority scoping tests.
 * Ministry (national) vs Police (governorate-scoped).
 */
class AuthorityScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $ministryUser;
    private User $policeUserTunis;
    private User $policeUserSfax;

    private AuthorityOrganization $ministry;
    private AuthorityOrganization $policeTunis;
    private AuthorityOrganization $policeSfax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ministry    = AuthorityOrganization::factory()->ministry()->create();
        $this->policeTunis = AuthorityOrganization::factory()->police('Tunis')->create();
        $this->policeSfax  = AuthorityOrganization::factory()->police('Sfax')->create();

        $this->ministryUser    = User::factory()->authorityUser($this->ministry)->create();
        $this->policeUserTunis = User::factory()->authorityUser($this->policeTunis)->create();
        $this->policeUserSfax  = User::factory()->authorityUser($this->policeSfax)->create();
    }

    // ── Watchlist reason visibility ───────────────────────────────────────────

    public function test_ministry_sees_reason_in_watchlist(): void
    {
        WatchlistEntry::factory()->for($this->ministry)->create([
            'reason'   => 'Secret national security reason',
            'severity' => 'critique',
        ]);

        $response = $this->actingAs($this->ministryUser)
            ->getJson('/api/v1/authority/watchlist')
            ->assertOk();

        $reason = $response->json('data.0.reason');
        $this->assertEquals('Secret national security reason', $reason);
    }

    public function test_police_cannot_see_reason_in_watchlist(): void
    {
        WatchlistEntry::factory()->for($this->ministry)->create([
            'reason'   => 'Secret national security reason',
            'severity' => 'critique',
        ]);

        $response = $this->actingAs($this->policeUserTunis)
            ->getJson('/api/v1/authority/watchlist')
            ->assertOk();

        // Police should see the entry (if it's from ministry, all can see in search)
        // But reason must be null
        $reason = $response->json('data.0.reason');
        $this->assertNull($reason, 'Police must not see the watchlist reason text');
    }

    // ── Watchlist CRUD permissions ─────────────────────────────────────────────

    public function test_police_cannot_edit_ministry_watchlist_entry(): void
    {
        $entry = WatchlistEntry::factory()->for($this->ministry)->create();

        $this->actingAs($this->policeUserTunis)
            ->patchJson("/api/v1/authority/watchlist/{$entry->id}", ['severity' => 'moyen'])
            ->assertForbidden();
    }

    public function test_police_cannot_delete_ministry_watchlist_entry(): void
    {
        $entry = WatchlistEntry::factory()->for($this->ministry)->create();

        $this->actingAs($this->policeUserTunis)
            ->deleteJson("/api/v1/authority/watchlist/{$entry->id}")
            ->assertForbidden();
    }

    public function test_police_sfax_cannot_edit_police_tunis_entry(): void
    {
        $entry = WatchlistEntry::factory()->for($this->policeTunis)->create();

        $this->actingAs($this->policeUserSfax)
            ->patchJson("/api/v1/authority/watchlist/{$entry->id}", ['severity' => 'moyen'])
            ->assertForbidden();
    }

    public function test_ministry_can_edit_any_watchlist_entry(): void
    {
        $entry = WatchlistEntry::factory()->for($this->policeTunis)->create(['severity' => 'moyen']);

        $this->actingAs($this->ministryUser)
            ->patchJson("/api/v1/authority/watchlist/{$entry->id}", ['severity' => 'critique'])
            ->assertOk();

        $this->assertDatabaseHas('watchlist_entries', ['id' => $entry->id, 'severity' => 'critique']);
    }

    // ── Watchlist list scoping ─────────────────────────────────────────────────

    public function test_police_only_sees_own_org_watchlist_entries_by_default(): void
    {
        WatchlistEntry::factory()->for($this->policeTunis)->count(2)->create();
        WatchlistEntry::factory()->for($this->policeSfax)->count(3)->create();

        $response = $this->actingAs($this->policeUserTunis)
            ->getJson('/api/v1/authority/watchlist')
            ->assertOk();

        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_ministry_sees_all_watchlist_entries(): void
    {
        WatchlistEntry::factory()->for($this->policeTunis)->count(2)->create();
        WatchlistEntry::factory()->for($this->policeSfax)->count(3)->create();
        WatchlistEntry::factory()->for($this->ministry)->count(1)->create();

        $response = $this->actingAs($this->ministryUser)
            ->getJson('/api/v1/authority/watchlist')
            ->assertOk();

        $this->assertEquals(6, $response->json('meta.total'));
    }

    // ── Search scoping for police ─────────────────────────────────────────────

    public function test_police_search_is_scoped_to_their_governorate(): void
    {
        $tunisHotel = Hotel::factory()->inGovernorate('Tunis')->create();
        $sfaxHotel  = Hotel::factory()->inGovernorate('Sfax')->create();

        // Create check-ins in both hotels
        \App\Models\CheckIn::factory()->for($tunisHotel)->withGuest('Ahmed', 'Ben Ali')->create();
        \App\Models\CheckIn::factory()->for($sfaxHotel)->withGuest('Ahmed', 'Ben Ali')->create();

        // Police Tunis search for Ahmed Ben Ali — should only see Tunis hotel
        $response = $this->actingAs($this->policeUserTunis)
            ->getJson('/api/v1/authority/search?first_name=Ahmed&last_name=Ben+Ali')
            ->assertOk();

        foreach ($response->json('data') as $guest) {
            $lastStay = $guest['last_stay'];
            if ($lastStay) {
                $this->assertEquals('Tunis', $lastStay['hotel']['governorate'] ?? null);
            }
        }
    }

    // ── Expired credential ────────────────────────────────────────────────────

    public function test_authority_user_with_expired_credential_is_blocked(): void
    {
        $expiredUser = User::factory()->authorityUser($this->policeTunis)->create();
        AuthorityUserProfile::where('user_id', $expiredUser->id)
            ->update(['expires_at' => now()->subDays(1)]);

        $this->actingAs($expiredUser)
            ->getJson('/api/v1/authority/watchlist')
            ->assertForbidden()
            ->assertJson(['errors' => [['code' => 'AUTHORITY_CREDENTIAL_EXPIRED']]]);
    }

    public function test_authority_user_without_profile_is_blocked(): void
    {
        $user = User::factory()->create();
        $user->assignRole('authority_user');

        $this->actingAs($user)
            ->getJson('/api/v1/authority/watchlist')
            ->assertForbidden();
    }

    // ── Hotel routes: authority cannot access ─────────────────────────────────

    public function test_authority_user_cannot_access_hotel_routes(): void
    {
        $this->actingAs($this->ministryUser)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertForbidden();
    }
}
