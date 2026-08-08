<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscription\CheckinQuota;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migrations de la grille V3, jouées sur des données existantes.
 *
 * Les migrations de données ne s'exécutent que sur une base peuplée : la
 * suite normale (RefreshDatabase, base vierge) ne les traverse jamais. On
 * les rejoue donc ici explicitement, sur un jeu de données représentatif de
 * la production.
 *
 * Ce qui est protégé :
 *  - un client conserve EXACTEMENT le quota qui lui a été vendu ;
 *  - la reprise du registre ne prend que les fiches réellement déclarées ;
 *  - les deux migrations sont rejouables sans rien corrompre.
 */
class PricingV3MigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(string $file): void
    {
        (require database_path("migrations/{$file}.php"))->up();
    }

    /** Remet les packs dans l'état de la grille V2 (celui de la production avant ce chantier). */
    private function restoreV2Grid(): void
    {
        $v2 = [
            'essentiel' => ['quota' => 100, 'price' => 10.000, 'bundle' => 50],
            'pro'       => ['quota' => 600, 'price' => 10.000, 'bundle' => 50],
            'hotel'     => ['quota' => -1,  'price' => null,   'bundle' => null],
        ];

        foreach ($v2 as $slug => $attrs) {
            $plan     = SubscriptionPlan::where('slug', $slug)->firstOrFail();
            $features = (array) $plan->features;
            $features['checkins_per_month'] = $attrs['quota'];
            $plan->update([
                'features'            => $features,
                'overage_price'       => $attrs['price'],
                'overage_bundle_size' => $attrs['bundle'],
            ]);
        }
    }

    private function makeOrgOn(string $slug, string $name, array $subAttrs = []): Organization
    {
        $org = Organization::create([
            'name' => $name, 'entity_type' => 'company',
            'contact_email' => strtolower(str_replace(' ', '-', $name)).'@test.tn',
            'status' => 'active', 'locale' => 'fr',
        ]);

        Subscription::create(array_merge([
            'organization_id' => $org->id,
            'plan_id'         => SubscriptionPlan::where('slug', $slug)->value('id'),
            'status'          => 'active', 'billing_cycle' => 'monthly',
            'started_at'      => now()->subMonth(), 'expires_at' => now()->addMonth(),
        ], $subAttrs));

        return $org;
    }

    // ── Gel des quotas vendus ────────────────────────────────────────────────

    public function test_existing_customers_keep_the_quota_they_were_sold(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->restoreV2Grid();

        $pro   = $this->makeOrgOn('pro', 'MAISON PRO');       // vendu 600
        $hotel = $this->makeOrgOn('hotel', 'GRAND HOTEL');    // vendu illimité
        $petit = $this->makeOrgOn('essentiel', 'PETITE MAISON'); // vendu 100

        $this->assertSame(600, CheckinQuota::quota($pro));
        $this->assertNull(CheckinQuota::quota($hotel));

        $this->runMigration('2026_08_08_200003_pricing_v3_grid');

        // Le pack a changé…
        $this->assertSame(300, SubscriptionPlan::where('slug', 'pro')->value('features')['checkins_per_month']);
        $this->assertSame(1000, SubscriptionPlan::where('slug', 'hotel')->value('features')['checkins_per_month']);

        // …mais AUCUN client existant ne perd ce qu'il a acheté.
        $this->assertSame(600, CheckinQuota::quota($pro->fresh()), 'un Pro vendu 600 ne retombe pas à 300');
        $this->assertNull(CheckinQuota::quota($hotel->fresh()), 'un Grand Flux vendu illimité ne se voit pas plafonné');
        $this->assertSame(100, CheckinQuota::quota($petit->fresh()));
    }

    public function test_new_customers_get_the_v3_grid(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->restoreV2Grid();
        $this->makeOrgOn('pro', 'ANCIEN CLIENT');

        $this->runMigration('2026_08_08_200003_pricing_v3_grid');

        // Souscription postérieure à la migration : pas d'override, donc la
        // grille courante s'applique.
        $nouveau = $this->makeOrgOn('pro', 'NOUVEAU CLIENT');
        $this->assertSame(300, CheckinQuota::quota($nouveau));
    }

    public function test_the_grid_migration_never_overwrites_a_negotiated_deal(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->restoreV2Grid();

        $negocie = $this->makeOrgOn('essentiel', 'DEAL NEGOCIE', [
            'metadata' => ['feature_overrides' => ['checkins_per_month' => 250, 'max_users' => 9]],
        ]);

        $this->runMigration('2026_08_08_200003_pricing_v3_grid');

        $overrides = $negocie->activeSubscription()->first()->metadata['feature_overrides'];
        $this->assertSame(250, $overrides['checkins_per_month']);
        $this->assertSame(9, $overrides['max_users']);
    }

    public function test_the_grid_migration_is_idempotent(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->restoreV2Grid();
        $pro = $this->makeOrgOn('pro', 'MAISON PRO');

        $this->runMigration('2026_08_08_200003_pricing_v3_grid');
        $this->runMigration('2026_08_08_200003_pricing_v3_grid');
        $this->runMigration('2026_08_08_200003_pricing_v3_grid');

        // Le gel ne « suit » pas la nouvelle valeur du pack au fil des relances.
        $this->assertSame(600, CheckinQuota::quota($pro->fresh()));
        $this->assertSame(300, SubscriptionPlan::where('slug', 'pro')->value('features')['checkins_per_month']);
    }

    public function test_the_v3_grid_matches_the_target_pricing(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->restoreV2Grid();
        $this->runMigration('2026_08_08_200003_pricing_v3_grid');

        $expected = [
            'essentiel' => ['price' => '59.000',  'quota' => 100,  'overage' => '0.600'],
            'pro'       => ['price' => '119.000', 'quota' => 300,  'overage' => '0.400'],
            'hotel'     => ['price' => '299.000', 'quota' => 1000, 'overage' => '0.250'],
        ];

        foreach ($expected as $slug => $want) {
            $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
            $this->assertSame($want['price'], (string) $plan->price_monthly, "prix {$slug}");
            $this->assertSame($want['quota'], $plan->features['checkins_per_month'], "quota {$slug}");
            $this->assertSame($want['overage'], (string) $plan->overage_price, "dépassement {$slug}");
            $this->assertSame(1, (int) $plan->overage_bundle_size, "tranche {$slug}");
        }
    }

    // ── Reprise du registre de consommation ──────────────────────────────────

    public function test_backfill_takes_declared_checkins_only(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $org   = $this->makeOrgOn('essentiel', 'DAR OMI');
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $user  = User::factory()->create(['organization_id' => $org->id]);

        $make = fn (array $attrs) => CheckIn::factory()->create(array_merge([
            'hotel_id' => $hotel->id, 'created_by' => $user->id,
        ], $attrs));

        $make(['status' => 'active',    'completed_at' => now()->subDays(2)]);
        $make(['status' => 'completed', 'completed_at' => now()->subDays(3)]);
        $make(['status' => 'cancelled', 'completed_at' => now()->subDays(4)]); // déclarée puis annulée
        $make(['status' => 'draft',     'completed_at' => null]);              // jamais déclarée
        $make(['status' => 'draft',     'completed_at' => null]);
        $supprimee = $make(['status' => 'active', 'completed_at' => now()->subDay()]);
        $supprimee->delete();

        // La reprise a déjà tourné avec la suite de migrations : on repart de zéro.
        DB::table('checkin_usage_events')->delete();
        $this->runMigration('2026_08_08_200002_backfill_checkin_usage_ledger');

        // 3 fiches déclarées ; les 2 brouillons et la fiche supprimée sont hors registre.
        $this->assertSame(3, CheckinUsageEvent::count());
        $this->assertSame(3, CheckinQuota::usedInMonth($org));

        // L'annulation postérieure est datée sans annuler la consommation.
        $this->assertSame(1, CheckinQuota::cancelledInMonth($org));
    }

    public function test_backfill_is_replayable_without_duplicating(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $org   = $this->makeOrgOn('essentiel', 'DAR OMI');
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $user  = User::factory()->create(['organization_id' => $org->id]);

        CheckIn::factory()->count(4)->create([
            'hotel_id' => $hotel->id, 'created_by' => $user->id,
            'status' => 'active', 'completed_at' => now()->subDay(),
        ]);

        DB::table('checkin_usage_events')->delete();
        $this->runMigration('2026_08_08_200002_backfill_checkin_usage_ledger');
        $this->runMigration('2026_08_08_200002_backfill_checkin_usage_ledger');
        $this->runMigration('2026_08_08_200002_backfill_checkin_usage_ledger');

        $this->assertSame(4, CheckinUsageEvent::count());
        $this->assertSame(4, CheckinQuota::usedInMonth($org));
    }

    public function test_backfill_dates_each_consumption_on_its_own_month(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $org   = $this->makeOrgOn('essentiel', 'DAR OMI');
        $hotel = Hotel::factory()->create(['organization_id' => $org->id]);
        $user  = User::factory()->create(['organization_id' => $org->id]);

        CheckIn::factory()->count(2)->create([
            'hotel_id' => $hotel->id, 'created_by' => $user->id,
            'status' => 'active', 'completed_at' => now()->startOfMonth()->addDays(2),
        ]);
        CheckIn::factory()->count(5)->create([
            'hotel_id' => $hotel->id, 'created_by' => $user->id,
            'status' => 'active', 'completed_at' => now()->subMonthNoOverflow()->startOfMonth()->addDays(2),
        ]);

        DB::table('checkin_usage_events')->delete();
        $this->runMigration('2026_08_08_200002_backfill_checkin_usage_ledger');

        $this->assertSame(2, CheckinQuota::usedInMonth($org));
        $this->assertSame(5, CheckinQuota::usedInMonth($org, now()->subMonthNoOverflow()));
    }
}
