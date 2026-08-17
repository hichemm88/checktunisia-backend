<?php

namespace Tests\Feature;

use App\Mail\PoliceDailyDigest;
use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Hotel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Récapitulatif quotidien des fiches de police, tous établissements.
 *
 * Mis en place pendant l'absence de l'exploitant (18–28/08/2026), le relais
 * WhatsApp étant hors service. Ce qui est protégé ici n'est pas un confort :
 * c'est la transmission légale des fiches, et le périmètre des données
 * personnelles qui quittent la plateforme.
 */
class PoliceDailyDigestTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotel = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Alpha']);
        $this->owner = User::factory()->hotelAdmin($this->hotel)->create(['email' => 'patron@qayed.tn']);

        config([
            'police_digest.recipient' => 'patron@qayed.tn',
            'police_digest.until' => null,
            'police_digest.hotels' => null,
        ]);

        Mail::fake();
    }

    private function organisation(string $name): Organization
    {
        // Pas de fabrique Organization dans le dépôt : création directe.
        return Organization::create([
            'name' => $name,
            'entity_type' => 'company',
            'contact_email' => Str::slug($name).'@example.tn',
            'status' => 'active',
        ]);
    }

    private function arrival(Hotel $hotel, string $last = 'Ostermeier', string $first = 'Martin'): CheckIn
    {
        return CheckIn::factory()->for($hotel)->active()->withGuest($first, $last)->create([
            'check_in_date' => now()->toDateString(),
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_digest_covers_every_establishment_of_the_recipient(): void
    {
        $org = $this->organisation('Groupe Alpha');
        $this->hotel->update(['organization_id' => $org->id]);
        $second = Hotel::factory()->withActiveSubscription()->create([
            'name' => 'Dar Beta', 'organization_id' => $org->id,
        ]);
        $this->owner->update(['organization_id' => $org->id]);

        $this->arrival($this->hotel);
        $this->arrival($second, 'Zanoori', 'Rustum');

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->hasTo('patron@qayed.tn')
            && $m->count === 2
            && $m->hotelCount === 2
            && str_starts_with($m->pdfData, '%PDF'));
    }

    public function test_digest_never_reaches_beyond_the_recipient_scope(): void
    {
        /*
         * Le point le plus important du fichier. Qayed héberge les voyageurs de
         * PLUSIEURS clients : « tous mes établissements » ne peut pas devenir
         * « tous les établissements de la plateforme ». Envoyer à une boîte
         * personnelle les pièces d'identité des clients d'autrui serait une
         * violation caractérisée.
         */
        $stranger = Hotel::factory()->withActiveSubscription()->create([
            'name' => 'Hôtel d\'un autre client',
            'organization_id' => $this->organisation('Client tiers')->id,
        ]);

        $this->arrival($this->hotel);
        $this->arrival($stranger, 'Inconnu', 'Voyageur');

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 1
            && $m->hotelCount === 1);
    }

    public function test_digest_refuses_rather_than_widening_when_scope_is_unknown(): void
    {
        // Une adresse sans compte Qayed : le périmètre est indéterminable. Ne
        // rien envoyer est le seul comportement acceptable — l'alternative
        // serait de tout envoyer.
        config(['police_digest.recipient' => 'inconnu@example.com']);
        $this->arrival($this->hotel);

        $this->artisan('police:daily-digest')->assertExitCode(1);

        Mail::assertNothingSent();
    }

    public function test_explicit_hotel_list_overrides_the_deduced_scope(): void
    {
        $other = Hotel::factory()->withActiveSubscription()->create(['name' => 'Dar Gamma']);
        $this->arrival($this->hotel);
        $this->arrival($other, 'Gamma', 'Invité');

        config(['police_digest.hotels' => $other->id]);

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 1
            && $m->hotelCount === 1);
    }

    public function test_digest_carries_the_identity_photo(): void
    {
        $checkIn = $this->arrival($this->hotel);
        $jpeg = (string) ImageManager::gd()->create(1200, 800)->fill('bbbbbb')->toJpeg(90);

        DocumentScan::create([
            'check_in_id' => $checkIn->id,
            'guest_id' => $checkIn->guests()->first()->id,
            'file_path' => 'scans/absent.jpg',
            'file_hash' => hash('sha256', $jpeg),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => strlen($jpeg),
            'image_data' => base64_encode($jpeg),
            'ocr_status' => 'done',
            'uploaded_by' => $this->owner->id,
        ]);

        $withPhoto = 0;
        $this->artisan('police:daily-digest')->assertExitCode(0);
        Mail::assertSent(PoliceDailyDigest::class, function (PoliceDailyDigest $m) use (&$withPhoto) {
            $withPhoto = strlen($m->pdfData);

            return $m->withoutPhoto === 0;
        });

        // Sans scan, le même envoi doit être nettement plus léger : c'est la
        // preuve que la pièce est embarquée, pas seulement référencée.
        DocumentScan::query()->delete();
        Mail::fake();
        $withoutPhoto = 0;
        $this->artisan('police:daily-digest')->assertExitCode(0);
        Mail::assertSent(PoliceDailyDigest::class, function (PoliceDailyDigest $m) use (&$withoutPhoto) {
            $withoutPhoto = strlen($m->pdfData);

            return $m->withoutPhoto === 1;
        });

        $this->assertGreaterThan($withoutPhoto + 3000, $withPhoto);
    }

    public function test_a_quiet_day_still_sends_a_message(): void
    {
        // Un silence serait indiscernable d'une panne. Le destinataire est en
        // voyage : il doit pouvoir constater chaque soir que le système vit.
        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 0);
    }

    public function test_rolling_window_does_not_drop_late_arrivals(): void
    {
        /*
         * Le défaut qu'une fenêtre « journée civile » aurait introduit : un
         * envoi à 17 h perdrait DÉFINITIVEMENT les fiches enregistrées après
         * 17 h, puisque le lendemain couvrirait le jour suivant. Pour une
         * transmission légale, une omission silencieuse est le pire défaut.
         */
        $late = $this->arrival($this->hotel, 'Tardif', 'Arrivant');
        $late->forceFill(['created_at' => now()->subHours(20)])->save();

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 1);
    }

    public function test_window_excludes_what_a_previous_run_already_covered(): void
    {
        // Au-delà de 24 h, la fiche est déjà partie la veille — et sa photo a
        // été purgée. La réexpédier gonflerait le document sans rien apporter.
        $old = $this->arrival($this->hotel, 'Ancien', 'Voyageur');
        $old->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 0);
    }

    public function test_a_date_argument_forces_a_calendar_day_catch_up(): void
    {
        $old = $this->arrival($this->hotel, 'Rattrapage', 'Fiche');
        $old->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('police:daily-digest', ['date' => now()->toDateString()])->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class, fn (PoliceDailyDigest $m) => $m->count === 1);
    }

    public function test_the_schedule_stops_itself_after_the_deadline(): void
    {
        /*
         * Un envoi quotidien de pièces d'identité ne doit pas survivre à la
         * raison qui l'a motivé faute qu'on ait pensé à l'arrêter. La borne
         * s'applique sans redéploiement.
         */
        config(['police_digest.until' => Carbon::now('Africa/Tunis')->subDay()->toDateString()]);
        $this->arrival($this->hotel);

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_the_deadline_day_itself_is_still_sent(): void
    {
        // « Jusqu'au 28 » inclut le 28 : un décalage d'un jour ferait manquer
        // le dernier envoi, celui de la veille du retour.
        config(['police_digest.until' => Carbon::now('Africa/Tunis')->toDateString()]);
        $this->arrival($this->hotel);

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertSent(PoliceDailyDigest::class);
    }

    public function test_no_recipient_makes_the_task_inert(): void
    {
        config(['police_digest.recipient' => null]);
        $this->arrival($this->hotel);

        $this->artisan('police:daily-digest')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
