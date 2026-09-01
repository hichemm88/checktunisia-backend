<?php

namespace Tests\Unit;

use App\Support\BrandLogo;
use Tests\TestCase;

/**
 * Le logo des PDF, et son repli.
 *
 * Deux comportements comptent, et le second plus que le premier : un dépôt
 * fraîchement cloné n'a pas le fichier, et une fiche de police sans en-tête
 * partirait quand même — c'est au repli de garantir qu'elle reste identifiable.
 *
 * Étend le TestCase de Laravel — `resource_path()` exige l'application — mais
 * sans RefreshDatabase : depuis que le seeder de rôles est conditionné au
 * trait, un test peut démarrer l'application sans toucher la base.
 */
class BrandLogoTest extends TestCase
{
    private string $path;

    private bool $ownsFile = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = dirname(__DIR__, 2).'/resources/images/qayed-logo.png';
        BrandLogo::forget();
    }

    protected function tearDown(): void
    {
        // On ne supprime QUE le fichier qu'on a nous-même déposé : le vrai logo,
        // le jour où il sera versionné, ne doit pas disparaître à cause d'un test.
        if ($this->ownsFile && is_file($this->path)) {
            unlink($this->path);
        }

        BrandLogo::forget();
        parent::tearDown();
    }

    public function test_a_missing_logo_falls_back_instead_of_failing(): void
    {
        if (is_file($this->path)) {
            $this->markTestSkipped('Un logo est déjà en place : le cas « absent » ne peut pas être joué sans le détruire.');
        }

        // null n'est pas une erreur : c'est le signal que la vue doit afficher
        // le mot-symbole.
        $this->assertNull(BrandLogo::dataUri());
    }

    public function test_a_present_logo_is_embedded_as_a_data_uri(): void
    {
        if (is_file($this->path)) {
            $this->markTestSkipped('Un logo est déjà en place ; ce test dépose le sien.');
        }

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        // Un PNG 1x1 transparent, le plus petit fichier valide possible.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($this->path, $png);
        $this->ownsFile = true;

        BrandLogo::forget();
        $uri = BrandLogo::dataUri();

        // Embarqué, pas référencé : DomPDF ne va chercher aucune ressource
        // distante, une URL laisserait un cadre vide dans le PDF.
        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame($png, base64_decode(substr($uri, strlen('data:image/png;base64,'))));
    }
}
