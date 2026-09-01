<?php

namespace Tests\Unit;

use App\Services\Delivery\FicheScanCropper;
use Tests\TestCase;

/**
 * Contrôle de vraisemblance du rectangle rendu par le modèle.
 *
 * C'est la partie qui décide si une pièce d'identité va être rognée. Elle doit
 * pouvoir être éprouvée sans appeler quoi que ce soit — un modèle qui se trompe
 * ne doit pas pouvoir amputer un document transmis à l'autorité.
 */
class FicheScanCropperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['fiche.ai_crop.margin' => 0.0, 'fiche.ai_crop.min_area' => 0.05]);
    }

    public function test_a_plausible_frame_is_accepted(): void
    {
        $box = FicheScanCropper::validate([
            'found' => true, 'x' => 0.1, 'y' => 0.2, 'width' => 0.6, 'height' => 0.5,
        ]);

        $this->assertEqualsWithDelta(0.1, $box['x'], 0.0001);
        $this->assertEqualsWithDelta(0.6, $box['width'], 0.0001);
    }

    public function test_the_margin_widens_the_frame_without_leaving_the_image(): void
    {
        // La détection est bonne, pas exacte au pixel : un recadrage au ras
        // rognerait tôt ou tard un bord de passeport ou une ligne de MRZ.
        config(['fiche.ai_crop.margin' => 0.1]);

        $box = FicheScanCropper::validate([
            'found' => true, 'x' => 0.0, 'y' => 0.0, 'width' => 1.0, 'height' => 1.0,
        ]);

        $this->assertSame(0.0, $box['x']);
        $this->assertEqualsWithDelta(1.0, $box['width'], 0.0001, 'jamais au-delà de l\'image');
    }

    public function test_an_implausible_frame_is_refused(): void
    {
        $refused = [
            'non trouvé' => ['found' => false, 'x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            'largeur nulle' => ['found' => true, 'x' => 0.1, 'y' => 0.1, 'width' => 0, 'height' => 0.5],
            'hors bornes' => ['found' => true, 'x' => 0.8, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
            'négatif' => ['found' => true, 'x' => -0.1, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
            'champ manquant' => ['found' => true, 'x' => 0.1, 'y' => 0.1, 'width' => 0.5],
            'non numérique' => ['found' => true, 'x' => 'gauche', 'y' => 0.1, 'width' => 0.5, 'height' => 0.5],
            'réponse vide' => null,
        ];

        foreach ($refused as $label => $payload) {
            $this->assertNull(FicheScanCropper::validate($payload), $label);
        }
    }

    public function test_a_frame_too_small_to_be_the_document_is_refused(): void
    {
        // Plus probablement un tampon, ou la photo d'identité imprimée SUR le
        // document — recadrer là-dessus perdrait toute la pièce.
        $this->assertNull(FicheScanCropper::validate([
            'found' => true, 'x' => 0.4, 'y' => 0.4, 'width' => 0.1, 'height' => 0.1,
        ]));
    }
}
