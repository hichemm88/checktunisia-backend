<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Packs d'abonnement : CRUD admin (protégé platform_admin) + endpoint public
 * consommé par la section tarifs de la homepage. Le contenu marketing est
 * trilingue (fr requis, en/ar optionnels).
 */
class PlanAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $platformAdmin;
    private User $hotelAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        $this->platformAdmin = User::factory()->platformAdmin()->create();
        $this->hotelAdmin    = User::factory()->hotelAdmin(\App\Models\Hotel::factory()->create())->create();
    }

    // ── Accès ────────────────────────────────────────────────────────────────

    public function test_plan_crud_requires_platform_admin(): void
    {
        $plan = SubscriptionPlan::first();

        $this->getJson('/api/v1/admin/plans')->assertUnauthorized();
        $this->actingAs($this->hotelAdmin)->getJson('/api/v1/admin/plans')->assertForbidden();
        $this->actingAs($this->hotelAdmin)->patchJson("/api/v1/admin/plans/{$plan->id}", ['name' => 'X'])->assertForbidden();
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function test_admin_can_update_plan_marketing_content(): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/plans/{$plan->id}", [
                'marketing' => [
                    'tier'         => ['fr' => 'Pro', 'en' => 'Pro', 'ar' => 'احترافي'],
                    'display_name' => ['fr' => 'Professionnel+', 'en' => 'Professional+', 'ar' => 'المحترف+'],
                    'tagline'      => ['fr' => 'Nouvelle tagline.', 'en' => null, 'ar' => null],
                    'featured'     => true,
                    'badge'        => ['fr' => 'Recommandé', 'en' => 'Recommended', 'ar' => 'موصى به'],
                    'cta_label'    => ['fr' => 'Démarrer', 'en' => 'Start', 'ar' => 'ابدأ'],
                    'bullets'      => [
                        ['included' => true,  'text' => ['fr' => 'Check-ins illimités', 'en' => 'Unlimited', 'ar' => null]],
                        ['included' => false, 'text' => ['fr' => 'Multi-établissements', 'en' => null, 'ar' => null]],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.marketing.display_name.fr', 'Professionnel+')
            ->assertJsonPath('data.marketing.bullets.1.included', false);
    }

    public function test_marketing_bullet_requires_french_text(): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();

        $this->actingAs($this->platformAdmin)
            ->patchJson("/api/v1/admin/plans/{$plan->id}", [
                'marketing' => ['bullets' => [['included' => true, 'text' => ['en' => 'No french']]]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.field', 'marketing.bullets.0.text.fr');
    }

    public function test_admin_can_create_and_delete_unused_plan(): void
    {
        $created = $this->actingAs($this->platformAdmin)
            ->postJson('/api/v1/admin/plans', [
                'name' => 'Saisonnier', 'slug' => 'saisonnier',
                'min_rooms' => 1, 'price_monthly' => 39,
                'marketing' => ['tagline' => ['fr' => 'Pour la haute saison.']],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($this->platformAdmin)
            ->deleteJson("/api/v1/admin/plans/{$created['id']}")
            ->assertNoContent();
    }

    public function test_plan_in_use_cannot_be_deleted(): void
    {
        $plan = SubscriptionPlan::where('slug', 'pro')->firstOrFail();
        $org  = \App\Models\Organization::create([
            'name' => 'Org Test', 'entity_type' => 'company',
            'contact_email' => 'org@test.tn', 'status' => 'active',
        ]);
        \App\Models\Subscription::create([
            'organization_id' => $org->id,
            'plan_id'         => $plan->id,
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now(),
            'expires_at'      => now()->addMonth(),
        ]);

        $this->actingAs($this->platformAdmin)
            ->deleteJson("/api/v1/admin/plans/{$plan->id}")
            ->assertUnprocessable();
    }

    // ── Public ───────────────────────────────────────────────────────────────

    public function test_public_plans_expose_marketing_and_exclude_inactive(): void
    {
        SubscriptionPlan::where('slug', 'essentiel')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/plans')->assertOk();
        $slugs = array_column($response->json('data'), 'slug');

        $this->assertNotContains('essentiel', $slugs);
        $this->assertContains('pro', $slugs);

        $pro = collect($response->json('data'))->firstWhere('slug', 'pro');
        $this->assertSame('Professionnel', $pro['marketing']['display_name']['fr']);
        $this->assertSame('Most popular', $pro['marketing']['badge']['en']);
        $this->assertNotEmpty($pro['marketing']['bullets']);
    }
}
