<?php

namespace Tests\Feature\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use App\Models\Prospection\ProspectionUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ImportIdempotenceTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = <<<'CSV'
Nom;Numéro WhatsApp;Adresse / Repère;Taille estimée;Segment;Décideur / Contact;Canal pour trouver le numéro;Notes de qualification;Statut;Dernière action;Date relance;Objections / Retours
Dar Zaghouan;20123456;Rue de la Kasbah;petite (≤8 ch.);maison d'hôtes;Ahmed;Google Maps;Contact chaleureux;À contacter;Message envoyé le 10/01;15/01/2027;Prix
Dar Sfax;98765432;Médina;moyenne (9-15 ch.);guesthouse;Sonia;Réseau TLL;;Contacté;;;
CSV;

    private function authHeader(): array
    {
        $user = ProspectionUser::create([
            'name' => 'Hichem', 'email' => 'hichem@qayed.tn',
            'password' => Hash::make('un-mot-de-passe-solide'), 'role' => 'admin',
        ]);

        $token = $this->postJson('/api/v1/prospection/auth/login', [
            'email' => $user->email, 'password' => 'un-mot-de-passe-solide',
        ])->json('data.token');

        return ['Authorization' => "Bearer {$token}"];
    }

    private function csvFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('prospects.csv', self::CSV);
    }

    public function test_preview_detects_columns_and_finds_no_duplicate_on_an_empty_base(): void
    {
        $response = $this->withHeaders($this->authHeader())
            ->post('/api/v1/prospection/import/preview', ['file' => $this->csvFile()])
            ->assertOk();

        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(0, $response->json('data.duplicates'));
        $this->assertSame('Dar Zaghouan', $response->json('data.rows.0.fields.name'));
        $this->assertSame('+21620123456', $response->json('data.rows.0.fields.whatsapp_phone'));
        $this->assertSame('petite', $response->json('data.rows.0.fields.size'));
        $this->assertSame('maison_hotes', $response->json('data.rows.0.fields.segment'));
        $this->assertSame('a_contacter', $response->json('data.rows.0.fields.status'));
        $this->assertSame('contacte', $response->json('data.rows.1.fields.status'));
    }

    public function test_committing_the_same_file_twice_does_not_create_duplicates(): void
    {
        $headers = $this->authHeader();

        $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/commit', ['file' => $this->csvFile()])
            ->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->assertSame(2, Establishment::count());
        $this->assertSame(1, ProspectionAction::where('content', 'Message envoyé le 10/01')->count());

        // Même fichier, rejoué (ex. double clic, ou reprise après coupure), et
        // SANS décision explicite par ligne : un doublon non résolu est
        // ignoré par défaut (jamais fusionné à l'aveugle) — mais surtout,
        // aucune ligne supplémentaire n'est créée. C'est ça, l'idempotence.
        $second = $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/commit', ['file' => $this->csvFile()])
            ->assertOk();

        $this->assertSame(0, $second->json('data.created'));
        $this->assertSame(0, $second->json('data.merged'));
        $this->assertSame(2, $second->json('data.skipped'));
        $this->assertSame(2, Establishment::count());
        $this->assertSame(1, ProspectionAction::where('content', 'Message envoyé le 10/01')->count());

        // Rejoué une troisième fois, cette fois avec 'merge' explicite pour
        // les deux lignes (l'utilisateur choisit de fusionner) : toujours
        // aucun doublon créé, et la note d'import n'est pas répétée.
        $third = $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/commit', [
                'file' => $this->csvFile(),
                'resolutions' => [2 => 'merge', 3 => 'merge'],
            ])
            ->assertOk();

        $this->assertSame(0, $third->json('data.created'));
        $this->assertSame(2, $third->json('data.merged'));
        $this->assertSame(2, Establishment::count());
        $this->assertSame(1, ProspectionAction::where('content', 'Message envoyé le 10/01')->count());
    }

    public function test_a_duplicate_name_is_flagged_for_merge_or_skip_decision(): void
    {
        $headers = $this->authHeader();
        Establishment::create(['name' => 'Dar Zaghouan', 'status' => 'client', 'qualification_notes' => 'Déjà cliente']);

        $preview = $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/preview', ['file' => $this->csvFile()])
            ->assertOk();

        $this->assertSame(1, $preview->json('data.duplicates'));
        $this->assertTrue($preview->json('data.rows.0.is_duplicate'));
        $this->assertFalse($preview->json('data.rows.1.is_duplicate'));

        // 'skip' explicite sur la ligne en doublon : le client existant n'est pas touché.
        $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/commit', [
                'file' => $this->csvFile(),
                'resolutions' => [2 => 'skip'],
            ])
            ->assertOk()
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.created', 1);

        $this->assertSame('client', Establishment::where('name', 'Dar Zaghouan')->first()->status);
        $this->assertSame(2, Establishment::count());
    }

    public function test_merge_never_blanks_an_already_qualified_field_with_empty_import_data(): void
    {
        $headers = $this->authHeader();
        Establishment::create([
            'name' => 'Dar Sfax', 'status' => 'client', 'qualification_notes' => 'Notes précieuses déjà saisies',
        ]);

        // La ligne "Dar Sfax" du CSV n'a pas de notes de qualification.
        $this->withHeaders($headers)
            ->post('/api/v1/prospection/import/commit', [
                'file' => $this->csvFile(),
                'resolutions' => [3 => 'merge'],
            ])
            ->assertOk();

        $this->assertSame('Notes précieuses déjà saisies', Establishment::where('name', 'Dar Sfax')->first()->qualification_notes);
    }
}
