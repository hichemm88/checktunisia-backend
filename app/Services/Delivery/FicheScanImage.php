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

            $width = (int) config('fiche.photo_width', 1200);
            $height = (int) config('fiche.photo_height', 800);

            config('fiche.photo_fit', 'pad') === 'cover'
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
