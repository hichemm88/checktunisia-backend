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
    public static function send(string $key, ?string $to, array $vars = [], ?string $locale = null): bool
    {
        if (!$to) {
            \Log::warning("SystemMailer: no recipient for template [{$key}], skipped.");
            return false;
        }

        try {
            $locale   = EmailTemplate::normalizeLocale($locale);
            $template = EmailTemplate::getOrDefault($key, $locale);
            $subject  = self::substitute($template['subject'], $vars);
            $bodyHtml = self::substitute($template['body_html'], $vars);
            $html     = self::wrapShell($bodyHtml, $locale);

            Mail::to($to)->send(new SystemMail($subject, $html));
            return true;
        } catch (\Throwable $e) {
            \Log::warning("SystemMailer: send failed for template [{$key}] to [{$to}]: ".$e->getMessage());
            return false;
        }
    }

    /** Libelles localises des boutons/boites reutilisables (CTA, boite montant). */
    public const LABELS = [
        'fr' => [
            'login' => 'Se connecter', 'set_password' => 'Définir mon mot de passe',
            'reset_password' => 'Réinitialiser mon mot de passe', 'view_invoice' => 'Voir la facture',
            'pay_invoice' => 'Régler la facture', 'view_subscriptions' => 'Voir les abonnements',
            'amount' => 'Montant', 'invoice_number' => 'N° facture',
            'role_admin' => 'Administrateur', 'role_receptionist' => 'Réceptionniste',
        ],
        'en' => [
            'login' => 'Sign in', 'set_password' => 'Set my password',
            'reset_password' => 'Reset my password', 'view_invoice' => 'View invoice',
            'pay_invoice' => 'Pay invoice', 'view_subscriptions' => 'View plans',
            'amount' => 'Amount', 'invoice_number' => 'Invoice no.',
            'role_admin' => 'Administrator', 'role_receptionist' => 'Receptionist',
        ],
        'ar' => [
            'login' => 'تسجيل الدخول', 'set_password' => 'تعيين كلمة المرور',
            'reset_password' => 'إعادة تعيين كلمة المرور', 'view_invoice' => 'عرض الفاتورة',
            'pay_invoice' => 'دفع الفاتورة', 'view_subscriptions' => 'عرض الاشتراكات',
            'amount' => 'المبلغ', 'invoice_number' => 'رقم الفاتورة',
            'role_admin' => 'مدير', 'role_receptionist' => 'موظف استقبال',
        ],
    ];

    /** Libelle localise (repli francais). */
    public static function label(string $key, ?string $locale = null): string
    {
        $locale = EmailTemplate::normalizeLocale($locale);

        return self::LABELS[$locale][$key] ?? self::LABELS[EmailTemplate::DEFAULT_LOCALE][$key] ?? $key;
    }

    /** Renders a template with sample data — used by the admin preview endpoint. */
    public static function preview(string $key, ?string $locale = null): array
    {
        $locale = EmailTemplate::normalizeLocale($locale);

        $sample = match ($key) {
            'welcome' => [
                'first_name' => 'Nour', 'last_name' => 'Kaouach', 'hotel_name' => 'Dar Omi',
                'role_label' => 'Réceptionniste',
                'cta_button' => self::ctaButton(self::frontendUrl('/set-password?email=nour%40example.tn&token=exemple'), self::label('set_password', $locale)),
            ],
            'password_reset' => [
                'first_name' => 'Nour', 'last_name' => 'Kaouach',
                'cta_button' => self::ctaButton(self::frontendUrl('/set-password?email=nour%40example.tn&token=exemple'), self::label('reset_password', $locale)),
            ],
            'account_suspended' => ['name' => 'Kasbahost SARL', 'reason' => "Facture impayée depuis 30 jours"],
            'payment_received' => [
                'name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'expires_at' => '31/12/2026',
                'credentials_box' => self::amountBox(\App\Support\Money::tnd(119), 'INV-2026-0042', $locale),
            ],
            'subscription_reminder' => ['name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'expires_at' => '15/07/2026', 'days_remaining' => '7'],
            'trial_ending' => [
                'name' => 'Riad Al Warda', 'trial_message' => 'Votre essai gratuit se termine dans 2 jour(s), le 15/07/2026.',
                'cta_button' => self::ctaButton(self::frontendUrl('/hotel/settings'), self::label('view_subscriptions', $locale)),
            ],
            'invoice_overdue' => [
                'name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'invoice_number' => 'INV-2026-0042', 'days_late' => '7',
                'credentials_box' => self::amountBox(\App\Support\Money::tnd(119), 'INV-2026-0042', $locale),
                'cta_button' => self::ctaButton(self::frontendUrl('/hotel/settings'), self::label('pay_invoice', $locale)),
            ],
            'invoice_available' => [
                'name' => 'Kasbahost SARL', 'plan_name' => 'Pro', 'invoice_number' => 'INV-2026-0042',
                'credentials_box' => self::amountBox(\App\Support\Money::tnd(119), 'INV-2026-0042', $locale),
                'cta_button' => self::ctaButton(self::frontendUrl('/hotel/settings'), self::label('view_invoice', $locale)),
            ],
            default => [],
        };

        $template = EmailTemplate::getOrDefault($key, $locale);
        return [
            'subject' => self::substitute($template['subject'], $sample),
            'html'    => self::wrapShell(self::substitute($template['body_html'], $sample), $locale),
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

    /**
     * Sends the branded "forgot password" email. Reuses the same reset-token
     * mechanism and /set-password SPA page as the invite flow, so a forgotten
     * password and a first password are validated identically server-side.
     */
    public static function sendPasswordReset(\App\Models\User $user): bool
    {
        $link   = self::issueSetPasswordLink($user);
        $locale = $user->locale ?? EmailTemplate::DEFAULT_LOCALE;

        return self::send('password_reset', $user->email, [
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'cta_button' => self::ctaButton($link, self::label('reset_password', $locale)),
        ], $locale);
    }

    public static function ctaButton(string $url, string $label = 'Se connecter'): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 8px;"><tr><td align="center">'
            .'<a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" style="display:inline-block;background-color:#5346A8;color:#ffffff !important;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:14px;font-weight:600;letter-spacing:0.2px;font-family:\'IBM Plex Sans\',-apple-system,\'Segoe UI\',Arial,sans-serif;">'
            .htmlspecialchars($label, ENT_QUOTES, 'UTF-8').' &rarr;</a></td></tr></table>';
    }

    public static function amountBox(string $amount, string $invoiceNumber, ?string $locale = null): string
    {
        return self::twoRowBox(self::label('amount', $locale), $amount, self::label('invoice_number', $locale), $invoiceNumber);
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

    /** Textes de la coquille (en-tete + pied) par langue. */
    private const SHELL = [
        'fr' => [
            'tagline' => "Plateforme d'enregistrement des voyageurs",
            'rights'  => 'Tous droits réservés',
            'auto'    => 'Cet email a été envoyé automatiquement, merci de ne pas y répondre.',
        ],
        'en' => [
            'tagline' => 'Traveler registration platform',
            'rights'  => 'All rights reserved',
            'auto'    => 'This email was sent automatically, please do not reply.',
        ],
        'ar' => [
            'tagline' => 'منصة تسجيل المسافرين',
            'rights'  => 'جميع الحقوق محفوظة',
            'auto'    => 'أُرسلت هذه الرسالة تلقائيًا، يُرجى عدم الرد عليها.',
        ],
    ];

    private static function wrapShell(string $bodyHtml, ?string $locale = null): string
    {
        $locale = EmailTemplate::normalizeLocale($locale);
        $shell  = self::SHELL[$locale] ?? self::SHELL[EmailTemplate::DEFAULT_LOCALE];
        $year   = date('Y');
        $sceau  = self::sceau();
        $dir    = $locale === 'ar' ? 'rtl' : 'ltr';
        // Bordure d'accent du cote du debut de ligne (gauche en LTR, droite en RTL).
        $accent = $locale === 'ar' ? 'right' : 'left';
        $tagline = $shell['tagline'];
        $footer  = '© '.$year.' Qayed — '.$shell['rights'].'<br>'.$shell['auto'];
        return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}" dir="{$dir}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <meta name="supported-color-schemes" content="light" />
  <title>Qayed</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@900&family=IBM+Plex+Sans:wght@400;600&family=IBM+Plex+Sans+Arabic:wght@400;600;700&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
  <style>
    body { margin: 0; padding: 0; background: #F6F5F1; font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #10222E; }
    .wrapper { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 18px; overflow: hidden; border: 1px solid #DDD9CF; }
    .header { background: #10222E; background-image: repeating-linear-gradient(to bottom, transparent 0, transparent 15px, rgba(246,245,241,0.07) 15px, rgba(246,245,241,0.07) 16px); padding: 32px 40px; text-align: center; }
    .header p  { margin: 14px 0 0; font-size: 13px; color: #8B7FE0; }
    .body { padding: 32px 40px; }
    .body p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
    .warning { background: #FBF0D7; border-{$accent}: 3px solid #E3A008; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #8A6206; margin-top: 24px; }
    .danger { background: #F6F5F1; border-{$accent}: 3px solid #DC2626; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #991B1B; margin-top: 8px; }
    .footer { padding: 20px 40px; text-align: center; font-size: 12px; color: #8A94A0; border-top: 1px solid #DDD9CF; }
  </style>
</head>
<body dir="{$dir}">
  <div class="wrapper">
    <div class="header">
      {$sceau}
      <p>{$tagline}</p>
    </div>
    <div class="body">
      {$bodyHtml}
    </div>
    <div class="footer">
      {$footer}
    </div>
  </div>
</body>
</html>
HTML;
    }
}
