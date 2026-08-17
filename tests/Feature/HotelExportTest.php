<?php

namespace Tests\Feature;

use App\Jobs\ExportPoliceFichesJob;
use App\Mail\PoliceFichesExport;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/** Export des fiches de police (PDF par email) — manager établissement. */
class HotelExportTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $manager;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Test']);
        $this->manager = User::factory()->hotelAdmin($this->hotel)->create(['email' => 'manager@dar-test.tn']);
        $this->receptionist = User::factory()->receptionist($this->hotel)->create();
    }

    public function test_manager_queues_export_and_gets_202(): void
    {
        Queue::fake();

        $this->actingAs($this->manager)
            ->postJson('/api/v1/hotel/exports/police-fiches', [
                'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.email', 'manager@dar-test.tn');

        Queue::assertPushed(ExportPoliceFichesJob::class);
    }

    public function test_receptionist_cannot_export(): void
    {
        $this->actingAs($this->receptionist)
            ->postJson('/api/v1/hotel/exports/police-fiches', [
                'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            ])
            ->assertForbidden();
    }

    public function test_invalid_range_is_rejected(): void
    {
        $this->actingAs($this->manager)
            ->postJson('/api/v1/hotel/exports/police-fiches', [
                'date_from' => '2026-07-31', 'date_to' => '2026-07-01',
            ])
            ->assertStatus(422);
    }

    public function test_job_generates_pdf_and_emails_it(): void
    {
        Mail::fake();

        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Martin', 'Ostermeier')->create([
            'check_in_date' => '2026-07-15', 'created_by' => $this->manager->id,
        ]);

        (new ExportPoliceFichesJob($this->hotel->id, '2026-07-01', '2026-07-31', 'manager@dar-test.tn'))->handle();

        Mail::assertSent(PoliceFichesExport::class, function (PoliceFichesExport $mail) {
            return $mail->hasTo('manager@dar-test.tn')
                && $mail->count >= 1
                && strlen($mail->pdfData) > 500          // un vrai PDF a été généré
                && str_starts_with($mail->pdfData, '%PDF');
        });
    }

    public function test_export_carries_the_identity_document_photo(): void
    {
        /*
         * L'export ne portait que le texte de la fiche. Tant que le relais
         * WhatsApp transmettait, l'écart passait inaperçu ; le 17/08/2026 Meta a
         * restreint le numéro émetteur et l'export est devenu la SEULE voie de
         * transmission — une fiche sans sa pièce n'est pas celle que l'autorité
         * attend.
         */
        Mail::fake();

        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Martin', 'Ostermeier')->create([
            'check_in_date' => '2026-07-15', 'created_by' => $this->manager->id,
        ]);

        // Un vrai JPEG, pour exercer réellement la chaîne de compression.
        $jpeg = (string) ImageManager::gd()
            ->create(1400, 900)->fill('cccccc')->toJpeg(90);

        DocumentScan::create([
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'file_path' => 'scans/absent.jpg',   // disque vide : la copie en base doit suffire
            'file_hash' => hash('sha256', $jpeg),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => strlen($jpeg),
            'image_data' => base64_encode($jpeg),
            'ocr_status' => 'done',
            'uploaded_by' => $this->manager->id,
        ]);

        $withPhoto = 0;
        (new ExportPoliceFichesJob($this->hotel->id, '2026-07-01', '2026-07-31', 'manager@dar-test.tn'))->handle();
        Mail::assertSent(PoliceFichesExport::class, function (PoliceFichesExport $mail) use (&$withPhoto) {
            $withPhoto = strlen($mail->pdfData);

            return str_starts_with($mail->pdfData, '%PDF');
        });

        // Même export sans scan : la photo pèse, donc le PDF doit être nettement
        // plus lourd quand elle est là. C'est la preuve qu'elle est réellement
        // embarquée, et pas seulement référencée.
        DocumentScan::query()->delete();
        Mail::fake();
        $withoutPhoto = 0;
        (new ExportPoliceFichesJob($this->hotel->id, '2026-07-01', '2026-07-31', 'manager@dar-test.tn'))->handle();
        Mail::assertSent(PoliceFichesExport::class, function (PoliceFichesExport $mail) use (&$withoutPhoto) {
            $withoutPhoto = strlen($mail->pdfData);

            return true;
        });

        $this->assertGreaterThan(
            $withoutPhoto + 3000,
            $withPhoto,
            'la pièce d\'identité doit être embarquée dans le PDF, pas seulement référencée',
        );
    }

    public function test_a_missing_photo_never_breaks_the_whole_export(): void
    {
        // Un scan illisible ne doit pas priver l'autorité des 44 autres fiches.
        Mail::fake();

        $checkIn = CheckIn::factory()->for($this->hotel)->active()->withGuest('Martin', 'Ostermeier')->create([
            'check_in_date' => '2026-07-15', 'created_by' => $this->manager->id,
        ]);

        DocumentScan::create([
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'file_path' => 'scans/nowhere.jpg',
            'file_hash' => hash('sha256', 'pas une image'),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 12,
            'image_data' => base64_encode('pas une image'),
            'ocr_status' => 'done',
            'uploaded_by' => $this->manager->id,
        ]);

        (new ExportPoliceFichesJob($this->hotel->id, '2026-07-01', '2026-07-31', 'manager@dar-test.tn'))->handle();

        Mail::assertSent(PoliceFichesExport::class, fn (PoliceFichesExport $mail) => $mail->count >= 1
            && str_starts_with($mail->pdfData, '%PDF'));
    }
}
