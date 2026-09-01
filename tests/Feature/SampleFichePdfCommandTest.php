<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Hotel;
use App\Models\User;
use App\Models\WhatsappSendLog;
use App\Support\BrandLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * `whatsapp:sample-pdf` — contrôler ce qui part, sans rien changer.
 *
 * L'outil sert à ouvrir le PDF d'une fiche pour vérifier de visu ce que reçoit
 * un destinataire. Sa propriété essentielle n'est pas d'écrire un fichier :
 * c'est de ne RIEN écrire en base.
 *
 * `whatsapp_send_log` est le registre de transmission des fiches de police. Un
 * outil de diagnostic qui y touche fabrique de la preuve — et le ferait
 * silencieusement, puisque personne ne relit une ligne d'outbox après avoir
 * regardé un PDF.
 */
class SampleFichePdfCommandTest extends TestCase
{
    use RefreshDatabase;

    private CheckIn $checkIn;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Échantillon']);
        $this->author = User::factory()->hotelAdmin($hotel)->create();
        $this->checkIn = CheckIn::factory()->for($hotel)->active()
            ->withGuest('Mathlouthi', 'Hicham')->create(['created_by' => $this->author->id]);

        foreach (glob(storage_path('app/whatsapp/samples/*.pdf')) ?: [] as $stale) {
            unlink($stale);
        }
    }

    private function job(bool $withScan = true): WhatsappSendLog
    {
        $guest = $this->checkIn->guests()->first();

        if ($withScan) {
            $jpeg = (string) ImageManager::gd()->create(2000, 1400)->fill('dddddd')->toJpeg(85);

            DocumentScan::create([
                'check_in_id' => $this->checkIn->id,
                'guest_id' => $guest->id,
                'file_path' => 'scans/absent.jpg',
                'file_hash' => hash('sha256', $jpeg),
                'mime_type' => 'image/jpeg',
                'file_size_bytes' => strlen($jpeg),
                'image_data' => base64_encode($jpeg),
                'ocr_status' => 'done',
                'uploaded_by' => $this->author->id,
            ]);
        }

        return WhatsappSendLog::create([
            'hotel_id' => $this->checkIn->hotel_id,
            'check_in_id' => $this->checkIn->id,
            'guest_id' => $guest->id,
            'recipient' => '21620123456',
            'caption' => 'Fiche',
            'status' => WhatsappSendLog::STATUS_PENDING,
            'queued_at' => now(),
        ]);
    }

    public function test_it_writes_the_pdf_and_names_the_path(): void
    {
        $job = $this->job();

        $this->artisan('whatsapp:sample-pdf', ['send_log_id' => $job->id])
            ->expectsOutputToContain('whatsapp/samples')
            ->assertExitCode(0);

        $written = glob(storage_path('app/whatsapp/samples/*.pdf')) ?: [];

        $this->assertCount(1, $written);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($written[0]));
    }

    public function test_it_never_writes_to_the_database(): void
    {
        $job = $this->job();
        $before = $job->fresh()->getAttributes();

        $this->artisan('whatsapp:sample-pdf', ['send_log_id' => $job->id])->assertExitCode(0);

        // Attribut par attribut : un statut, une tentative ou un horodatage
        // modifiés au passage fausseraient le registre de transmission.
        $this->assertSame($before, $job->fresh()->getAttributes());

        // Et rien n'a été enfilé au passage.
        $this->assertSame(1, WhatsappSendLog::count());
    }

    public function test_an_unknown_identifier_fails_without_writing_anything(): void
    {
        $this->artisan('whatsapp:sample-pdf', ['send_log_id' => (string) \Illuminate\Support\Str::uuid()])
            ->assertExitCode(1);

        $this->assertSame([], glob(storage_path('app/whatsapp/samples/*.pdf')) ?: []);
    }

    public function test_a_row_without_a_stay_says_so_instead_of_writing_an_empty_pdf(): void
    {
        // Cas réel : le séjour a été supprimé depuis l'enfilage. Mieux vaut le
        // dire que produire une fiche vide qu'on croirait représentative.
        $orphan = WhatsappSendLog::create([
            'recipient' => '21620123456',
            'caption' => 'Fiche orpheline',
            'status' => WhatsappSendLog::STATUS_PENDING,
            'queued_at' => now(),
        ]);

        $this->artisan('whatsapp:sample-pdf', ['send_log_id' => $orphan->id])
            ->expectsOutputToContain('check-in ou voyageur absent')
            ->assertExitCode(1);
    }

    public function test_the_link_page_carries_the_logo_and_falls_back_without_it(): void
    {
        /*
         * La page servie par /f/{token} en mode « info » est ce que voit un
         * policier qui clique depuis WhatsApp. Elle ne doit jamais s'afficher
         * sans identification : ni logo, ni lettre, ce serait une page blanche
         * au nom de personne — exactement ce qu'on n'envoie pas à l'autorité.
         *
         * Les deux branches sont pilotées par la configuration, et non par la
         * donnée passée à la vue : un compositeur alimente `brandLogo`, et sa
         * valeur l'emporte sur celle que fournirait un appelant.
         */
        config(['whatsapp.fiche_link_mode' => 'info']);
        $job = $this->job(withScan: false);

        config(['fiche.logo_path' => '']);
        BrandLogo::forget();

        $this->get('/f/'.$job->public_token)
            ->assertOk()
            ->assertSee('data:image/png;base64,', false)
            ->assertDontSee('>Q</div>', false);

        config(['fiche.logo_path' => sys_get_temp_dir().'/qayed-absent-'.uniqid().'.png']);
        BrandLogo::forget();

        $this->get('/f/'.$job->public_token)
            ->assertOk()
            ->assertSee('>Q</div>', false)
            ->assertDontSee('data:image/png;base64,', false);

        config(['fiche.logo_path' => '']);
        BrandLogo::forget();
    }

    public function test_the_pdf_stays_light_enough_for_a_phone(): void
    {
        $job = $this->job();

        $this->artisan('whatsapp:sample-pdf', ['send_log_id' => $job->id])->assertExitCode(0);

        $written = (glob(storage_path('app/whatsapp/samples/*.pdf')) ?: [])[0];

        // Meta plafonne à 100 Mo, mais le seuil qui compte est celui du
        // confort : la pièce jointe est lue sur un téléphone, souvent en
        // mobilité. 2 Mo est la limite qu'on s'est fixée.
        $this->assertLessThan(2 * 1024 * 1024, filesize($written));
    }
}
