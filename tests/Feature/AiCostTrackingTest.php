<?php

namespace Tests\Feature;

use App\Models\AiPricing;
use App\Models\AiUsageEvent;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tracking des couts IA (Claude vision : scan CIN + repli passeport).
 *
 * Couvre : ingestion interne (auth par secret, mapping etablissement, resolution
 * de l'operateur, snapshot du cout), ventilation par feature, guards admin, et
 * l'effet d'un changement de tarif sur les seuls nouveaux evenements.
 */
class AiCostTrackingTest extends TestCase
{
    use RefreshDatabase;

    private function tariff(float $in = 3, float $out = 15): AiPricing
    {
        return AiPricing::updateOrCreate(
            ['model' => 'claude-sonnet-5'],
            ['input_price_per_mtok_usd' => $in, 'output_price_per_mtok_usd' => $out, 'active' => true, 'updated_at' => now()],
        );
    }

    private function payload(Hotel $hotel, array $override = []): array
    {
        return array_merge([
            'feature' => 'cin_scan',
            'establishment_id' => $hotel->id,
            'model' => 'claude-sonnet-5',
            'input_tokens' => 1_000_000,
            'output_tokens' => 100_000,
            'status' => 'success',
            'latency_ms' => 1800,
        ], $override);
    }

    public function test_internal_ingest_requires_the_service_secret(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $hotel = Hotel::factory()->create();

        // Sans en-tete -> 401
        $this->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))->assertUnauthorized();

        // Mauvais secret -> 401
        $this->withHeader('Authorization', 'Bearer wrong')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))
            ->assertUnauthorized();

        $this->assertDatabaseCount('ai_usage_events', 0);
    }

    public function test_cin_success_records_one_event_mapped_to_the_hotel(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff();
        $hotel = Hotel::factory()->create();

        $this->withHeader('Authorization', 'Bearer right-secret')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))
            ->assertCreated()
            ->assertJsonPath('data.recorded', true);

        $this->assertDatabaseCount('ai_usage_events', 1);
        $event = AiUsageEvent::first();
        $this->assertSame('cin_scan', $event->feature);
        $this->assertSame($hotel->id, $event->hotel_id);
        // 1M in * $3/M + 100k out * $15/M = 3.00 + 1.50 = 4.50
        $this->assertSame('4.500000', $event->cost_usd);
    }

    public function test_dated_model_snapshot_resolves_to_the_alias_tariff(): void
    {
        // Tarif saisi sous l'alias ; l'API renvoie un snapshot daté.
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff(); // model = claude-sonnet-5
        $hotel = Hotel::factory()->create();

        $this->withHeader('Authorization', 'Bearer right-secret')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, ['model' => 'claude-sonnet-5-20260101']))
            ->assertCreated();

        // Le coût doit être calculé via le préfixe, pas figé à 0.
        $this->assertSame('4.500000', AiUsageEvent::first()->cost_usd);
    }

    public function test_passport_fallback_records_a_distinct_feature(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff();
        $hotel = Hotel::factory()->create();

        $this->withHeader('Authorization', 'Bearer right-secret')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, ['feature' => 'passport_scan']))
            ->assertCreated();

        $this->assertSame('passport_scan', AiUsageEvent::first()->feature);
    }

    public function test_api_error_costs_zero_and_parse_error_keeps_real_tokens(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff();
        $hotel = Hotel::factory()->create();
        $h = ['Authorization' => 'Bearer right-secret'];

        $this->withHeaders($h)->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, [
            'status' => 'api_error', 'input_tokens' => 0, 'output_tokens' => 0,
        ]))->assertCreated();

        $this->withHeaders($h)->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, [
            'status' => 'parse_error', 'input_tokens' => 200_000, 'output_tokens' => 10_000,
        ]))->assertCreated();

        $apiErr = AiUsageEvent::where('status', 'api_error')->first();
        $parseErr = AiUsageEvent::where('status', 'parse_error')->first();
        $this->assertSame('0.000000', $apiErr->cost_usd);
        // 200k * $3/M + 10k * $15/M = 0.60 + 0.15 = 0.75 (appel facturé même si inutilisable)
        $this->assertSame('0.750000', $parseErr->cost_usd);
    }

    public function test_ingest_resolves_operator_from_actor_token_never_the_traveller(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff();
        $hotel = Hotel::factory()->create();
        $operator = User::factory()->hotelAdmin($hotel)->create();
        $token = $operator->createToken('api-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer right-secret')
            ->withHeader('X-Actor-Token', $token)
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))
            ->assertCreated();

        $this->assertSame($operator->id, AiUsageEvent::first()->user_id);
    }

    public function test_summary_ventilates_by_feature_with_both_always_present(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $this->tariff();
        $hotel = Hotel::factory()->create();
        $admin = User::factory()->platformAdmin()->create();
        $h = ['Authorization' => 'Bearer right-secret'];

        $this->withHeaders($h)->postJson('/api/v1/internal/ai-usage', $this->payload($hotel)); // cin 4.50
        $this->withHeaders($h)->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, [
            'feature' => 'passport_scan', 'input_tokens' => 500_000, 'output_tokens' => 50_000,
        ])); // passport 2.25
        $this->withHeaders($h)->postJson('/api/v1/internal/ai-usage', $this->payload($hotel, [
            'status' => 'api_error', 'input_tokens' => 0, 'output_tokens' => 0,
        ]));

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/ai-costs/summary?period=current_month')
            ->assertOk()->json('data');

        $this->assertSame('6.750000', $data['total_cost_usd']);
        $this->assertTrue($data['pricing_configured']);
        $this->assertCount(2, $data['features']);

        $cin = collect($data['features'])->firstWhere('feature', 'cin_scan');
        $passport = collect($data['features'])->firstWhere('feature', 'passport_scan');
        $this->assertSame(1, $cin['success_count']);
        $this->assertSame(1, $cin['api_error_count']);
        $this->assertSame('4.500000', $cin['cost_usd']);
        $this->assertSame('2.250000', $passport['cost_usd']);
    }

    public function test_summary_flags_pricing_not_configured_when_tariff_is_zero(): void
    {
        $this->tariff(0, 0);
        $admin = User::factory()->platformAdmin()->create();

        $data = $this->actingAs($admin)->getJson('/api/v1/admin/ai-costs/summary')->assertOk()->json('data');
        $this->assertFalse($data['pricing_configured']);
    }

    public function test_admin_cost_endpoints_require_platform_admin(): void
    {
        $hotel = Hotel::factory()->create();
        $receptionist = User::factory()->receptionist($hotel)->create();

        $this->actingAs($receptionist)->getJson('/api/v1/admin/ai-costs/summary')->assertForbidden();
        $this->actingAs($receptionist)->getJson('/api/v1/admin/ai-pricing')->assertForbidden();
    }

    public function test_updating_tariff_changes_only_new_events(): void
    {
        config(['ai_tracking.secret' => 'right-secret']);
        $pricing = $this->tariff(3, 15);
        $hotel = Hotel::factory()->create();
        $admin = User::factory()->platformAdmin()->create();

        // Evenement au tarif initial -> 4.50, figé.
        $this->withHeader('Authorization', 'Bearer right-secret')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))->assertCreated();
        $old = AiUsageEvent::first();
        $this->assertSame('4.500000', $old->cost_usd);

        // L'admin double les tarifs via l'endpoint.
        $this->actingAs($admin)->putJson("/api/v1/admin/ai-pricing/{$pricing->id}", [
            'input_price_per_mtok_usd' => 6, 'output_price_per_mtok_usd' => 30,
        ])->assertOk()->assertJsonPath('data.input_price_per_mtok_usd', '6.0000');

        // L'ancien evenement n'est pas reecrit.
        $this->assertSame('4.500000', $old->fresh()->cost_usd);

        // Un nouvel evenement identique coute desormais le double (9.00).
        $this->withHeader('Authorization', 'Bearer right-secret')
            ->postJson('/api/v1/internal/ai-usage', $this->payload($hotel))->assertCreated();
        $new = AiUsageEvent::orderByDesc('created_at')->first();
        $this->assertSame('9.000000', $new->cost_usd);

        // L'edition du tarif est tracée dans l'audit.
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai_pricing.updated']);
    }
}
