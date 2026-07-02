<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RBAC enforcement tests.
 * Each role must be strictly limited to its permitted routes.
 * Cross-role access must be rejected (403 or 401).
 */
class RbacTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;
    private User  $hotelAdmin;
    private User  $receptionist;
    private User  $authorityUser;
    private User  $platformAdmin;
    private AuthorityOrganization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel        = Hotel::factory()->withActiveSubscription()->create();
        $this->org          = AuthorityOrganization::factory()->ministry()->create();

        $this->hotelAdmin     = User::factory()->hotelAdmin($this->hotel)->create();
        $this->receptionist   = User::factory()->receptionist($this->hotel)->create();
        $this->authorityUser  = User::factory()->authorityUser($this->org)->create();
        $this->platformAdmin  = User::factory()->platformAdmin()->create();
    }

    // ── Unauthenticated ───────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/hotel/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/authority/search')->assertUnauthorized();
        $this->getJson('/api/v1/admin/hotels')->assertUnauthorized();
    }

    // ── Hotel Admin — allowed ─────────────────────────────────────────────────

    public function test_hotel_admin_can_access_dashboard(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertOk();
    }

    public function test_hotel_admin_can_manage_users(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->getJson('/api/v1/hotel/users')
            ->assertOk();
    }

    public function test_hotel_admin_can_manage_rooms(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->getJson('/api/v1/hotel/rooms')
            ->assertOk();
    }

    // ── Hotel Admin — forbidden ───────────────────────────────────────────────

    public function test_hotel_admin_cannot_access_authority_routes(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->getJson('/api/v1/authority/search?last_name=Test')
            ->assertForbidden();
    }

    public function test_hotel_admin_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->getJson('/api/v1/admin/hotels')
            ->assertForbidden();
    }

    // ── Receptionist — allowed ────────────────────────────────────────────────

    public function test_receptionist_can_view_checkins(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/hotel/check-ins')
            ->assertOk();
    }

    public function test_receptionist_can_view_rooms(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/hotel/rooms')
            ->assertOk();
    }

    // ── Receptionist — forbidden ──────────────────────────────────────────────

    public function test_receptionist_cannot_create_hotel_users(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/users', [
                'email'      => 'newuser@test.com',
                'first_name' => 'Test',
                'last_name'  => 'User',
                'role'       => 'receptionist',
                'password'   => 'Password1!Test',
            ])
            ->assertForbidden();
    }

    public function test_receptionist_cannot_create_rooms(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/rooms', ['number' => '101', 'type' => 'standard'])
            ->assertForbidden();
    }

    public function test_receptionist_cannot_delete_rooms(): void
    {
        $room = \App\Models\Room::factory()->for($this->hotel)->create();

        $this->actingAs($this->receptionist)
            ->deleteJson("/api/v1/hotel/rooms/{$room->id}")
            ->assertForbidden();
    }

    public function test_receptionist_cannot_update_hotel_profile(): void
    {
        $this->actingAs($this->receptionist)
            ->patchJson('/api/v1/hotel/profile', ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_receptionist_cannot_access_authority_routes(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/authority/dashboard')
            ->assertForbidden();
    }

    public function test_receptionist_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->receptionist)
            ->getJson('/api/v1/admin/hotels')
            ->assertForbidden();
    }

    // ── Authority User — allowed ──────────────────────────────────────────────

    public function test_authority_user_can_access_search(): void
    {
        $this->actingAs($this->authorityUser)
            ->getJson('/api/v1/authority/search?last_name=Test')
            ->assertOk();
    }

    public function test_authority_user_can_access_watchlist(): void
    {
        $this->actingAs($this->authorityUser)
            ->getJson('/api/v1/authority/watchlist')
            ->assertOk();
    }

    // ── Authority User — forbidden ────────────────────────────────────────────

    public function test_authority_user_cannot_access_hotel_routes(): void
    {
        $this->actingAs($this->authorityUser)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertForbidden();
    }

    public function test_authority_user_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->authorityUser)
            ->getJson('/api/v1/admin/hotels')
            ->assertForbidden();
    }

    // ── Platform Admin — allowed ──────────────────────────────────────────────

    public function test_platform_admin_can_list_hotels(): void
    {
        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/admin/hotels')
            ->assertOk();
    }

    public function test_platform_admin_can_view_audit_logs(): void
    {
        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk();
    }

    // ── Platform Admin — forbidden ────────────────────────────────────────────

    public function test_platform_admin_cannot_access_hotel_routes(): void
    {
        // platform_admin has no hotel tenant assigned
        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/hotel/dashboard')
            ->assertForbidden();
    }

    public function test_platform_admin_cannot_access_authority_routes(): void
    {
        $this->actingAs($this->platformAdmin)
            ->getJson('/api/v1/authority/search?last_name=Test')
            ->assertForbidden();
    }

    // ── Watchlist write: authority-only ───────────────────────────────────────

    public function test_hotel_admin_cannot_create_watchlist_entry(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->postJson('/api/v1/authority/watchlist', [
                'document_number' => 'TN12345678',
                'severity'        => 'critique',
                'reason_code'     => 'AUTRE',
            ])
            ->assertForbidden();
    }

    // ── Token required for all protected routes ───────────────────────────────

    public function test_protected_route_with_no_token_returns_401(): void
    {
        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/api/v1/hotel/dashboard');

        $response->assertUnauthorized();
        $response->assertJson([
            'errors' => [['code' => 'UNAUTHENTICATED']],
        ]);
    }
}
