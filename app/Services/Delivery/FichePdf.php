<?php

namespace App\Services\Delivery;

use App\Models\CheckIn;
use App\Models\DocumentScan;
use App\Models\Guest;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\FicheFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Rend UNE fiche de police en PDF, pièce d'identité comprise.
 *
 * ── Pourquoi un PDF plutôt qu'un message texte ───────────────────────────────
 *
 * La Cloud API refuse le texte libre hors de la fenêtre de 24 h, et nos
 * destinataires ne répondent jamais : tout passe donc par un modèle approuvé.
 * Or une variable de modèle ne peut contenir aucun retour à la ligne — la
 * fiche, multi-ligne, ne rentre dans aucune variable.
 *
 * La fiche part donc en pièce jointe, dans l'en-tête « document » du modèle.
 * Ce détour règle au passage la photo : elle est dans le PDF, alors que
 * l'adaptateur Cloud ne savait jusqu'ici envoyer que du texte nu.
 *
 * Même vue Blade que l'export par email (`pdf.police-fiches`, avec une seule
 * fiche) : ce que l'autorité reçoit par WhatsApp et ce qu'elle reçoit par mail
 * sont le même document, et non deux mises en page à maintenir en parallèle.
 */
class FichePdf
{
    /**
     * @return string|null octets PDF, ou null si le job ne porte pas de fiche
     *                     réelle (message de test, check-in supprimé).
     */
    public static function forJob(WhatsappSendLog $job): ?string
    {
        $checkIn = $job->checkIn;
        $guest = $job->guest;

        if (!$checkIn || !$guest) {
            return null;
        }

        $checkIn->loadMissing(['hotel.address', 'room', 'guests.documents']);

        $fiche = FicheFormatter::fields($checkIn, $guest);
        $fiche['photo'] = self::photoDataUri($checkIn, $guest);

        $hotel = $checkIn->hotel;

        return Pdf::loadView('pdf.police-fiches', [
            'hotelName' => $hotel?->name ?? '—',
            'hotelAddress' => trim(implode(', ', array_filter([
                $hotel?->address?->line1, $hotel?->address?->city, $hotel?->address?->governorate,
            ]))) ?: '—',
            'rangeLabel' => Carbon::parse($checkIn->check_in_date)->format('d/m/Y'),
            'count' => 1,
            'generatedAt' => Carbon::now('Africa/Tunis')->format('d/m/Y H:i'),
            'fiches' => [$fiche],
        ])->setPaper('a4')->output();
    }

    /** Nom de fichier lisible pour le destinataire, qui empile toutes les fiches. */
    public static function filenameFor(WhatsappSendLog $job): string
    {
        $guest = $job->guest;
        $who = $guest
            ? trim(mb_strtoupper((string) $guest->last_name).' '.(string) $guest->first_name)
            : 'fiche';

        // Le destinataire trie à la main dans un fil unique : le nom du fichier
        // est souvent la seule chose qu'il voit avant d'ouvrir.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $who);

        return 'fiche-police-'.trim((string) $slug, '-').'.pdf';
    }

    /**
     * Photo de la pièce, en data URI. Même résolution de scan que le relais
     * WhatsApp et que l'export par email (DocumentScan::forFiche) : les trois
     * chemins joignent nécessairement la même pièce.
     *
     * Best-effort : une photo illisible ne doit pas empêcher la fiche de partir.
     */
    private static function photoDataUri(CheckIn $checkIn, Guest $guest): ?string
    {
        try {
            $scan = DocumentScan::forFiche($checkIn, $guest);
            if (!$scan) {
                return null;
            }

            $binary = $scan->imageBytes();
            if ($binary === null) {
                $disk = config('filesystems.passport_scan_disk', 'local');
                if (!$scan->file_path || !Storage::disk($disk)->exists($scan->file_path)) {
                    return null;
                }
                $binary = Storage::disk($disk)->get($scan->file_path);
            }

            $image = ImageManager::gd()->read($binary);
            $image->scaleDown(1100, 1100);

            return 'data:image/jpeg;base64,'.base64_encode((string) $image->toJpeg(70));
        } catch (\Throwable $e) {
            Log::warning('[delivery] photo non embarquée dans la fiche PDF du voyageur '.$guest->id.' : '.$e->getMessage());

            return null;
        }
    }
}
