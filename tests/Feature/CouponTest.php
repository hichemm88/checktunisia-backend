<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\CouponException;
use App\Services\Billing\CouponService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Codes promo : CRUD admin, calcul de la remise, application a une facture
 * (total reduit, redemption tracee, quota incremente) et rejet des codes
 * invalides.
 */
class CouponTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(array $override = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'  => 'WELCOME10',
            'type'  => Coupon::TYPE_PERCENT,
            'value' => 10,
            'active' => true,
        ], $override));
    }

    private function orgWithSub(): array
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $org = Organization::create([
            'name' => 'Kasbahost', 'entity_type' => 'company',
            'contact_email' => 'kasba@test.tn', 'status' => 'active',
        ]);
        $sub = Subscription::create([
            'organization_id' => $org->id,
            'plan_id'         => SubscriptionPlan::query()->orderBy('sort_order')->value('id'),
            'status'          => 'active',
            'billing_cycle'   => 'monthly',
            'started_at'      => now()->subMonth(),
            'expires_at'      => now()->addMonth(),
        ]);

        return [$org, $sub];
    }

    // ─── Guards + CRUD ────────────────────────────────────────────────────

    public function test_coupon_endpoints_require_platform_admin(): void
    {
        $hotel = Hotel::factory()->create();
        $receptionist = User::factory()->receptionist($hotel)->create();

        $this->actingAs($receptionist)->getJson('/api/v1/admin/coupons')->assertForbidden();
        $this->actingAs($receptionist)->postJson('/api/v1/admin/coupons', [])->assertForbidden();
    }

    public function test_admin_creates_coupon_with_uppercased_code(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/coupons', [
            'code' => 'ete-2026', 'type' => 'percent', 'value' => 15,
        ])->assertCreated()->assertJsonPath('data.code', 'ETE-2026');

        $this->assertDatabaseHas('coupons', ['code' => 'ETE-2026']);
    }

    public function test_percent_over_100_is_rejected(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/coupons', [
            'code' => 'TOOBIG', 'type' => 'percent', 'value' => 150,
        ])->assertStatus(422)->assertJsonPath('errors.0.field', 'value');
    }

    public function test_used_coupon_is_deactivated_not_deleted(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $coupon = $this->coupon();
        CouponRedemption::create(['coupon_id' => $coupon->id, 'amount_discounted' => 5, 'created_at' => now()]);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/coupons/{$coupon->id}")
            ->assertOk()->assertJsonPath('data.deactivated', true);

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'active' => false]);
    }

    // ─── Calcul de la remise ──────────────────────────────────────────────

    public function test_discount_computation_percent_and_fixed(): void
    {
        $svc = app(CouponService::class);

        $this->assertSame(10.0, $svc->discountFor($this->coupon(['type' => 'percent', 'value' => 10]), 100));
        $this->assertSame(25.0, $svc->discountFor($this->coupon(['code' => 'F25', 'type' => 'fixed', 'value' => 25]), 100));

        // La remise fixe ne peut pas depasser le montant (pas de total negatif).
        $this->assertSame(100.0, $svc->discountFor($this->coupon(['code' => 'F999', 'type' => 'fixed', 'value' => 999]), 100));
    }

    // ─── Application a une facture ────────────────────────────────────────

    public function test_apply_coupon_to_invoice_reduces_total_and_tracks_redemption(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$org, $sub] = $this->orgWithSub();
        $coupon = $this->coupon(['type' => 'percent', 'value' => 20]);

        $data = $this->actingAs($admin)->postJson("/api/v1/admin/hosts/{$org->id}/invoices", [
            'subscription_id' => $sub->id,
            'amount'          => 100,
            'tax_amount'      => 19,
            'coupon_code'     => 'welcome10', // saisie en minuscules -> resolue quand meme
        ])->assertCreated()->json('data');

        // 20 % de 100 = 20 ; total = 100 + 19 - 20 = 99.
        $this->assertSame('20.000', (string) $data['discount_amount']);
        $this->assertSame('WELCOME10', $data['coupon_code']);
        $this->assertSame('99.000', (string) $data['total_amount']);

        $this->assertDatabaseHas('coupon_redemptions', [
            'coupon_id' => $coupon->id, 'invoice_id' => $data['id'], 'amount_discounted' => 20,
        ]);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_invalid_coupon_rejects_invoice_creation(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        [$org, $sub] = $this->orgWithSub();
        $this->coupon(['active' => false]); // desactive

        $this->actingAs($admin)->postJson("/api/v1/admin/hosts/{$org->id}/invoices", [
            'subscription_id' => $sub->id, 'amount' => 100, 'coupon_code' => 'WELCOME10',
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'INVALID_COUPON');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_validate_throws_for_expired_and_exhausted(): void
    {
        $svc = app(CouponService::class);

        try {
            $svc->validate('EXPIRED', 100);
            $this->fail('expected not_found');
        } catch (CouponException $e) {
            $this->assertSame('not_found', $e->reason);
        }

        $this->coupon(['code' => 'EXP', 'expires_at' => now()->subDay()]);
        $this->assertSame('expired', $this->captureReason(fn () => $svc->validate('EXP', 100)));

        $this->coupon(['code' => 'MAXED', 'max_uses' => 1, 'used_count' => 1]);
        $this->assertSame('exhausted', $this->captureReason(fn () => $svc->validate('MAXED', 100)));

        $this->coupon(['code' => 'MIN50', 'min_amount' => 50]);
        $this->assertSame('below_min', $this->captureReason(fn () => $svc->validate('MIN50', 20)));
    }

    private function captureReason(callable $fn): string
    {
        try {
            $fn();
        } catch (CouponException $e) {
            return $e->reason;
        }

        return 'no_exception';
    }
}
