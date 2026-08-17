<?php

namespace App\Mail;

use App\Services\Email\EmailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Récapitulatif quotidien des arrivées, tous établissements, PDF en pièce jointe.
 *
 * L'objet porte la date et le nombre de fiches : le destinataire retransmet à
 * la main aux autorités, souvent depuis un téléphone, et doit pouvoir vérifier
 * d'un coup d'œil dans sa liste de messages qu'aucun jour ne manque — y compris
 * les jours à zéro arrivée, qui sont envoyés eux aussi. Un silence serait
 * indiscernable d'une panne.
 */
class PoliceDailyDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $dateLabel,
        public readonly int $count,
        public readonly int $hotelCount,
        public readonly int $withoutPhoto,
        public readonly string $pdfData,
        public readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Qayed — Fiches de police : {$this->dateLabel} ({$this->count} fiche(s))",
        );
    }

    public function content(): Content
    {
        if ($this->count === 0) {
            $body = '<p>Bonjour,</p>'
                .'<p><strong>Aucune arrivée</strong> enregistrée sur la période '.e($this->dateLabel).'.</p>'
                .'<p>Ce message est envoyé même les jours sans arrivée, pour que son absence '
                .'signale une panne et non une journée creuse.</p>';

            return new Content(htmlString: EmailBrand::wrap('Fiches de police du jour', $body));
        }

        $body = '<p>Bonjour,</p>'
            .'<p>Ci-joint le PDF des <strong>'.$this->count.' fiche(s) de police</strong> '
            .'correspondant aux arrivées enregistrées sur <strong>'.e($this->dateLabel).'</strong>, '
            .'réparties sur <strong>'.$this->hotelCount.' établissement(s)</strong>. '
            .'Les pièces d\'identité sont incluses dans le document.</p>';

        if ($this->withoutPhoto > 0) {
            // Signalé dans l'email et pas seulement dans le PDF : c'est la seule
            // information qui peut demander une action avant retransmission.
            $body .= '<p><strong>'.$this->withoutPhoto.' fiche(s) sans pièce d\'identité</strong> — '
                .'elles sont signalées comme telles dans le document.</p>';
        }

        return new Content(htmlString: EmailBrand::wrap('Fiches de police du jour', $body));
    }

    public function attachments(): array
    {
        if ($this->count === 0) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $this->pdfData, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
