<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Delivery\FicheScanCropper;
use App\Services\Delivery\FicheScanImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * Cadrage des pièces d'identité dans les PDF de fiches.
 *
 * Les photos arrivent dans tous les formats : scan à plat en paysage, cliché
 * de téléphone en portrait, capture recadrée à la main. Rendues telles quelles,
 * chaque pièce avait sa propre taille dans le document — illisible et peu
 * sérieux pour une transmission à l'autorité.
 */
class FicheScanImageTest extends TestCase
{
    use RefreshDatabase;

    private CheckIn $checkIn;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $hotel = Hotel::factory()->withActiveSubscription()->create();
        $this->author = User::factory()->hotelAdmin($hotel)->create();
        $this->checkIn = CheckIn::factory()->for($hotel)->active()
            ->withGuest('Martin', 'Ostermeier')->create(['created_by' => $this->author->id]);
    }

    private function attachScan(int $width, int $height): void
    {
        DocumentScan::query()->delete();

        $jpeg = (string) ImageManager::gd()->create($width, $height)->fill('cccccc')->toJpeg(90);

        DocumentScan::create([
            'check_in_id' => $this->checkIn->id,
            'guest_id' => $this->checkIn->guests()->first()->id,
            'file_path' => 'scans/absent.jpg',
            'file_hash' => hash('sha256', $jpeg.$width.$height),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => strlen($jpeg),
            'image_data' => base64_encode($jpeg),
            'ocr_status' => 'done',
            'uploaded_by' => $this->author->id,
        ]);
    }

    /** @return array{0:int,1:int} dimensions du data URI produit */
    private function renderedSize(): array
    {
        $uri = FicheScanImage::dataUri($this->checkIn, $this->checkIn->guests()->first());
        $this->assertNotNull($uri);

        $binary = base64_decode(substr($uri, strlen('data:image/jpeg;base64,')));
        $image = ImageManager::gd()->read($binary);

        return [$image->width(), $image->height()];
    }

    public function test_every_document_lands_in_the_same_frame(): void
    {
        // Le cœur de la demande : un PDF uniforme, quelles que soient les
        // sources. Passeport scanné, CIN photographiée de travers, capture
        // d'écran — même cadre.
        config(['fiche.photo_width' => 1200, 'fiche.photo_height' => 800]);

        foreach ([[1600, 1200], [900, 1600], [400, 260], [3000, 2000], [800, 800]] as [$w, $h]) {
            $this->attachScan($w, $h);

            $this->assertSame([1200, 800], $this->renderedSize(), "source {$w}x{$h}");
        }
    }

    public function test_the_default_never_crops_the_document(): void
    {
        /*
         * Défaut délibérément 'pad' et non 'cover'. Une fiche de police porte
         * une pièce d'identité transmise à l'autorité : rogner peut emporter un
         * bord, un numéro, une bande MRZ — et rien dans le PDF ne signalerait
         * le manque. Un peu de blanc autour est un défaut visuel ; une pièce
         * amputée est un défaut de fond.
         *
         * On le vérifie par le contenu : une source dont les BORDS diffèrent du
         * centre garde ses bords en 'pad', les perd en 'cover'.
         */
        $this->assertSame('pad', config('fiche.photo_fit'));

        /*
         * Bande rouge au bord gauche d'une source PLUS LARGE que le cadre
         * (2400x800 pour un cadre 3:2). L'orientation compte : une source
         * portrait dans un cadre paysage se fait rogner en haut et en bas, pas
         * sur les côtés — c'est une source trop large qui perd ses bords
         * latéraux, là où se trouvent justement les bords d'un document
         * photographié de près.
         */
        DocumentScan::query()->delete();
        $source = ImageManager::gd()->create(2400, 800)->fill('ffffff');
        $source->drawRectangle(0, 0, function ($r) {
            $r->size(60, 800);
            $r->background('ff0000');
        });
        $jpeg = (string) $source->toJpeg(95);

        DocumentScan::create([
            'check_in_id' => $this->checkIn->id,
            'guest_id' => $this->checkIn->guests()->first()->id,
            'file_path' => 'scans/absent.jpg',
            'file_hash' => hash('sha256', $jpeg),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => strlen($jpeg),
            'image_data' => base64_encode($jpeg),
            'ocr_status' => 'done',
            'uploaded_by' => $this->author->id,
        ]);

        $this->assertTrue($this->containsRed(), 'le bord du document doit survivre au cadrage');

        // Et en 'cover', il disparaît : la preuve que le défaut protège bien
        // quelque chose de réel, et non une différence théorique.
        config(['fiche.photo_fit' => 'cover']);
        $this->assertFalse($this->containsRed(), 'cover rogne effectivement les bords');
    }

    /** La bande rouge du bord est-elle encore présente dans le rendu ? */
    private function containsRed(): bool
    {
        $uri = FicheScanImage::dataUri($this->checkIn, $this->checkIn->guests()->first());
        $image = ImageManager::gd()->read(base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));

        for ($x = 0; $x < $image->width(); $x += 8) {
            $c = $image->pickColor($x, (int) ($image->height() / 2))->toArray();
            if ($c[0] > 150 && $c[1] < 90 && $c[2] < 90) {
                return true;
            }
        }

        return false;
    }

    public function test_a_missing_scan_yields_null_rather_than_an_error(): void
    {
        // Une fiche sans pièce doit partir quand même : l'autorité préfère une
        // fiche incomplète à pas de fiche du tout.
        DocumentScan::query()->delete();

        $this->assertNull(FicheScanImage::dataUri($this->checkIn, $this->checkIn->guests()->first()));
    }

    public function test_an_unreadable_scan_never_breaks_the_fiche(): void
    {
        DocumentScan::query()->delete();
        DocumentScan::create([
            'check_in_id' => $this->checkIn->id,
            'guest_id' => $this->checkIn->guests()->first()->id,
            'file_path' => 'scans/nowhere.jpg',
            'file_hash' => hash('sha256', 'x'),
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => 12,
            'image_data' => base64_encode('pas une image'),
            'ocr_status' => 'done',
            'uploaded_by' => $this->author->id,
        ]);

        $this->assertNull(FicheScanImage::dataUri($this->checkIn, $this->checkIn->guests()->first()));
    }

    // ── Détourage par modèle de vision ───────────────────────────────────────

    public function test_the_detected_frame_is_applied_and_cached(): void
    {
        // Le rectangle est mémorisé sur le scan : la même pièce est rendue par
        // trois chemins (WhatsApp, export, récapitulatif) et ne doit être
        // analysée qu'une fois.
        config(['fiche.ai_crop.enabled' => true, 'fiche.ai_crop.api_key' => 'test']);
        $this->attachScan(2000, 2000);
        $scan = DocumentScan::first();

        $calls = 0;
        $this->app->instance(FicheScanCropper::class,
            new class($calls) extends FicheScanCropper
            {
                public function __construct(public int &$calls) {}

                public function detect(string $binary): ?array
                {
                    $this->calls++;

                    return ['x' => 0.25, 'y' => 0.25, 'width' => 0.5, 'height' => 0.5];
                }
            });

        $this->renderedSize();
        $this->renderedSize();

        $scan->refresh();
        $this->assertSame(1, $calls, 'une seule analyse par pièce');
        $this->assertEqualsWithDelta(0.5, $scan->crop_box['width'], 0.001);
        $this->assertNotNull($scan->crop_detected_at);
    }

    public function test_a_failing_vision_api_never_blocks_a_fiche(): void
    {
        /*
         * LE test de ce fichier. Le récapitulatif quotidien est la voie de
         * transmission légale des fiches pendant l'absence de l'exploitant :
         * une API muette, en panne ou hors quota ne doit jamais faire plus que
         * priver d'un cadrage plus serré.
         */
        config(['fiche.ai_crop.enabled' => true, 'fiche.ai_crop.api_key' => 'test']);
        $this->attachScan(1600, 1200);

        $this->app->instance(FicheScanCropper::class,
            new class extends FicheScanCropper
            {
                public function forScan(DocumentScan $scan, string $binary): ?array
                {
                    throw new \RuntimeException('API injoignable');
                }
            });

        $this->assertSame([1200, 800], $this->renderedSize(), 'la pièce part quand même');
    }

    public function test_an_undetected_document_is_not_re_analysed_forever(): void
    {
        // Sans mémoriser l'échec, un document que le modèle ne sait pas situer
        // serait resoumis à chaque rendu — un appel par fiche et par jour.
        config(['fiche.ai_crop.enabled' => true, 'fiche.ai_crop.api_key' => 'test']);
        $this->attachScan(1600, 1200);

        $calls = 0;
        $this->app->instance(FicheScanCropper::class,
            new class($calls) extends FicheScanCropper
            {
                public function __construct(public int &$calls) {}

                public function detect(string $binary): ?array
                {
                    $this->calls++;

                    return null;
                }
            });

        $this->renderedSize();
        $this->renderedSize();

        $this->assertSame(1, $calls);
        $this->assertNotNull(DocumentScan::first()->crop_detected_at);
        $this->assertNull(DocumentScan::first()->crop_box);
    }

    public function test_the_feature_is_off_until_someone_turns_it_on(): void
    {
        // Le chemin de transmission légale ne gagne pas une dépendance réseau
        // par défaut : on l'active après avoir vu un rendu.
        $this->assertFalse(config('fiche.ai_crop.enabled'));
    }
}
