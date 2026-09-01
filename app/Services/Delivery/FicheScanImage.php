<?php

namespace App\Services\Delivery;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Pièce d'identité d'un voyageur, cadrée pour un PDF de fiche de police.
 *
 * ── Pourquoi ce service existe ───────────────────────────────────────────────
 *
 * Trois chemins produisent une fiche — le relais WhatsApp (canal Cloud),
 * l'export par établissement et le récapitulatif quotidien — et chacun avait
 * recopié la même vingtaine de lignes. Trois copies, c'est trois endroits où
 * corriger un cadrage, et la certitude qu'une même pièce finirait par ne pas
 * avoir la même tête selon la voie empruntée.
 *
 * ── Le cadrage ───────────────────────────────────────────────────────────────
 *
 * Les photos arrivent dans tous les formats : scan à plat, cliché de téléphone
 * en portrait, capture recadrée à la main. Rendues telles quelles, elles
 * donnaient un PDF où chaque pièce avait sa propre taille. Toutes sont
 * désormais ramenées à un cadre unique.
 *
 * Par défaut la pièce est CONTENUE dans ce cadre, pas rognée. C'est un choix,
 * et il tient à ce qu'est ce document : une pièce d'identité transmise à
 * l'autorité. Rogner peut emporter un bord, un numéro, une bande MRZ — et rien
 * dans le PDF ne signalerait le manque. Un peu de blanc autour est un défaut
 * visuel ; une pièce amputée est un défaut de fond. Voir config/fiche.php pour
 * passer en 'cover' quand les photos d'un parc sont déjà cadrées serré.
 *
 * Tout est best-effort : une pièce illisible ne doit jamais empêcher la fiche
 * de partir, ni les autres fiches du même document.
 */
class FicheScanImage
{
    /** @return string|null data URI JPEG, ou null si aucune pièce exploitable */
    public static function dataUri(CheckIn $checkIn, Guest $guest): ?string
    {
        $scan = DocumentScan::forFiche($checkIn, $guest);

        if (!$scan) {
            return null;
        }

        $binary = self::bytes($scan);

        if ($binary === null) {
            return null;
        }

        try {
            $image = ImageManager::gd()->read($binary);

            // Les clichés de téléphone portent leur rotation en métadonnée EXIF
            // plutôt que dans les pixels. Sans ce redressement, une pièce
            // parfaitement lisible à l'écran arrive couchée dans le PDF.
            $image->orient();

            // Détourage du décor, quand un modèle de vision a su situer la
            // pièce. Appliqué APRÈS le redressement : le rectangle décrit
            // l'image telle qu'on la voit, pas telle qu'elle est stockée.
            $detoured = self::cropToDocument($image, $scan);

            [$width, $height] = self::frameFor($image);

            self::fitToFrame($image, $width, $height, $detoured)
                ? $image->cover($width, $height)
                : $image->pad($width, $height, 'ffffff');

            return 'data:image/jpeg;base64,'
                .base64_encode((string) $image->toJpeg((int) config('fiche.photo_quality', 70)));
        } catch (\Throwable $e) {
            Log::warning('[fiche] pièce non embarquée pour le voyageur '.$guest->id.' : '.$e->getMessage());

            return null;
        }
    }

    /**
     * Cadre de sortie, orienté comme la photo source.
     *
     * ── Le défaut que ceci corrige ───────────────────────────────────────────
     *
     * Le cadre était fixe et PAYSAGE (1200x800). Avec « pad », une photo
     * portrait y était encadrée de bandes vides : la pièce n'occupait plus que
     * 35 % de la largeur, contre 75 % pour la même pièce photographiée à
     * l'horizontale. Mesuré sur une carte identique, cela faisait 133 dpi
     * utiles contre 289 — un facteur 2,2 perdu par la seule orientation du
     * téléphone.
     *
     * Or photographier une carte tenue en main donne très souvent un cliché
     * vertical. Autrement dit, le cas le plus fréquent était le moins lisible,
     * et rien dans le PDF ne le signalait : la pièce est bien là, simplement
     * trop petite pour qu'on lise le numéro.
     *
     * Le cadre bascule donc avec la source. Aucun pixel n'est rogné pour
     * autant — « pad » reste la règle, et une pièce carrée ou de proportion
     * inattendue continue d'être complétée de blanc plutôt que coupée.
     *
     * @return array{0:int,1:int} largeur, hauteur
     */
    private static function frameFor($image): array
    {
        $long = (int) config('fiche.photo_long_edge', 1600);
        $short = (int) config('fiche.photo_short_edge', 1067);

        // À égalité (photo carrée), on garde le paysage : c'est l'orientation
        // du bloc qui accueille la pièce dans la vue.
        return $image->height() > $image->width()
            ? [$short, $long]
            : [$long, $short];
    }

