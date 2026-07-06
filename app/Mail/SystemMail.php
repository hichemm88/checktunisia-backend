<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Generic system email — subject/body are fully pre-rendered by SystemMailer. */
class SystemMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $renderedSubject,
        public readonly string $renderedHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedHtml);
    }
}
