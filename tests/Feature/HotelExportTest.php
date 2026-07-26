<?php

namespace Tests\Feature;

use App\Jobs\ExportPoliceFichesJob;
use App\Mail\PoliceFichesExport;
use App\Models\CheckIn;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
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
}
