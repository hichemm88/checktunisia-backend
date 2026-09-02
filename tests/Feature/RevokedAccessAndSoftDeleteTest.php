<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;
use App\Models\AuthorityOrganization;
use App\Models\AuthorityUserProfile;

/**
 * Ce qui a été RETIRÉ doit cesser d'être accessible — tout de suite, et par
 * tous les chemins.
 *
 * Deux angles que les tests existants ne couvrent pas :
 *
 *  1. La RÉVOCATION. Un compte suspendu, rétrogradé ou supprimé garde un jeton
 *     valide jusqu'à son expiration (jusqu'à 8 h). Si le jeton continue
 *     d'ouvrir les portes, « suspendre » ne veut rien dire — c'est le geste
 *     qu'on fait justement quand on est pressé.
 *  2. La SUPPRESSION LOGIQUE. `CheckIn`, `Guest` et `Hotel` utilisent
 *     `SoftDeletes`. Les lignes restent en base : tout chemin de lecture qui
 *     oublie le filtre les ressert, y compris à une autorité. Un séjour effacé
 *     à la demande d'un client qui reste consultable est une promesse rompue.
 */
class RevokedAccessAndSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->admin = User::factory()->hotelAdmin($this->hotel)->create();
    }

    // ── Révocation ───────────────────────────────────────────────────────────

    public function test_suspending_a_user_kills_their_live_session(): void
    {
        $target = User::factory()->receptionist($this->hotel)->create();
        $token = $target->createToken('probe', ['*'])->plainTextToken;

        // Le jeton fonctionne avant la suspension : sans cette assertion, le
        // test pourrait passer parce que le jeton n'a jamais marché.
        $this->withToken($token)->getJson('/api/v1/hotel/dashboard')->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/hotel/users/{$target->id}", ['status' => 'suspended'])
            ->assertOk();

        $this->forgetAuth();
        $this->withToken($token)->getJson('/api/v1/hotel/dashboard')->assertUnauthorized();
    }

    public function test_deleting_a_user_kills_their_live_session(): void
    {
        $target = User::factory()->receptionist($this->hotel)->create();
        $token = $target->createToken('probe', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/hotel/dashboard')->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/hotel/users/{$target->id}")
            ->assertNoContent();

        $this->forgetAuth();
        $this->withToken($token)->getJson('/api/v1/hotel/dashboard')->assertUnauthorized();
    }

    public function test_demoting_an_admin_takes_effect_on_the_next_request(): void
    {
        $target = User::factory()->hotelAdmin($this->hotel)->create();
        $token = $target->createToken('probe', ['*'])->plainTextToken;

        // Route réservée à hotel_admin.
        $this->withToken($token)->getJson('/api/v1/hotel/users')->assertOk();

        $this->actingAs($this->admin)
            ->patchJson("/api/v1/hotel/users/{$target->id}", ['role' => 'receptionist'])
            ->assertOk();

        // Le rôle est relu à CHAQUE requête : la rétrogradation ne doit pas
        // attendre l'expiration du jeton. Un cache de permissions mal vidé se
        // verrait exactement ici.
        $this->forgetAuth();
        $this->withToken($token)->getJson('/api/v1/hotel/users')->assertForbidden();
    }

    // ── Suppression logique ──────────────────────────────────────────────────

    public function test_a_soft_deleted_check_in_is_gone_from_every_hotel_route(): void
    {
        $checkIn = $this->stayWithGuest();
        $checkIn->delete();

        $this->actingAs($this->admin)
            ->getJson("/api/v1/hotel/check-ins/{$checkIn->id}")
            ->assertNotFound();

        $ids = collect($this->actingAs($this->admin)->getJson('/api/v1/hotel/check-ins')->json('data'))
            ->pluck('id')->all();

        $this->assertNotContains($checkIn->id, $ids, 'un séjour supprimé reste listé');
    }

    public function test_a_soft_deleted_guest_is_gone_from_the_authority_portal(): void
    {
        $checkIn = $this->stayWithGuest();
        $guest = $checkIn->guests()->first();

        $officer = $this->ministryOfficer();

        // Visible tant qu'il existe — sans quoi le test ne prouverait rien.
        $this->actingAs($officer)->getJson("/api/v1/authority/guests/{$guest->id}")->assertOk();

        $guest->delete();

        $this->actingAs($officer)
            ->getJson("/api/v1/authority/guests/{$guest->id}")
            ->assertNotFound();

        // L'export PDF est le second chemin vers les mêmes données : il doit
        // disparaître en même temps.
        $this->actingAs($officer)
            ->get("/api/v1/authority/guests/{$guest->id}/export/pdf")
            ->assertNotFound();
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    /**
     * Oublie l'utilisateur resolu par `actingAs()`.
     *
     * Sans cela, `withToken()` ne serait pas pris en compte : la garde garde en
     * memoire l'utilisateur pose par le dernier `actingAs()`, et le test
     * mesurerait la session de l'ADMIN au lieu du jeton qu'il croit tester —
     * un faux negatif qui declarerait la revocation cassee alors qu'elle
     * fonctionne (ou l'inverse).
     */
    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function stayWithGuest(): CheckIn
    {
        $checkIn = CheckIn::factory()->for($this->hotel)->create([
            'created_by' => $this->admin->id,
            'status' => 'active',
        ]);

        $guest = Guest::factory()->create(['last_name' => 'EFFACABLE']);
        TravelDocument::create([
            'guest_id' => $guest->id,
            'type' => 'passport',
            'document_number' => 'SOFT-DEL-1',
            'issuing_country_code' => 'TUN',
        ]);
        $checkIn->guests()->attach($guest->id, ['is_primary' => true, 'added_by' => $this->admin->id]);

        return $checkIn;
    }

    private function ministryOfficer(): User
    {
        $org = AuthorityOrganization::create([
            'name' => 'Ministère (sonde)', 'type' => 'ministry', 'is_active' => true,
        ]);
        $officer = User::factory()->authorityUser($org)->create();
        AuthorityUserProfile::firstOrCreate(
            ['user_id' => $officer->id],
            ['organization_id' => $org->id, 'authorized_at' => now()],
        );
        $officer->forceFill([
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $officer;
    }
}
