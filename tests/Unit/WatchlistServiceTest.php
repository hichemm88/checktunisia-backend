<?php

namespace Tests\Unit;

use App\Models\AuthorityOrganization;
use App\Models\Guest;
use App\Models\TravelDocument;
use App\Models\User;
use App\Models\WatchlistEntry;
use App\Services\Watchlist\WatchlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for WatchlistService matching logic.
 *
 * Uses RefreshDatabase so each test starts from a known DB state,
 * but no HTTP layer is involved — we call the service directly.
 */
class WatchlistServiceTest extends TestCase
{
    use RefreshDatabase;

    private WatchlistService $service;
    private AuthorityOrganization $org;
    private User $authorizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service   = new WatchlistService();
        $this->org       = AuthorityOrganization::factory()->ministry()->create();
        $this->authorizer = User::factory()->create();
    }

    // ─── checkGuest(): document match ────────────────────────────────────────

    public function test_check_guest_matches_by_document_number(): void
    {
        $entry = WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => 'TN12345678',
            'severity'        => 'critique',
            'reason_code'     => 'MANDAT_ARRET',
        ]);

        $guest = $this->guestWithDoc('TN12345678');

        $result = $this->service->checkGuest($guest);

        $this->assertNotNull($result);
        $this->assertEquals($entry->id, $result['entry_id']);
        $this->assertEquals('critique',      $result['severity']);
        $this->assertEquals('MANDAT_ARRET',  $result['reason_code']);
        $this->assertEquals('document',      $result['hit_type']);
    }

    public function test_check_guest_matches_by_name_and_dob(): void
    {
        $dob = '1985-06-15';

        $entry = WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => null,
            'last_name'       => 'Trabelsi',
            'date_of_birth'   => $dob,
            'severity'        => 'eleve',
            'reason_code'     => 'FRAUDE',
        ]);

        $guest = Guest::factory()->create([
            'last_name'     => 'trabelsi', // lowercase — must still match
            'date_of_birth' => $dob,
        ]);
        $guest->setRelation('documents', collect());

        $result = $this->service->checkGuest($guest);

        $this->assertNotNull($result);
        $this->assertEquals($entry->id, $result['entry_id']);
        $this->assertEquals('name_dob', $result['hit_type']);
    }

    public function test_check_guest_returns_null_for_no_match(): void
    {
        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => 'OTHER99999',
            'last_name'       => 'SomeoneElse',
        ]);

        $guest = $this->guestWithDoc('TN00000000');
        $guest->last_name = 'Nobody';

        $result = $this->service->checkGuest($guest);

        $this->assertNull($result);
    }

    public function test_check_guest_ignores_inactive_entries(): void
    {
        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => 'TN12345678',
            'status'          => 'inactive',
        ]);

        $guest = $this->guestWithDoc('TN12345678');

        $this->assertNull($this->service->checkGuest($guest));
    }

    public function test_check_guest_ignores_expired_entries(): void
    {
        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => 'TN12345678',
            'status'          => 'active',
            'expires_at'      => now()->subDay(),
        ]);

        $guest = $this->guestWithDoc('TN12345678');

        $this->assertNull($this->service->checkGuest($guest));
    }

    public function test_check_guest_prefers_critique_over_moyen(): void
    {
        $dob = '1990-01-01';

        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'      => $this->authorizer->id,
            'last_name'     => 'Ben Salah',
            'date_of_birth' => $dob,
            'severity'      => 'moyen',
            'reason_code'   => 'AUTRE',
        ]);
        $critEntry = WatchlistEntry::factory()->for($this->org)->create([
            'added_by'      => $this->authorizer->id,
            'last_name'     => 'Ben Salah',
            'date_of_birth' => $dob,
            'severity'      => 'critique',
            'reason_code'   => 'MANDAT_ARRET',
        ]);

        $guest = Guest::factory()->create([
            'last_name'     => 'Ben Salah',
            'date_of_birth' => $dob,
        ]);
        $guest->setRelation('documents', collect());

        $result = $this->service->checkGuest($guest);

        $this->assertEquals($critEntry->id, $result['entry_id']);
        $this->assertEquals('critique', $result['severity']);
    }

    // ─── batchCheckGuests() ───────────────────────────────────────────────────

    public function test_batch_check_matches_correct_guest_only(): void
    {
        $matchedDoc = 'TN11111111';
        $otherDoc   = 'TN99999999';

        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => $matchedDoc,
            'severity'        => 'critique',
        ]);

        $guestA = $this->guestWithDoc($matchedDoc);
        $guestB = $this->guestWithDoc($otherDoc);

        $results = $this->service->batchCheckGuests(collect([$guestA, $guestB]));

        $this->assertNotNull($results[$guestA->id]);
        $this->assertNull($results[$guestB->id]);
        $this->assertEquals('document', $results[$guestA->id]['hit_type']);
    }

    public function test_batch_check_returns_empty_for_empty_collection(): void
    {
        $results = $this->service->batchCheckGuests(collect());
        $this->assertSame([], $results);
    }

    public function test_batch_check_returns_null_for_all_when_no_watchlist_entries(): void
    {
        $guestA = $this->guestWithDoc('TN11111111');
        $guestB = $this->guestWithDoc('TN22222222');

        $results = $this->service->batchCheckGuests(collect([$guestA, $guestB]));

        $this->assertNull($results[$guestA->id]);
        $this->assertNull($results[$guestB->id]);
    }

    public function test_batch_check_document_takes_priority_over_name_dob(): void
    {
        $dob = '1988-03-20';
        $doc = 'TN55555555';

        // Two entries: one doc match (critique), one name_dob match (eleve)
        $docEntry = WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => $doc,
            'last_name'       => null,
            'severity'        => 'critique',
        ]);
        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorizer->id,
            'document_number' => null,
            'last_name'       => 'Gharbi',
            'date_of_birth'   => $dob,
            'severity'        => 'eleve',
        ]);

        $guest = $this->guestWithDoc($doc);
        $guest->last_name     = 'Gharbi';
        $guest->date_of_birth = \Carbon\Carbon::parse($dob);

        $results = $this->service->batchCheckGuests(collect([$guest]));

        $this->assertEquals('document', $results[$guest->id]['hit_type']);
        $this->assertEquals($docEntry->id, $results[$guest->id]['entry_id']);
    }

    // ─── pendingHitsForHotel() ───────────────────────────────────────────────

    public function test_pending_hits_count_excludes_acknowledged(): void
    {
        $hotel = \App\Models\Hotel::factory()->withActiveSubscription()->create();

        // Create 3 pending hits — each needs a unique (entry, guest, check_in) triple
        collect(range(1, 3))->each(function () use ($hotel) {
            \App\Models\WatchlistHit::factory()
                ->for($hotel)
                ->pending()
                ->create([
                    'watchlist_entry_id' => WatchlistEntry::factory()->for($this->org)->create(['added_by' => $this->authorizer->id])->id,
                    'guest_id'           => Guest::factory()->create()->id,
                    'check_in_id'        => \App\Models\CheckIn::factory()->for($hotel)->create([
                        'created_by' => $this->authorizer->id,
                    ])->id,
                ]);
        });

        \App\Models\WatchlistHit::factory()
            ->for($hotel)
            ->acknowledged()
            ->create([
                'watchlist_entry_id' => WatchlistEntry::factory()->for($this->org)->create(['added_by' => $this->authorizer->id])->id,
                'guest_id'           => Guest::factory()->create()->id,
                'check_in_id'        => \App\Models\CheckIn::factory()->for($hotel)->create([
                    'created_by' => $this->authorizer->id,
                ])->id,
            ]);

        $this->assertEquals(3, $this->service->pendingHitsForHotel($hotel->id));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a Guest with one travel document and eager-load 'documents'.
     */
    private function guestWithDoc(string $docNumber): Guest
    {
        $guest = Guest::factory()->create();

        $doc = TravelDocument::create([
            'guest_id'            => $guest->id,
            'type'                => 'passport',
            'document_number'     => $docNumber,
            'issuing_country_code' => 'TN',
        ]);

        $guest->setRelation('documents', collect([$doc]));

        return $guest;
    }
}
