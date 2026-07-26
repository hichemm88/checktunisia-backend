<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Email d'export des fiches de police (PDF en pièce jointe). */
class PoliceFichesExport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $hotelName,
        public readonly string $rangeLabel,
        public readonly int $count,
        public readonly string $pdfData,
        public readonly string $filename,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Qayed — Fiches de police : {$this->hotelName} ({$this->rangeLabel})");
    }

    public function content(): Content
    {
        $body = '<p>Bonjour,</p>'
            .'<p>Veuillez trouver ci-joint le PDF des <strong>'.$this->count.' fiche(s) de police</strong> de <strong>'
            .e($this->hotelName).'</strong> pour la période <strong>'.e($this->rangeLabel).'</strong>.</p>';

        return new Content(htmlString: \App\Services\Email\EmailBrand::wrap('Export des fiches de police', $body));
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfData, $this->filename)
                ->withMime('application/pdf'),
        ];
    }
}