    /**
     * Remplir le cadre, ou le compléter de blanc ?
     *
     * Sans détourage, jamais remplir : rogner une pièce d'identité peut emporter
     * un bord ou une bande MRZ, et rien dans le PDF ne signalerait le manque.
     *
     * Après détourage, c'est l'inverse qui serait absurde. Le rectangle détecté
     * a été élargi d'une marge PRÉCISÉMENT pour pouvoir être rogné sans toucher
     * au document ; le compléter de blanc par-dessus laisserait la pièce
     * flotter au milieu de bandes vides — le rendu que ce détourage était censé
     * corriger.
     *
     * On ne remplit donc que si le rognage nécessaire tient DANS cette marge.
     * Un rectangle de forme inattendue — le modèle s'est trompé de sujet, ou la
     * pièce est photographiée de biais — retombe sur le blanc plutôt que de se
     * faire couper.
     */
    private static function fitToFrame($image, int $width, int $height, bool $detoured): bool
    {
        if (!$detoured) {
            return config('fiche.photo_fit', 'pad') === 'cover';
        }

        $source = $image->width() / max(1, $image->height());
        $frame = $width / max(1, $height);

        // Part de l'image que « cover » sacrifierait, sur l'axe le plus long.
        $sacrifice = $source > $frame
            ? 1 - ($frame / $source)
            : 1 - ($source / $frame);

        // Part que la marge a ajoutée sur un axe (moitié de chaque côté).
        $margin = (float) config('fiche.ai_crop.margin', 0.06);
        $available = (2 * $margin) / (1 + 2 * $margin);

        return $sacrifice <= $available;
    }

    /**
     * Réduit l'image au document, si un cadre a pu être établi.
     *
     * Tout échec est absorbé : sans cadre, l'image continue vers le cadrage
     * géométrique, qui ne perd rien. Le détourage est un confort de lecture, pas
     * une condition de transmission — et il ne doit jamais devenir un motif de
     * fiche manquante.
     *
     * @return bool vrai si l'image a réellement été réduite au document
     */
    private static function cropToDocument($image, DocumentScan $scan): bool
    {
        try {
            $box = app(FicheScanCropper::class)->forScan($scan, (string) $image->toJpeg(90));

            if (!$box) {
                return false;
            }

            $w = max(1, (int) round($image->width() * $box['width']));
            $h = max(1, (int) round($image->height() * $box['height']));

            $image->crop(
                $w,
                $h,
                (int) round($image->width() * $box['x']),
                (int) round($image->height() * $box['y']),
                position: 'top-left',
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('[fiche] détourage ignoré pour le scan '.$scan->id.' : '.$e->getMessage());

            return false;
        }
    }

    /**
     * Octets de la pièce : copie en base d'abord, fichier disque ensuite.
     *
     * Cet ordre n'est pas arbitraire — le disque de Railway est éphémère et les
     * fichiers de scans disparaissent à chaque redéploiement, alors que la copie
     * compressée en base survit.
     */
    private static function bytes(DocumentScan $scan): ?string
    {
        if (($bytes = $scan->imageBytes()) !== null) {
            return $bytes;
        }

        $disk = config('filesystems.passport_scan_disk', 'local');

        if (!$scan->file_path || !Storage::disk($disk)->exists($scan->file_path)) {
            return null;
        }

        try {
            return Storage::disk($disk)->get($scan->file_path);
        } catch (\Throwable $e) {
            Log::warning('[fiche] lecture disque impossible pour le scan '.$scan->id.' : '.$e->getMessage());

            return null;
        }
    }
}
