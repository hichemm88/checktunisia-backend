<?php

namespace Tests\Feature\PartnerApi;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\FicheSession;
use App\Models\Guest;
use App\Models\PartnerWebhookDelivery;
use App\Models\Room;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\CheckIn\CheckInService;
use App\Services\PartnerApi\PartnerIntegrationActor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PartnerApi\Concerns\InteractsWithPartnerApi;
use Tests\TestCase;

/**
 * LOT 4 — purge des données de test d'un partenaire (commande + action admin).
 *
 * Même établissement, quatre origines de fiches : clé de test, clé live,
 * saisie dans Qayed, et le cas piège du check-in « mixte » (brouillon créé en
 * test puis repris et complété par une session live). Seules les données de
 * test pures doivent disparaître.
 */
class PartnerTestDataPurgeTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPartnerApi;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.passport_scan_disk', 'local'));
        $this->f = $this->buildMixedEstablishment();
    }

    // ── Jeu de données ───────────────────────────────────────────────────────

    /** Session API + widget complet ; renvoie [session, checkIn]. */
    private function apiFiche(string $plaintext, $hotel, string $booking, array $guest, ?string $roomId = null, bool $submit = true): array
    {
        $create = $this->withHeaders($this->bearer($plaintext))
            ->postJson('/v1/fiche-sessions', $this->fichePayload($hotel, $booking, [], array_filter(['room_id' => $roomId])))
            ->assertSuccessful();
        $session = FicheSession::findOrFail($create->json('session_id'));

        if ($submit) {
            parse_str((string) parse_url($create->json('widget_url'), PHP_URL_QUERY), $q);
            $widgetToken = $this->getJson('/widget/v1/bootstrap?token='.$q['token'])->assertOk()->json('widget_token');
            $this->withHeaders($this->bearer($widgetToken))->postJson('/widget/v1/guests', $guest)->assertCreated();
            $this->withHeaders($this->bearer($widgetToken))->postJson('/widget/v1/submit')->assertOk();
        }

        return [$session->fresh(), CheckIn::findOrFail($session->check_in_id)];
    }

    private function buildMixedEstablishment(): array
    {
        $this->seedWidgetJwtSecret();
        ['org' => $org, 'hotel' => $hotel] = $this->makeOrgWithHotel();
        $partner = $this->makePartner();
        ['plaintext' => $testKey] = $this->issuePartnerKey($partner, ApiKey::MODE_TEST);
        ['plaintext' => $liveKey] = $this->issuePartnerKey($partner, ApiKey::MODE_LIVE);
        $this->linkEstablishment($partner, $hotel);
        $endpoint = $this->makeWebhookEndpoint($partner);

        $roomTest = Room::factory()->for($hotel)->create(['number' => 'T1', 'status' => 'available']);
        $roomDraft = Room::factory()->for($hotel)->create(['number' => 'T2', 'status' => 'available']);
        $roomLive = Room::factory()->for($hotel)->create(['number' => 'L1', 'status' => 'available']);
        $roomNative = Room::factory()->for($hotel)->create(['number' => 'Q1', 'status' => 'available']);

        $sharedDoc = 'TNSHARED'.random_int(1000, 9999);

        // 1. Fiche de TEST complète (2 voyageurs dont un partagé), avec scan image.
        [$testSession, $testCheckIn] = $this->apiFiche($testKey, $hotel, 'BK-TEST-1', $this->widgetGuestPayload([
            'first_name' => 'Testprenom', 'last_name' => 'Testnom',
        ]), $roomTest->id);
        $actor = app(PartnerIntegrationActor::class)->forOrganization($org);
        app(CheckInService::class)->addGuest($testCheckIn, $actor, $this->widgetGuestPayload([
            'first_name' => 'Partage', 'last_name' => 'Voyageur', 'is_primary' => false,
            'document' => ['type' => 'passport', 'document_number' => $sharedDoc, 'issuing_country_code' => 'TUN'],
        ]));
        $scan = app(CheckInService::class)->uploadScan($testCheckIn, $actor, UploadedFile::fake()->image('cin.jpg', 400, 300), runOcr: false);

        // 2. Brouillon de TEST (jamais soumis) dans une autre chambre.
        [$draftSession, $draftCheckIn] = $this->apiFiche($testKey, $hotel, 'BK-TEST-DRAFT', [], $roomDraft->id, submit: false);

        // 3. Fiche LIVE dont un voyageur est le même document que la fiche de test.
        [$liveSession, $liveCheckIn] = $this->apiFiche($liveKey, $hotel, 'BK-LIVE-1', $this->widgetGuestPayload([
            'first_name' => 'Partage', 'last_name' => 'Voyageur',
            'document' => ['type' => 'passport', 'document_number' => $sharedDoc, 'issuing_country_code' => 'TUN'],
        ]), $roomLive->id);

        // 4. Cas piège : brouillon créé en TEST, lien expiré, repris par une
        //    session LIVE sur le même booking puis soumis pour de vrai.
        [$mixTestSession] = $this->apiFiche($testKey, $hotel, 'BK-MIX', [], null, submit: false);
        $mixTestSession->update(['expires_at' => now()->subMinute()]);
        [$mixLiveSession, $mixCheckIn] = $this->apiFiche($liveKey, $hotel, 'BK-MIX', $this->widgetGuestPayload([
            'first_name' => 'Mixte', 'last_name' => 'Reel',
        ]));

        // 5. Session de TEST rouvrant (amend) la fiche LIVE : la session est de
        //    test, la fiche visée ne l'est pas.
        [$amendTestSession] = $this->apiFiche($testKey, $hotel, 'BK-LIVE-1', [], null, submit: false);

        // 6. Fiche saisie dans Qayed (aucune métadonnée API).
        $owner = $this->hotelOwner($hotel);
        $native = CheckIn::factory()->create([
            'hotel_id' => $hotel->id, 'room_id' => $roomNative->id, 'created_by' => $owner->id, 'status' => 'active',
        ]);
        $nativeGuest = Guest::factory()->create();
        $native->guests()->attach($nativeGuest->id, ['is_primary' => true, 'added_by' => $owner->id, 'added_at' => now()]);

        // 7. Livraisons webhook : une de test (session), une live.
        $testDelivery = PartnerWebhookDelivery::create([
            'endpoint_id' => $endpoint->id, 'event_type' => 'session.expired', 'idempotency_key' => $draftSession->id,
            'payload' => ['session_id' => $draftSession->id], 'status' => 'pending', 'queued_at' => now(),
        ]);

        return compact(
            'org', 'hotel', 'partner', 'endpoint', 'roomTest', 'roomDraft', 'roomLive', 'roomNative',
            'testSession', 'testCheckIn', 'scan', 'draftSession', 'draftCheckIn', 'liveSession', 'liveCheckIn',
            'mixTestSession', 'mixLiveSession', 'mixCheckIn', 'amendTestSession', 'native', 'nativeGuest', 'testDelivery',
        );
    }

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    private function roomIsOccupied(Room $room): bool
    {
        return CheckIn::where('room_id', $room->id)->whereIn('status', ['draft', 'active'])->exists();
    }

    private function preview(User $admin): array
    {
        return $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data", ['dry_run' => true])
            ->assertOk()
            ->json('data');
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_fixture_sanity(): void
    {
        $this->assertTrue((bool) $this->f['testCheckIn']->metadata['test_mode']);
        $this->assertTrue((bool) $this->f['mixCheckIn']->metadata['test_mode'], 'le check-in mixte porte le marqueur test de sa création');
        $this->assertSame($this->f['mixCheckIn']->id, $this->f['mixLiveSession']->check_in_id);
        $this->assertFalse($this->f['mixLiveSession']->is_test);
        $this->assertSame($this->f['liveCheckIn']->id, $this->f['amendTestSession']->check_in_id);
        $this->assertTrue($this->f['amendTestSession']->is_test);
        $this->assertTrue($this->roomIsOccupied($this->f['roomTest']));
        $this->assertTrue($this->roomIsOccupied($this->f['roomDraft']));
        Storage::disk(config('filesystems.passport_scan_disk', 'local'))->assertExists(DocumentScan::find($this->f['scan']->id)->file_path);
    }

    public function test_preview_has_no_effect_and_reports_counts(): void
    {
        $before = [CheckIn::withTrashed()->count(), FicheSession::count(), Guest::withTrashed()->count(), DocumentScan::count(), PartnerWebhookDelivery::count()];

        $data = $this->preview($this->admin());

        $this->assertSame([CheckIn::withTrashed()->count(), FicheSession::count(), Guest::withTrashed()->count(), DocumentScan::count(), PartnerWebhookDelivery::count()], $before);
        $this->assertSame(4, $data['counts']['sessions'], 'fiche test, brouillon test, test du mixte, amend test');
        $this->assertSame(1, $data['counts']['drafts']);
        $this->assertSame(1, $data['counts']['fiches']);
        $this->assertSame(1, $data['counts']['guests'], 'le voyageur partagé avec la fiche live est conservé');
        $this->assertSame(1, $data['counts']['scans']);
        $this->assertSame(1, $data['counts']['webhook_deliveries']);
        $this->assertSame(2, $data['counts']['rooms_released']);
        $this->assertSame(2, $data['counts']['kept_check_ins'], 'mixte + fiche live visée par la session amend de test');
        $this->assertSame(64, strlen($data['confirmation_token']));
        $this->assertGreaterThan(0, $data['total']);
        $this->assertSame(0, AuditLog::where('action', 'partner.test_data_purged')->count());
    }

    public function test_admin_purge_removes_only_pure_test_data_and_releases_rooms(): void
    {
        $admin = $this->admin();
        $preview = $this->preview($admin);
        $scanPath = DocumentScan::find($this->f['scan']->id)->file_path;
        $testGuestId = $this->f['testCheckIn']->guests()->where('first_name', 'Testprenom')->value('guests.id');
        $sharedGuestId = $this->f['liveCheckIn']->guests()->value('guests.id');

        $res = $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data", ['confirmation_token' => $preview['confirmation_token']])
            ->assertOk();
        $this->assertTrue($res->json('data.purged'));
        $this->assertSame($preview['counts'], $res->json('data.counts'));

        // Test : supprimé, définitivement.
        $this->assertNull(CheckIn::withTrashed()->find($this->f['testCheckIn']->id));
        $this->assertNull(CheckIn::withTrashed()->find($this->f['draftCheckIn']->id));
        foreach (['testSession', 'draftSession', 'mixTestSession', 'amendTestSession'] as $s) {
            $this->assertNull(FicheSession::find($this->f[$s]->id), $s);
        }
        $this->assertNull(Guest::withTrashed()->find($testGuestId));
        $this->assertSame(0, TravelDocument::where('guest_id', $testGuestId)->count());
        $this->assertNull(DocumentScan::find($this->f['scan']->id));
        Storage::disk(config('filesystems.passport_scan_disk', 'local'))->assertMissing($scanPath);
        $this->assertNull(PartnerWebhookDelivery::find($this->f['testDelivery']->id));

        // Chambres libérées.
        $this->assertFalse($this->roomIsOccupied($this->f['roomTest']));
        $this->assertFalse($this->roomIsOccupied($this->f['roomDraft']));

        // Live, Qayed, mixte : intacts.
        foreach (['liveCheckIn', 'mixCheckIn', 'native'] as $c) {
            $fresh = CheckIn::find($this->f[$c]->id);
            $this->assertNotNull($fresh, $c);
            $this->assertSame('active', $fresh->status, $c);
            $this->assertGreaterThan(0, $fresh->guests()->count(), $c);
        }
        $this->assertNotNull(FicheSession::find($this->f['liveSession']->id));
        $this->assertNotNull(FicheSession::find($this->f['mixLiveSession']->id));
        $this->assertNotNull(Guest::find($sharedGuestId), 'voyageur partagé conservé');
        $this->assertSame(1, TravelDocument::where('guest_id', $sharedGuestId)->count());
        $this->assertNotNull(Guest::find($this->f['nativeGuest']->id));
        $this->assertTrue($this->roomIsOccupied($this->f['roomLive']));
        $this->assertTrue($this->roomIsOccupied($this->f['roomNative']));

        // Audit : comptes, acteur, aucune donnée personnelle.
        $log = AuditLog::where('action', 'partner.test_data_purged')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($this->f['partner']->id, $log->subject_id);
        $this->assertEquals($preview['counts'], $log->new_values['counts']); // jsonb : ordre des clés non garanti
        $json = json_encode($log->new_values);
        foreach (['Testprenom', 'Testnom', 'Partage', 'Voyageur', $scanPath] as $personal) {
            $this->assertStringNotContainsString($personal, $json);
        }

        // Rejouable : plus rien à purger.
        $again = $this->preview($admin);
        $this->assertSame(0, $again['total']);
    }

    public function test_missing_confirmation_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson("/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data", [])
            ->assertStatus(422);

        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));
        $this->assertSame(0, AuditLog::where('action', 'partner.test_data_purged')->count());
    }

    public function test_wrong_or_stale_confirmation_is_refused_with_current_counts(): void
    {
        $admin = $this->admin();
        $preview = $this->preview($admin);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data", ['confirmation_token' => str_repeat('0', 64)])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'PURGE_CONFIRMATION_MISMATCH');
        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));

        // Nouvelle donnée de test apparue après l'aperçu : l'ancien jeton ne vaut plus.
        $this->apiFiche($this->issuePartnerKey($this->f['partner'], ApiKey::MODE_TEST)['plaintext'], $this->f['hotel'], 'BK-TEST-LATE', [], null, submit: false);

        $stale = $this->actingAs($admin)
            ->postJson("/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data", ['confirmation_token' => $preview['confirmation_token']])
            ->assertStatus(409);
        $this->assertSame($preview['counts']['sessions'] + 1, $stale->json('data.counts.sessions'));
        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));
        $this->assertSame(0, AuditLog::where('action', 'partner.test_data_purged')->count());
    }

    public function test_only_platform_admins_can_preview_or_purge(): void
    {
        $url = "/api/v1/admin/partners/{$this->f['partner']->id}/purge-test-data";

        // FicheSessionService pose l'acteur d'intégration sur la garde par
        // défaut pendant la construction du jeu : on repart d'un client anonyme.
        $this->app['auth']->forgetGuards();
        $this->postJson($url, ['dry_run' => true])->assertStatus(401);
        $this->actingAs($this->hotelOwner($this->f['hotel']))->postJson($url, ['dry_run' => true])->assertStatus(403);

        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));
    }

    public function test_command_dry_run_has_no_effect_and_force_purges(): void
    {
        $partner = $this->f['partner'];

        $this->artisan('partner:purge-test-data', ['partner' => $partner->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));

        $this->artisan('partner:purge-test-data', ['partner' => $partner->slug, '--force' => true])->assertExitCode(0);
        $this->assertNull(CheckIn::withTrashed()->find($this->f['testCheckIn']->id));
        $this->assertNotNull(CheckIn::find($this->f['liveCheckIn']->id));
        $this->assertNotNull(CheckIn::find($this->f['mixCheckIn']->id));
        $this->assertNotNull(CheckIn::find($this->f['native']->id));

        $log = AuditLog::where('action', 'partner.test_data_purged')->sole();
        $this->assertSame('cli', $log->new_values['via']);
    }

    public function test_command_without_force_in_non_interactive_mode_refuses(): void
    {
        $this->artisan('partner:purge-test-data', ['partner' => $this->f['partner']->id, '--no-interaction' => true])->assertExitCode(1);
        $this->assertNotNull(CheckIn::find($this->f['testCheckIn']->id));
    }
}
