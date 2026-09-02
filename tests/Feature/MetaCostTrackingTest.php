<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappBillableMessage;
use App\Models\WhatsappMessageCost;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\WhatsappCostRecorder;
use App\Services\Whatsapp\WhatsappOtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Suivi des coûts Meta / WhatsApp.
 *
 * Ce qui est vérifié ici n'est pas « le total s'affiche », mais les cinq
 * façons dont ce total pourrait être FAUX sans que personne ne le voie :
 *
 *  - compter au `sent` au lieu du `delivered` (surestimation systématique,
 *    exactement de la part du trafic qui n'arrive jamais) ;
 *  - compter deux fois le même message parce que Meta rejoue ses webhooks ;
 *  - imputer à un établissement un coût qu'il n'a pas causé (les codes de
 *    connexion appartiennent au compte autorité) ;
 *  - appliquer un tarif figé dans le code, insensible aux variables d'env ;
 *  - présenter une estimation comme un montant réel Meta, ou tomber en panne
 *    parce que Meta ne répond pas.
 */
class MetaCostTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->create(['name' => 'Dar Test']);

        config([
            'whatsapp.enabled' => true,
            'whatsapp.channel' => 'cloud',
            'whatsapp.cloud.token' => 'test-token',
            'whatsapp.cloud.phone_number_id' => '123456',
            'whatsapp.cloud.waba_id' => 'waba-1',
            'whatsapp.cloud.app_secret' => 'app-secret',
            'whatsapp.cloud.template.name' => 'fiche_police_v2',
            'whatsapp.cloud.template.language' => 'fr',
            'whatsapp.cloud.template.otp_name' => 'qayed_otp',
            'whatsapp.cloud.template.otp_language' => 'fr',
            'whatsapp.pricing.rates.utility' => 0.0080,
            'whatsapp.pricing.rates.authentication' => 0.0077,
            'whatsapp.pricing.rates.marketing' => 0.0448,
            'whatsapp.pricing.rates.service' => 0.0,
            'whatsapp.pricing.usd_to_tnd' => 3.05,
        ]);
    }

    // ── Comptage ─────────────────────────────────────────────────────────────

    public function test_a_sent_message_costs_nothing_until_it_is_delivered(): void
    {
        $job = $this->sentJob('wamid.A');

        // `sent` est l'accusé d'ACCEPTATION de Meta, pas une livraison. Le
        // compter reviendrait à facturer les numéros morts et bloqués.
        $this->postSigned($this->statusPayload('wamid.A', 'sent'))->assertOk();

        $this->assertSame(0, WhatsappMessageCost::count());
        $this->assertNull(WhatsappBillableMessage::find('wamid.A')?->counted_at);

        $this->postSigned($this->statusPayload('wamid.A', 'delivered'))->assertOk();

        $row = WhatsappMessageCost::sole();
        $this->assertSame('utility', $row->category);
        $this->assertSame(1, $row->messages);
        $this->assertSame('0.008000', $row->cost_usd);
        $this->assertSame(WhatsappMessageCost::SOURCE_ESTIMATE, $row->source);
        $this->assertSame($this->hotel->id, $row->hotel_id);
    }

    public function test_a_failed_delivery_is_never_billed(): void
    {
        $this->sentJob('wamid.KO');

        $this->postSigned([
            'entry' => [['changes' => [['value' => ['statuses' => [[
                'id' => 'wamid.KO',
                'status' => 'failed',
                'errors' => [['code' => 131026, 'title' => 'Receiver incapable']],
            ]]]]]]],
        ])->assertOk();

        $this->assertSame(0, WhatsappMessageCost::count());
    }

    public function test_replayed_delivered_webhooks_are_counted_once(): void
    {
        $this->sentJob('wamid.REPLAY');

        // Meta rejoue toute livraison non acquittée, et rejoue aussi après ses
        // propres incidents. Un coût surcompté est pire qu'un coût absent : il
        // a l'air juste.
        foreach (range(1, 4) as $ignored) {
            $this->postSigned($this->statusPayload('wamid.REPLAY', 'delivered'))->assertOk();
        }

        $row = WhatsappMessageCost::sole();
        $this->assertSame(1, $row->messages);
        $this->assertSame('0.008000', $row->cost_usd);
    }

    public function test_a_read_after_a_delivered_does_not_bill_twice(): void
    {
        $this->sentJob('wamid.READ');

        $this->postSigned($this->statusPayload('wamid.READ', 'delivered'))->assertOk();
        $this->postSigned($this->statusPayload('wamid.READ', 'read'))->assertOk();

        $this->assertSame(1, WhatsappMessageCost::sole()->messages);
    }

    public function test_a_read_arriving_without_a_delivered_still_bills_once(): void
    {
        $this->sentJob('wamid.ONLYREAD');

        // Meta ne garantit pas l'ordre des accusés. Un message lu a forcément
        // été livré : ne pas le compter creuserait un trou silencieux.
        $this->postSigned($this->statusPayload('wamid.ONLYREAD', 'read'))->assertOk();

        $this->assertSame(1, WhatsappMessageCost::sole()->messages);
    }

    public function test_two_deliveries_on_the_same_day_share_one_aggregate_row(): void
    {
        $this->sentJob('wamid.D1');
        $this->sentJob('wamid.D2');

        $this->postSigned($this->statusPayload('wamid.D1', 'delivered'))->assertOk();
        $this->postSigned($this->statusPayload('wamid.D2', 'delivered'))->assertOk();

        $row = WhatsappMessageCost::sole();
        $this->assertSame(2, $row->messages);
        $this->assertSame('0.016000', $row->cost_usd);
    }

    // ── Attribution ──────────────────────────────────────────────────────────

    public function test_each_establishment_carries_its_own_cost(): void
    {
        $other = Hotel::factory()->create(['name' => 'Dar Autre']);

        $this->sentJob('wamid.H1');
        $this->sentJob('wamid.H2', $other);
        $this->sentJob('wamid.H3', $other);

        foreach (['wamid.H1', 'wamid.H2', 'wamid.H3'] as $wamid) {
            $this->postSigned($this->statusPayload($wamid, 'delivered'))->assertOk();
        }

        $admin = User::factory()->platformAdmin()->create();
        $rows = $this->actingAs($admin)
            ->getJson('/api/v1/admin/meta-costs/by-establishment?period=current_month')
            ->assertOk()->json('data');

        $mine = collect($rows)->firstWhere('establishment_id', $this->hotel->id);
        $theirs = collect($rows)->firstWhere('establishment_id', $other->id);

        $this->assertSame('Dar Test', $mine['establishment_name']);
        $this->assertSame(1, $mine['utility_messages']);
        $this->assertSame('0.008000', $mine['cost_usd']);
        $this->assertSame(2, $theirs['utility_messages']);
        $this->assertSame('0.016000', $theirs['cost_usd']);

        // Le plus cher d'abord : c'est un écran de marge, pas un annuaire.
        $this->assertSame($other->id, $rows[0]['establishment_id']);
    }

    public function test_an_otp_is_billed_as_authentication_and_to_no_establishment(): void
    {
        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.OTP']]], 200),
        ]);

        app(WhatsappOtpSender::class)->send('21620123456', '123456');

        $entry = WhatsappBillableMessage::sole();
        $this->assertSame('authentication', $entry->category);
        // Un code appartient au compte autorité qui se connecte. L'imputer à
        // un établissement gonflerait sa marge apparente d'un coût qu'il n'a
        // pas causé.
        $this->assertNull($entry->hotel_id);
        $this->assertNull($entry->send_log_id);

        // Aucune ligne d'outbox : le webhook ne trouve rien à mettre à jour, et
        // c'est pourtant le seul endroit où ce coût est observable.
        $this->postSigned($this->statusPayload('wamid.OTP', 'delivered'))->assertOk();

        $row = WhatsappMessageCost::sole();
        $this->assertSame('authentication', $row->category);
        $this->assertNull($row->hotel_id);
        $this->assertSame('0.007700', $row->cost_usd);
    }

    public function test_a_status_for_an_unknown_message_bills_nothing(): void
    {
        // Un autre système partageant le numéro, ou une ligne purgée : on
        // n'invente pas un coût qu'on ne sait pas rattacher.
        $this->postSigned($this->statusPayload('wamid.STRANGER', 'delivered'))->assertOk();

        $this->assertSame(0, WhatsappMessageCost::count());
    }

    // ── Tarifs ───────────────────────────────────────────────────────────────

    public function test_rates_come_from_configuration_not_from_code(): void
    {
        config([
            'whatsapp.pricing.rates.utility' => 0.0123,
            'whatsapp.pricing.rates.authentication' => 0.0456,
        ]);

        $this->sentJob('wamid.RATE');
        $this->postSigned($this->statusPayload('wamid.RATE', 'delivered'))->assertOk();

        $this->assertSame('0.012300', WhatsappMessageCost::sole()->cost_usd);

        $recorder = app(WhatsappCostRecorder::class);
        $this->assertSame(0.0456, $recorder->rateFor('authentication'));
        // Les conversations de service restent gratuites jusqu'au 01/10/2026 :
        // la catégorie existe, son tarif est à 0.
        $this->assertSame(0.0, $recorder->rateFor('service'));
    }

    public function test_the_category_is_derived_from_the_template(): void
    {
        $recorder = app(WhatsappCostRecorder::class);

        $this->assertSame('utility', $recorder->categoryForTemplate('fiche_police_v2'));
        $this->assertSame('authentication', $recorder->categoryForTemplate('qayed_otp'));
        // Modèle inconnu : `utility` — la seule valeur par défaut qui ne fasse
        // pas apparaître un coût marketing là où il n'y en a pas.
        $this->assertSame('utility', $recorder->categoryForTemplate('un_modele_jamais_vu'));

        config(['whatsapp.pricing.template_categories' => ['promo_ete' => 'marketing']]);
        $this->assertSame('marketing', $recorder->categoryForTemplate('promo_ete'));
    }

    public function test_a_cost_is_frozen_at_the_rate_of_the_moment(): void
    {
        $this->sentJob('wamid.FROZEN');
        $this->postSigned($this->statusPayload('wamid.FROZEN', 'delivered'))->assertOk();

        // Révision de tarif APRÈS coup : l'historique ne bouge pas, comme pour
        // les coûts IA.
        config(['whatsapp.pricing.rates.utility' => 0.5]);

        $this->assertSame('0.008000', WhatsappMessageCost::sole()->cost_usd);
        $this->assertSame('0.008000', WhatsappBillableMessage::find('wamid.FROZEN')->cost_usd);
    }

    // ── Endpoints admin ──────────────────────────────────────────────────────

    public function test_the_summary_splits_by_category_and_reports_its_source(): void
    {
        $this->sentJob('wamid.S1');
        $this->postSigned($this->statusPayload('wamid.S1', 'delivered'))->assertOk();

        $admin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/meta-costs/summary?period=current_month')
            ->assertOk()->json('data');

        $this->assertSame('estimate', $data['source']);
        $this->assertSame('0.008000', $data['total_cost_usd']);
        $this->assertSame(1, $data['total_messages']);
        $this->assertTrue($data['pricing_configured']);
        // Aucune synchro n'a tourné : le champ le dit, il n'invente pas de date.
        $this->assertNull($data['last_meta_sync_at']);

        // Les quatre catégories, toujours, même à zéro (comme les coûts IA).
        $this->assertCount(4, $data['categories']);
        $utility = collect($data['categories'])->firstWhere('category', 'utility');
        $marketing = collect($data['categories'])->firstWhere('category', 'marketing');
        $this->assertSame(1, $utility['messages']);
        $this->assertSame(0, $marketing['messages']);

        // Conversion d'affichage : le stockage reste en USD.
        $this->assertSame('3.0500', $data['usd_to_tnd']);
        $this->assertSame('0.024', $data['total_cost_tnd']);
    }

    public function test_the_daily_series_is_continuous_and_zero_filled(): void
    {
        $this->sentJob('wamid.DAY');
        $this->postSigned($this->statusPayload('wamid.DAY', 'delivered'))->assertOk();

        $admin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/meta-costs/daily?days=7')
            ->assertOk()->json('data');

        // Un graphe à trous se lit comme une panne : la série porte les 7 jours.
        $this->assertCount(7, $data['series']);
        $this->assertSame(now()->toDateString(), end($data['series'])['date']);
        $this->assertSame(1, end($data['series'])['utility_count']);
        $this->assertSame(0, $data['series'][0]['utility_count']);
        $this->assertSame('0.000000', $data['series'][0]['total_cost_usd']);
    }

    public function test_cost_endpoints_require_platform_admin(): void
    {
        $receptionist = User::factory()->receptionist($this->hotel)->create();

        $this->actingAs($receptionist)->getJson('/api/v1/admin/meta-costs/summary')->assertForbidden();
        $this->actingAs($receptionist)->getJson('/api/v1/admin/meta-costs/daily')->assertForbidden();
        $this->actingAs($receptionist)->getJson('/api/v1/admin/meta-costs/by-establishment')->assertForbidden();
    }

    public function test_the_business_kpis_carry_the_current_month_meta_cost(): void
    {
        $this->sentJob('wamid.KPI');
        $this->postSigned($this->statusPayload('wamid.KPI', 'delivered'))->assertOk();

        $admin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($admin)->getJson('/api/v1/admin/metrics/kpis')->assertOk()->json('data');

        // Le bloc porte sa devise : le reste du payload KPI est en TND.
        $this->assertSame('USD', $data['meta_costs']['currency']);
        $this->assertSame('0.008000', $data['meta_costs']['cost_usd']);
        $this->assertSame(1, $data['meta_costs']['messages']);
        $this->assertSame('estimate', $data['meta_costs']['source']);
    }

    // ── Synchronisation Meta ─────────────────────────────────────────────────

    public function test_meta_amounts_replace_the_local_estimate(): void
    {
        $this->sentJob('wamid.SYNC');
        $this->postSigned($this->statusPayload('wamid.SYNC', 'delivered'))->assertOk();

        Http::fake([
            '*/waba-1*' => Http::response(['pricing_analytics' => ['data_points' => [
                [
                    'start' => now()->startOfDay()->timestamp,
                    'end' => now()->endOfDay()->timestamp,
                    'pricing_category' => 'UTILITY',
                    'volume' => 12,
                    'cost' => 0.0912,
                ],
            ]]], 200),
        ]);

        $this->artisan('whatsapp:sync-costs')->assertSuccessful();

        $admin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($admin)->getJson('/api/v1/admin/meta-costs/summary')->assertOk()->json('data');

        // Meta fait autorité dès qu'il a répondu : ses 0,0912 $ remplacent
        // l'estimation de 0,008 $, et l'écran dit lequel il montre.
        $this->assertSame('meta', $data['source']);
        $this->assertSame('0.091200', $data['total_cost_usd']);
        $this->assertSame(12, $data['total_messages']);
        $this->assertNotNull($data['last_meta_sync_at']);

        // L'estimation locale n'est pas effacée pour autant : c'est la seule
        // ventilation par établissement dont nous disposions.
        $this->assertTrue(
            WhatsappMessageCost::where('source', WhatsappMessageCost::SOURCE_ESTIMATE)->exists()
        );
    }

    public function test_the_sync_is_idempotent(): void
    {
        Http::fake([
            '*/waba-1*' => Http::response(['pricing_analytics' => ['data_points' => [[
                'start' => now()->startOfDay()->timestamp,
                'pricing_category' => 'AUTHENTICATION',
                'volume' => 5,
                'cost' => 0.0385,
            ]]]], 200),
        ]);

        // Les analytics sont un ÉTAT consolidé, pas un flux d'événements :
        // rejouer la synchro doit donner le même total, pas le double.
        $this->artisan('whatsapp:sync-costs')->assertSuccessful();
        $this->artisan('whatsapp:sync-costs')->assertSuccessful();

        $row = WhatsappMessageCost::where('source', WhatsappMessageCost::SOURCE_META)->sole();
        $this->assertSame(5, $row->messages);
        $this->assertSame('0.038500', $row->cost_usd);
    }

    public function test_a_missing_meta_endpoint_leaves_the_estimate_untouched(): void
    {
        $this->sentJob('wamid.NOSYNC');
        $this->postSigned($this->statusPayload('wamid.NOSYNC', 'delivered'))->assertOk();

        // `pricing_analytics` n'est pas garanti : version d'API, type de
        // compte, permissions du jeton. Son absence n'est pas une panne.
        Http::fake([
            '*/waba-1*' => Http::response(['error' => [
                'code' => 100,
                'message' => 'Unsupported get request.',
            ]], 400),
        ]);

        $this->artisan('whatsapp:sync-costs')->assertSuccessful();

        $admin = User::factory()->platformAdmin()->create();
        $data = $this->actingAs($admin)->getJson('/api/v1/admin/meta-costs/summary')->assertOk()->json('data');

        $this->assertSame('estimate', $data['source']);
        $this->assertSame('0.008000', $data['total_cost_usd']);
        $this->assertSame(0, WhatsappMessageCost::where('source', WhatsappMessageCost::SOURCE_META)->count());
    }

    public function test_the_sync_does_nothing_without_a_waba(): void
    {
        config(['whatsapp.cloud.waba_id' => null]);
        Http::fake();

        $this->artisan('whatsapp:sync-costs')->assertSuccessful();

        // Sans compte à interroger, il n'y a pas d'appel à faire — et surtout
        // pas d'échec à alerter chaque nuit.
        Http::assertNothingSent();
    }

    public function test_the_sync_can_be_switched_off(): void
    {
        config(['whatsapp.pricing.sync.enabled' => false]);
        Http::fake();

        $this->artisan('whatsapp:sync-costs')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Utilitaires ──────────────────────────────────────────────────────────

    private function sentJob(string $wamid, ?Hotel $hotel = null): WhatsappSendLog
    {
        $job = WhatsappSendLog::create([
            'hotel_id' => ($hotel ?? $this->hotel)->id,
            'recipient' => '21611111111',
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_SENT,
            'message_id_whatsapp' => $wamid,
            'template_name' => 'fiche_police_v2',
            'queued_at' => now(),
            'sent_at' => now(),
        ]);

        // Le registre est normalement alimenté par markSent(). On passe par le
        // même service ici plutôt que d'insérer à la main : c'est ce chemin-là
        // qui doit rester juste.
        app(WhatsappCostRecorder::class)->registerFicheSend($job, $wamid);

        return $job;
    }

    /** @return array<string,mixed> */
    private function statusPayload(string $wamid, string $status): array
    {
        return ['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) now()->timestamp,
        ]]]]]]]];
    }

    /** @param  array<string,mixed>  $payload */
    private function postSigned(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }
}
