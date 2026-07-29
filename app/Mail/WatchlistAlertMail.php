<?php

namespace App\Mail;

use App\Services\Email\EmailBrand;
use App\Services\Email\SystemMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerte de securite envoyee au gerant de l'etablissement lorsqu'un voyageur
 * figurant sur la liste de surveillance effectue un enregistrement.
 *
 * Le niveau d'information est volontairement aligne sur l'ecran d'alerte de
 * l'application : AUCUN nom de voyageur n'est divulgue. L'email se limite a
 * l'etablissement, la reference du check-in, le niveau et l'horodatage, avec
 * un lien vers l'application ou l'alerte complete est consultable en securite.
 *
 * Marque Qayed via EmailBrand::wrap() (meme coquille que l'export des fiches).
 */
class WatchlistAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $hotelName,
        public readonly string $checkInReference,
        public readonly string $severityLabel,
        public readonly string $occurredAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Qayed — Alerte de sécurité : {$this->hotelName}");
    }

    public function content(): Content
    {
        $url = SystemMailer::frontendUrl('/hotel/security');
        $cta = SystemMailer::ctaButton($url, 'Voir les alertes');

        $rows = $this->row('Établissement', $this->hotelName)
            .$this->row('Référence du check-in', $this->checkInReference)
            .$this->row("Niveau d'alerte", $this->severityLabel)
            .$this->row('Horodatage', $this->occurredAt);

        $body = '<p>Un voyageur figurant sur la liste de surveillance vient d\'effectuer un enregistrement dans votre établissement.</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0;border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate;overflow:hidden">'
            .$rows
            .'</table>'
            .$cta
            .'<div style="margin-top:20px;padding:12px 16px;background:#F6F5F1;border-left:3px solid #DC2626;border-radius:8px;font-size:13px;color:#991B1B">'
            .'Ne partagez pas cette information. Contactez les autorités compétentes.'
            .'</div>';

        return new Content(htmlString: EmailBrand::wrap('Alerte de sécurité', $body));
    }

    private function row(string $label, string $value): string
    {
        return '<tr>'
            .'<td style="padding:10px 16px;font-size:12px;font-weight:600;color:#6b7280;background:#fafafa;border-bottom:1px solid #eee;white-space:nowrap;vertical-align:top">'.e($label).'</td>'
            .'<td style="padding:10px 16px;font-size:13px;color:#111827;border-bottom:1px solid #eee">'.e($value).'</td>'
            .'</tr>';
    }
}
