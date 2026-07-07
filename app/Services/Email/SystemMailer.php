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
                'cta_button' => self::ctaButton(self::frontendUrl('/set-password?email=nour%40example.tn&token=exemple'), 'Définir mon mot de passe'),
            ],
            'account_suspended' => ['name' => 'Kasbahost SARL', 'reason' => "Facture impayée depuis 30 jours"],
            'payment_received' => [
                'name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'expires_at' => '31/12/2026',
                'credentials_box' => self::amountBox(\App\Support\Money::tnd(119), 'INV-2026-0042'),
            ],
            'subscription_reminder' => ['name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'expires_at' => '15/07/2026', 'days_remaining' => '7'],
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

    public static function frontendUrl(string $path = ''): string
    {
        return rtrim(env('FRONTEND_URL', 'https://checktunisia.vercel.app'), '/').$path;
    }

    public static function loginUrl(): string
    {
        return self::frontendUrl('/login');
    }

    /**
     * Issues a Laravel password-reset token for a newly-invited (or
     * re-invited) user and returns the link to embed in the welcome email —
     * used instead of emailing a temporary password in plaintext. Reuses
     * the same token/table AuthController::resetPassword() already
     * validates, so "set my first password" and "reset a forgotten one"
     * are the same operation on the backend.
     */
    public static function issueSetPasswordLink(\App\Models\User $user): string
    {
        $token = \Illuminate\Support\Facades\Password::createToken($user);
        return self::frontendUrl('/set-password?email='.urlencode($user->email).'&token='.$token);
    }

    public static function ctaButton(string $url, string $label = 'Se connecter'): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;"><tr><td align="center">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" style="display:inline-block;background-color:#5346A8;color:#ffffff !important;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:600;letter-spacing:0.2px;font-family:\'IBM Plex Sans\',-apple-system,\'Segoe UI\',Arial,sans-serif;">'
            .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').' &rarr;</a></td></tr></table>';
    }

    public static function amountBox(string $amount, string $invoiceNumber): string
    {
        return self::twoRowBox('Montant', $amount, 'N° facture', $invoiceNumber);
    }

    private static function twoRowBox(string $label1, string $value1, string $label2, string $value2): string
    {
        $cell = fn(string $label, string $value, bool $border) =>
            '<td style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #DDD9CF;' : '').'font-size:12px;font-weight:600;color:#8A94A0;text-transform:uppercase;letter-spacing:0.05em;font-family:\'IBM Plex Sans\',-apple-system,\'Segoe UI\',Arial,sans-serif;">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</td>'
            .'<td align="right" style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #DDD9CF;' : '').'font-size:14px;font-weight:600;color:#10222E;font-family:\'IBM Plex Mono\',monospace;">'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'</td>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F6F5F1;border:1px solid #DDD9CF;border-radius:14px;margin:24px 0;">'
            .'<tr>'.$cell($label1, $value1, true).'</tr>'
            .'<tr>'.$cell($label2, $value2, false).'</tr>'
            .'</table>';
    }

    /** Sceau قيد + wordmark, rendered as an email-safe table (the −6° tilt degrades gracefully where `transform` isn't supported). */
    private static function sceau(): string
    {
        return <<<HTML
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
  <tr>
    <td style="width:40px;height:40px;border:2.5px solid #8B7FE0;border-radius:9px;transform:rotate(-6deg);-webkit-transform:rotate(-6deg);-ms-transform:rotate(-6deg);text-align:center;vertical-align:middle;font-family:'IBM Plex Sans Arabic',Arial,sans-serif;font-weight:700;font-size:15px;color:#8B7FE0;line-height:40px;">قيد</td>
    <td style="width:10px;line-height:1px;font-size:1px;">&nbsp;</td>
    <td style="font-family:Archivo,'IBM Plex Sans',Arial,sans-serif;font-weight:900;font-size:20px;letter-spacing:-0.02em;color:#ffffff;vertical-align:middle;">QAYED</td>
  </tr>
</table>
HTML;
    }

    private static function wrapShell(string $bodyHtml): string
    {
        $year  = date('Y');
        $sceau = self::sceau();
        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>Qayed</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@900&family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Sans+Arabic:wght@700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
  <style>
    body { margin: 0; padding: 0; background: #F6F5F1; font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #10222E; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 18px; overflow: hidden; border: 1px solid #DDD9CF; }
    .header { background: #10222E; background-image: repeating-linear-gradient(to bottom, transparent 0, transparent 15px, rgba(246,245,241,0.07) 15px, rgba(246,245,241,0.07) 16px); padding: 32px 40px; text-align: center; }
    .header p  { margin: 14px 0 0; font-size: 13px; color: #8B7FE0; }
    .body { padding: 32px 40px; }
    .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
    .warning { background: #FBF0D7; border-left: 3px solid #E3A008; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #8A6206; margin-top: 24px; }
    .danger { background: #F6F5F1; border-left: 3px solid #DC2626; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #991B1B; margin-top: 8px; }
    .footer { padding: 20px 40px; text-align: center; font-size: 12px; color: #8A94A0; border-top: 1px solid #DDD9CF; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      {$sceau}
      <p>Plateforme d'enregistrement des voyageurs</p>
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
