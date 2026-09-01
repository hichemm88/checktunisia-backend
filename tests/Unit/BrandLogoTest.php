<?php

namespace Tests\Unit;

use App\Support\BrandLogo;
use Tests\TestCase;

/**
 * Le logo, et son repli.
 *
 * Deux comportements comptent, et le second plus que le premier : le fichier
 * peut manquer sur un dépôt fraîchement cloné ou être oublié au déploiement,
 * et la fiche partirait quand même — c'est au repli de garantir qu'elle reste
 * identifiable.
 *
 * ── Pourquoi le chemin est configurable ──────────────────────────────────────
 *
 * Ces deux tests se SAUTAIENT dès que le vrai logo a été versionné : le cas
 * « absent » ne pouvait plus être joué qu'en supprimant un fichier du dépôt,
 * ce qu'un test n'a pas le droit de faire. Deux tests verts par abstention,
 * en local comme en CI — un garde-fou qui ne s'exécute jamais ne garde rien.
 *
 * `fiche.logo_path` les rend tous deux exerçables sans toucher au dépôt.
 */
class BrandLogoTest extends TestCase
{
    /** PNG 1x1 transparent — le plus petit fichier valide. */
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/qayed-logo-'.uniqid();
        mkdir($this->directory, 0755, true);
        BrandLogo::forget();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);

        BrandLogo::forget();
        parent::tearDown();
    }

    public function test_a_missing_logo_falls_back_instead_of_failing(): void
    {
        config(['fiche.logo_path' => $this->directory.'/absent.png']);

        // null n'est pas une erreur : c'est le signal que la vue doit afficher
        // le mot-symbole.
        $this->assertNull(BrandLogo::dataUri());
    }

    public function test_an_empty_file_falls_back_too(): void
    {
        // Un fichier vide est le résultat typique d'un transfert interrompu.
        // Il existe, donc le contrôle d'existence seul le laisserait passer —
        // et produirait une balise `<img>` cassée dans un document transmis à
        // l'autorité.
        $path = $this->directory.'/vide.png';
        touch($path);
        config(['fiche.logo_path' => $path]);

        $this->assertNull(BrandLogo::dataUri());
    }

    public function test_a_present_logo_is_embedded_as_a_data_uri(): void
    {
        $path = $this->directory.'/logo.png';
        $png = base64_decode(self::PIXEL);
        file_put_contents($path, $png);
        config(['fiche.logo_path' => $path]);

        $uri = BrandLogo::dataUri();

        // Embarqué, pas référencé : DomPDF ne va chercher aucune ressource
        // distante, une URL laisserait un cadre vide dans le PDF.
        $this->assertIsString($uri);
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertSame($png, base64_decode(substr($uri, strlen('data:image/png;base64,'))));
    }

    public function test_the_shipped_logo_is_readable(): void
    {
        // Le fichier réellement versionné, par son chemin par défaut. Ce test
        // échoue si quelqu'un le supprime, le renomme, ou le commite vide —
        // les trois façons dont un dépôt perd son logo sans que personne ne
        // s'en aperçoive avant la première fiche envoyée.
        config(['fiche.logo_path' => '']);

        $uri = BrandLogo::dataUri();

        $this->assertIsString($uri, 'resources/images/qayed-logo.png est absent ou illisible.');
        $this->assertStringStartsWith('data:image/png;base64,', $uri);
    }
}
