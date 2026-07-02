<?php

namespace Tests\Feature;

use App\Models\AuthorityOrganization;
use App\Models\User;
use App\Models\WatchlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Feature tests for the CSV watchlist import endpoint.
 *
 * Covers:
 *  - Successful import of valid rows
 *  - Deduplication: same document_number for same org → skipped
 *  - CSV injection sanitization: formulas stripped from field values
 *  - Rows with neither document_number nor last_name → skipped
 *  - Malformed rows (wrong column count) → skipped, no crash
 *  - Only authority_user can import
 *  - File type validation
 */
class WatchlistImportTest extends TestCase
{
    use RefreshDatabase;

    private AuthorityOrganization $org;
    private User $authorityUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org           = AuthorityOrganization::factory()->ministry()->create();
        $this->authorityUser = User::factory()->authorityUser($this->org)->create();
    }

    // ── CSV helpers ───────────────────────────────────────────────────────────

    private function csvFile(string $content, string $name = 'import.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wl_csv_');
        file_put_contents($path, $content);
        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function validCsvRow(array $overrides = []): string
    {
        $row = array_merge([
            'document_number'  => 'TN12345678',
            'document_type'    => 'passport',
            'first_name'       => 'Ahmed',
            'last_name'        => 'Trabelsi',
            'date_of_birth'    => '1985-03-15',
            'nationality_code' => 'TUN',
            'severity'         => 'critique',
            'reason_code'      => 'MANDAT_ARRET',
            'reason'           => 'Test reason',
        ], $overrides);
        return implode(',', array_values($row));
    }

    private function csvWithHeader(string ...$rows): string
    {
        $header = 'document_number,document_type,first_name,last_name,date_of_birth,nationality_code,severity,reason_code,reason';
        return implode("\n", [$header, ...$rows]);
    }

    /** Upload CSV as multipart form data (correct for file uploads in Laravel tests). */
    private function importCsv(User $user, string $csvContent): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)
            ->post(
                '/api/v1/authority/watchlist/import',
                ['file' => $this->csvFile($csvContent)],
                ['Accept' => 'application/json']
            );
    }

    // ── Success ───────────────────────────────────────────────────────────────

    public function test_valid_csv_imports_entries_successfully(): void
    {
        $csv = $this->csvWithHeader(
            $this->validCsvRow(['document_number' => 'TN11111111']),
            $this->validCsvRow(['document_number' => 'TN22222222']),
        );

        $response = $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertEquals(2, $response->json('data.created'));
        $this->assertEquals(0, $response->json('data.skipped'));
        $this->assertDatabaseHas('watchlist_entries', ['document_number' => 'TN11111111']);
        $this->assertDatabaseHas('watchlist_entries', ['document_number' => 'TN22222222']);
    }

    public function test_import_assigns_correct_severity_and_reason_code(): void
    {
        $csv = $this->csvWithHeader(
            $this->validCsvRow(['document_number' => 'TN77777777', 'severity' => 'eleve', 'reason_code' => 'FRAUDE']),
        );

        $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertDatabaseHas('watchlist_entries', [
            'document_number' => 'TN77777777',
            'severity'        => 'eleve',
            'reason_code'     => 'FRAUDE',
        ]);
    }

    // ── Deduplication ─────────────────────────────────────────────────────────

    public function test_duplicate_document_number_is_skipped(): void
    {
        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorityUser->id,
            'document_number' => 'TN12345678',
        ]);

        $csv = $this->csvWithHeader(
            $this->validCsvRow(['document_number' => 'TN12345678']), // duplicate
            $this->validCsvRow(['document_number' => 'TN99999999']), // new
        );

        $response = $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertEquals(1, $response->json('data.created'));
        $this->assertEquals(1, $response->json('data.skipped'));
        $this->assertEquals(1, WatchlistEntry::where('document_number', 'TN12345678')->count());
    }

    public function test_same_document_can_be_imported_by_different_orgs(): void
    {
        $otherOrg  = AuthorityOrganization::factory()->police('Sfax')->create();
        $otherUser = User::factory()->authorityUser($otherOrg)->create();

        WatchlistEntry::factory()->for($this->org)->create([
            'added_by'        => $this->authorityUser->id,
            'document_number' => 'TN12345678',
        ]);

        $csv      = $this->csvWithHeader($this->validCsvRow(['document_number' => 'TN12345678']));
        $response = $this->importCsv($otherUser, $csv)->assertOk();

        $this->assertEquals(1, $response->json('data.created'));
        $this->assertEquals(2, WatchlistEntry::where('document_number', 'TN12345678')->count());
    }

    // ── CSV injection sanitization ────────────────────────────────────────────

    public function test_csv_formula_prefix_equals_is_stripped_from_fields(): void
    {
        $csv = $this->csvWithHeader(
            'TN55555555,passport,=SYSTEM("rm -rf /"),Hacker,1990-01-01,TUN,moyen,AUTRE,',
        );

        $this->importCsv($this->authorityUser, $csv)->assertOk();

        $entry = WatchlistEntry::where('document_number', 'TN55555555')->firstOrFail();
        $this->assertStringStartsNotWith('=', $entry->first_name);
        $this->assertEquals('SYSTEM("rm -rf /")', $entry->first_name);
    }

    public function test_plus_and_at_formula_prefixes_are_stripped(): void
    {
        $csv = $this->csvWithHeader(
            'TN66666666,passport,+1+1,@SUM(1+1),1990-01-01,TUN,moyen,AUTRE,',
        );

        $this->importCsv($this->authorityUser, $csv)->assertOk();

        $entry = WatchlistEntry::where('document_number', 'TN66666666')->firstOrFail();
        $this->assertEquals('1+1',      $entry->first_name);
        $this->assertEquals('SUM(1+1)', $entry->last_name);
    }

    // ── Skipped / malformed rows ──────────────────────────────────────────────

    public function test_rows_without_document_number_or_last_name_are_skipped(): void
    {
        $csv = $this->csvWithHeader(
            ',passport,Ahmed,,1985-01-01,TUN,moyen,AUTRE,',
        );

        $response = $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertEquals(0, $response->json('data.created'));
        $this->assertEquals(1, $response->json('data.skipped'));
    }

    public function test_malformed_row_wrong_column_count_is_skipped(): void
    {
        $csv = $this->csvWithHeader(
            'TN11111111,passport', // too few columns
        );

        $response = $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertEquals(0, $response->json('data.created'));
        $this->assertEquals(1, $response->json('data.skipped'));
    }

    public function test_invalid_severity_defaults_to_moyen(): void
    {
        $csv = $this->csvWithHeader(
            $this->validCsvRow(['document_number' => 'TN33333333', 'severity' => 'ultra_secret']),
        );

        $this->importCsv($this->authorityUser, $csv)->assertOk();

        $this->assertDatabaseHas('watchlist_entries', [
            'document_number' => 'TN33333333',
            'severity'        => 'moyen',
        ]);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_hotel_admin_cannot_import_watchlist(): void
    {
        $hotel = \App\Models\Hotel::factory()->withActiveSubscription()->create();
        $admin = User::factory()->hotelAdmin($hotel)->create();
        $csv   = $this->csvWithHeader($this->validCsvRow());

        $this->importCsv($admin, $csv)->assertForbidden();
    }

    public function test_import_requires_csv_file_type(): void
    {
        $this->actingAs($this->authorityUser)
            ->post(
                '/api/v1/authority/watchlist/import',
                ['file' => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream')],
                ['Accept' => 'application/json']
            )
            ->assertUnprocessable();
    }

    // ── Batch ID ──────────────────────────────────────────────────────────────

    public function test_import_returns_batch_id(): void
    {
        $response = $this->importCsv($this->authorityUser, $this->csvWithHeader($this->validCsvRow()))
            ->assertOk();

        $this->assertStringStartsWith('IMPORT-', $response->json('data.batch_id'));
    }

    public function test_imported_entries_carry_source_and_batch_id(): void
    {
        $csv      = $this->csvWithHeader($this->validCsvRow(['document_number' => 'TN44444444']));
        $response = $this->importCsv($this->authorityUser, $csv)->assertOk();

        $batchId = $response->json('data.batch_id');
        $this->assertDatabaseHas('watchlist_entries', [
            'document_number' => 'TN44444444',
            'import_batch_id' => $batchId,
            'source'          => 'import',
        ]);
    }
}
