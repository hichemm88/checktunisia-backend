<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PartnerApi\Concerns\BuildsPartnerFiches;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * v1.2 — GET /v1/fiches : liste des fiches d'un établissement lié (API +
 * saisies dans Qayed), pagination par curseur, filtres, périmètre test/live.
 */
class FicheListTest extends TestCase
{
    use BuildsPartnerFiches;
    use InteractsWithPartnerApi;
    use RefreshDatabase;

    private const ITEM_KEYS = [
        'fiche_id', 'establishment_id', 'booking_ref', 'room_id', 'room', 'arrival_date',
        'departure_date', 'guest_count', 'primary_guest_name', 'status', 'source',
        'operator_user_id', 'submitted_at', 'delivery_status',
    ];

    private function list(string $key, array $query): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->bearer($key))->getJson('/v1/fiches?'.http_build_query($query));
    }

    public function test_lists_native_and_own_api_fiches_with_only_safe_fields(): void
    {
        ['hotel' => $hotel, 'partner' => $partner, 'plaintext' => $key] = $this->setUpLinkedPartner();

        $guest = Guest::factory()->named('Sami', 'Trabelsi')->create(['date_of_birth' => '1979-04-23']);
        $this->giveDocument($guest, 'ZX9988776');
        $native = $this->makeNativeFiche($hotel, [], $guest);
        $this->attachGuest($native, Guest::factory()->named('Leila', 'Trabelsi')->create());

        $api = $this->makeApiFiche($hotel, $partner, 'BK-LIST-1');

        // Exclus : brouillon, fiche de test, fiche API d'un autre partenaire,
        // fiche d'un autre établissement.
        $this->makeNativeFiche($hotel, ['status' => 'draft', 'completed_at' => null, 'completed_by' => null]);
        $this->makeApiFiche($hotel, $partner, 'BK-LIST-TEST', testMode: true);
        $this->makeApiFiche($hotel, $this->makePartner(['name' => 'Autre PMS']), 'BK-OTHER');
        $this->makeNativeFiche(Hotel::factory()->create());

        $res = $this->list($key, ['establishment_id' => $hotel->id])->assertOk();

        $res->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', null);
        $items = collect($res->json('data'))->keyBy('fiche_id');
        $this->assertEqualsCanonicalizing([$native->id, $api->id], $items->keys()->all());

        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(self::ITEM_KEYS, array_keys($item));
        }

        $n = $items[$native->id];
        $this->assertSame('qayed', $n['source']);
        $this->assertNull($n['booking_ref']);
        $this->assertSame('Sami Trabelsi', $n['primary_guest_name']);
        $this->assertSame(2, $n['guest_count']);
        $this->assertSame('active', $n['status']);
        $this->assertSame($native->completed_by, $n['operator_user_id']);
        $this->assertNotNull($n['submitted_at']);

        $a = $items[$api->id];
        $this->assertSame('api', $a['source']);
        $this->assertSame('BK-LIST-1', $a['booking_ref']);
        $this->assertSame('Suite Jasmin', $a['room']);

        // Aucun numéro de document, aucune date de naissance.
        $raw = $res->getContent();
        $this->assertStringNotContainsString('ZX9988776', $raw);
        $this->assertStringNotContainsString('1979-04-23', $raw);
        $this->assertStringNotContainsString('date_of_birth', $raw);
        $this->assertStringNotContainsString('document', $raw);
    }

    public function test_unlinked_or_revoked_establishment_is_refused_like_other_routes(): void
    {
        ['hotel' => $hotel, 'link' => $link, 'plaintext' => $key] = $this->setUpLinkedPartner();
        $other = Hotel::factory()->withActiveSubscription()->create();
        $this->makeNativeFiche($other);

        $this->list($key, ['establishment_id' => $other->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');

        // Même réponse que GET /v1/establishments/{id}/rooms.
        $this->withHeaders($this->bearer($key))->getJson("/v1/establishments/{$other->id}/rooms")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');

        $link->update(['revoked_at' => now()]);
        $this->list($key, ['establishment_id' => $hotel->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'establishment_not_linked');

        $this->list($key, [])->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->flushHeaders()->getJson('/v1/fiches?establishment_id='.$hotel->id)->assertStatus(401);
    }

    public function test_cursor_pagination_walks_every_fiche_once(): void
    {
        ['hotel' => $hotel, 'plaintext' => $key] = $this->setUpLinkedPartner();

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeNativeFiche($hotel)->id;
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $query = ['establishment_id' => $hotel->id, 'limit' => 2] + ($cursor ? ['cursor' => $cursor] : []);
            $res = $this->list($key, $query)->assertOk();
            $pages++;
            $this->assertLessThanOrEqual(2, count($res->json('data')));
            $seen = array_merge($seen, array_column($res->json('data'), 'fiche_id'));
            $cursor = $res->json('next_cursor');
            $this->assertSame($cursor !== null, $res->json('has_more'));
        } while ($cursor !== null && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(5, $seen);
        $this->assertEqualsCanonicalizing($ids, $seen);

        $this->list($key, ['establishment_id' => $hotel->id, 'cursor' => 'pas-un-curseur'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
        $this->list($key, ['establishment_id' => $hotel->id, 'limit' => 500])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_updated_since_includes_fiches_whose_delivery_progressed(): void
    {
        ['hotel' => $hotel, 'plaintext' => $key] = $this->setUpLinkedPartner();

        $old = $this->makeNativeFiche($hotel);
        $fresh = $this->makeNativeFiche($hotel);
        $oldButDelivered = $this->makeNativeFiche($hotel);

        $past = now()->subDays(2);
        DB::table('check_ins')->whereIn('id', [$old->id, $oldButDelivered->id])->update(['updated_at' => $past]);
        $log = $this->makeSendLog($oldButDelivered);
        DB::table('whatsapp_send_log')->where('id', $log->id)->update(['updated_at' => now()]);
        $stale = $this->makeSendLog($old);
        DB::table('whatsapp_send_log')->where('id', $stale->id)->update(['updated_at' => $past]);

        $since = now()->subHour()->toIso8601String();
        $ids = array_column($this->list($key, ['establishment_id' => $hotel->id, 'updated_since' => $since])
            ->assertOk()->json('data'), 'fiche_id');

        $this->assertEqualsCanonicalizing([$fresh->id, $oldButDelivered->id], $ids);

        $this->list($key, ['establishment_id' => $hotel->id, 'updated_since' => 'hier'])
            ->assertStatus(422);
    }

    public function test_guest_change_marks_the_fiche_as_updated(): void
    {
        ['hotel' => $hotel, 'plaintext' => $key] = $this->setUpLinkedPartner();
        $checkIn = $this->makeNativeFiche($hotel);
        DB::table('check_ins')->where('id', $checkIn->id)->update(['updated_at' => now()->subDays(2)]);

        $since = now()->subHour()->toIso8601String();
        $this->assertCount(0, $this->list($key, ['establishment_id' => $hotel->id, 'updated_since' => $since])->json('data'));

        $guest = $checkIn->guests()->first();
        app(\App\Services\CheckIn\CheckInService::class)->updateGuest($checkIn->fresh(), $guest, ['first_name' => 'Samir']);

        $this->assertCount(1, $this->list($key, ['establishment_id' => $hotel->id, 'updated_since' => $since])->json('data'));
    }

    public function test_arrival_date_filters_are_inclusive(): void
    {
        ['hotel' => $hotel, 'plaintext' => $key] = $this->setUpLinkedPartner();

        $a = $this->makeNativeFiche($hotel, ['check_in_date' => '2026-09-10', 'expected_check_out_date' => '2026-09-12']);
        $b = $this->makeNativeFiche($hotel, ['check_in_date' => '2026-09-15', 'expected_check_out_date' => '2026-09-16']);
        $c = $this->makeNativeFiche($hotel, ['check_in_date' => '2026-09-20', 'expected_check_out_date' => '2026-09-22']);

        $ids = fn (array $q) => array_column($this->list($key, ['establishment_id' => $hotel->id] + $q)->assertOk()->json('data'), 'fiche_id');

        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ids(['arrival_from' => '2026-09-15']));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids(['arrival_to' => '2026-09-15']));
        $this->assertEqualsCanonicalizing([$b->id], $ids(['arrival_from' => '2026-09-11', 'arrival_to' => '2026-09-19']));

        $item = collect($this->list($key, ['establishment_id' => $hotel->id, 'arrival_from' => '2026-09-20'])->json('data'))->first();
        $this->assertSame('2026-09-20', $item['arrival_date']);
        $this->assertSame('2026-09-22', $item['departure_date']);

        $this->list($key, ['establishment_id' => $hotel->id, 'arrival_from' => '2026-09-20', 'arrival_to' => '2026-09-10'])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_error');
    }

    public function test_test_key_only_sees_its_own_test_mode_fiches(): void
    {
        ['hotel' => $hotel, 'partner' => $partner] = $this->setUpLinkedPartner();
        ['plaintext' => $testKey] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);

        $this->makeNativeFiche($hotel);
        $this->makeApiFiche($hotel, $partner, 'BK-LIVE');
        $test = $this->makeApiFiche($hotel, $partner, 'BK-TEST', testMode: true);

        $data = $this->list($testKey, ['establishment_id' => $hotel->id])->assertOk()->json('data');

        $this->assertSame([$test->id], array_column($data, 'fiche_id'));
        $this->assertSame('not_applicable', $data[0]['delivery_status']);
    }

    public function test_list_reports_delivery_status_per_fiche(): void
    {
        ['hotel' => $hotel, 'plaintext' => $key] = $this->setUpLinkedPartner();
        $this->enableDeliveryModule();

        $sent = $this->makeNativeFiche($hotel);
        $this->makeSendLog($sent, WhatsappSendLog::STATUS_SENT);

        $failed = $this->makeNativeFiche($hotel);
        $this->attachGuest($failed, Guest::factory()->create());
        $this->makeSendLog($failed, WhatsappSendLog::STATUS_SENT);
        $this->makeSendLog($failed, WhatsappSendLog::STATUS_FAILED, $failed->guests()->get()->last());

        $waiting = $this->makeNativeFiche($hotel);

        $statuses = collect($this->list($key, ['establishment_id' => $hotel->id])->assertOk()->json('data'))
            ->pluck('delivery_status', 'fiche_id');

        $this->assertSame('sent', $statuses[$sent->id]);
        $this->assertSame('failed', $statuses[$failed->id]);
        $this->assertSame('pending', $statuses[$waiting->id]);
    }
}
