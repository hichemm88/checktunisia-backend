<?php

namespace App\Services\Delivery;

use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\FicheFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

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
        $fiche['photo'] = FicheScanImage::dataUri($checkIn, $guest);

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
}
