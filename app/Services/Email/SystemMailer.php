<?php

namespace App\Services\Email;

use App\Mail\SystemMail;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the 4 system emails (welcome, account_suspended, payment_received,
 * subscription_reminder) using an admin-editable template (falls back to a
 * built-in default — see EmailTemplate::DEFAULTS) wrapped in a shared visual
 * shell matching the original welcome email design.
 *
 * Body placeholders are substituted via str_replace (never compiled as code)
 * so an admin editing a template from the browser can never inject PHP/Blade.
 */
class SystemMailer
{
    /**
     * @param array<string,string> $vars Plain placeholders, e.g. ['name' => 'Dar Omi'].
     *   Two special composite placeholders are available to templates:
     *   {{credentials_box}} and {{cta_button}} — pass them pre-built via $vars
     *   only when relevant (see helpers below); otherwise they render empty.
     */
    public static function send(string $key, ?string $to, array $vars = []): bool
    {
        if (!$to) {
            \Log::warning("SystemMailer: no recipient for template [{$key}], skipped.");
            return false;
        }

        try {
            $template = EmailTemplate::getOrDefault($key);
            $subject  = self::substitute($template['subject'], $vars);
            $bodyHtml = self::substitute($template['body_html'], $vars);
            $html     = self::wrapShell($bodyHtml);

            Mail::to($to)->send(new SystemMail($subject, $html));
            return true;
        } catch (\Throwable $e) {
            \Log::warning("SystemMailer: send failed for template [{$key}] to [{$to}]: ".$e->getMessage());
            return false;
        }
    }

    /** Renders a template with sample data — used by the admin preview endpoint. */
    public static function preview(string $key): array
    {
        $sample = match ($key) {
            'welcome' => [
                'first_name' => 'Nour', 'last_name' => 'Kaouach', 'hotel_name' => 'Dar Omi',
                'role_label' => 'Réceptionniste',
                'credentials_box' => self::credentialsBox('nour@example.tn', 'xK9mPq2vRtLz'),
                'cta_button' => self::ctaButton(self::loginUrl()),
            ],
            'account_suspended' => ['name' => 'Kasbahost SARL', 'reason' => "Facture impayée depuis 30 jours"],
            'payment_received' => [
                'name' => 'Kasbahost SARL', 'plan_name' => 'Medium', 'expires_at' => '31/12/2026',
                'credentials_box' => self::amountBox('250.000 TND', 'INV-2026-0042'),
            ],
            'subscription_reminder' => ['name' => 'Kasbahost SARL', 'plan_name' => 'Medium', 'expires_at' => '15/07/2026', 'days_remaining' => '7'],
            default => [],
        };

        $template = EmailTemplate::getOrDefault($key);
        return [
            'subject' => self::substitute($template['subject'], $sample),
            'html'    => self::wrapShell(self::substitute($template['body_html'], $sample)),
        ];
    }

    private static function substitute(string $text, array $vars): string
    {
        $composite = ['credentials_box', 'cta_button'];
        foreach ($vars as $key => $value) {
            $safe = in_array($key, $composite, true) ? $value : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $text = str_replace('{{'.$key.'}}', $safe, $text);
        }
        // Any placeholder left unresolved (composite box not passed for this send) → drop silently.
        return preg_replace('/\{\{\w+\}\}/', '', $text);
    }

    public static function loginUrl(): string
    {
        return rtrim(env('FRONTEND_URL', 'https://checktunisia.vercel.app'), '/').'/login';
    }

    public static function ctaButton(string $url, string $label = 'Se connecter'): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;"><tr><td align="center">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" style="display:inline-block;background-color:#1B3A5F;color:#ffffff !important;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:600;letter-spacing:0.2px;font-family:\'Segoe UI\', Arial, sans-serif;">'
            .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').' &rarr;</a></td></tr></table>';
    }

    public static function credentialsBox(string $email, string $tempPassword): string
    {
        return self::twoRowBox('Email', $email, 'Mot de passe temporaire', $tempPassword);
    }

    public static function amountBox(string $amount, string $invoiceNumber): string
    {
        return self::twoRowBox('Montant', $amount, 'N° facture', $invoiceNumber);
    }

    private static function twoRowBox(string $label1, string $value1, string $label2, string $value2): string
    {
        $cell = fn(string $label, string $value, bool $border) =>
            '<td style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #E2E8F0;' : '').'font-size:12px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.05em;">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</td>'
            .'<td align="right" style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #E2E8F0;' : '').'font-size:14px;font-weight:600;color:#1F2937;font-family:monospace;">'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'</td>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;margin:24px 0;">'
            .'<tr>'.$cell($label1, $value1, true).'</tr>'
            .'<tr>'.$cell($label2, $value2, false).'</tr>'
            .'</table>';
    }

    private static function wrapShell(string $bodyHtml): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>Qayed</title>
  <style>
    body { margin: 0; padding: 0; background: #F3F4F6; font-family: 'Segoe UI', Arial, sans-serif; color: #1F2937; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
    .header { background: #1B3A5F; padding: 32px 40px; text-align: center; }
    .header h1 { margin: 0; font-size: 22px; color: #ffffff; letter-spacing: -0.3px; }
    .header p  { margin: 6px 0 0; font-size: 13px; color: #9CB3CC; }
    .body { padding: 32px 40px; }
    .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
    .warning { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400E; margin-top: 24px; }
    .footer { padding: 20px 40px; text-align: center; font-size: 12px; color: #9CA3AF; border-top: 1px solid #F3F4F6; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>Qayed</h1>
      <p>Plateforme de gestion hôtelière</p>
    </div>
    <div class="body">
      {$bodyHtml}
    </div>
    <div class="footer">
      © {$year} Qayed — Tous droits réservés<br>
      Cet email a été envoyé automatiquement, merci de ne pas y répondre.
    </div>
  </div>
</body>
</html>
HTML;
    }
}
