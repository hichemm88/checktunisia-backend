<?php

namespace App\Services\Notifications;

use App\Mail\SystemMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alertes email vers les administrateurs de la plateforme (platform_admin) :
 * nouvelle inscription, paiement déclaré, etc. TOUJOURS non bloquant — un échec
 * d'email ne doit jamais casser l'action métier (inscription, déclaration).
 *
 * Les destinataires sont les comptes platform_admin (leurs emails), pour que le
 * routage suive naturellement le(s) compte(s) admin réel(s).
 */
class AdminNotifier
{
    public static function notify(string $subject, string $bodyHtml): void
    {
        try {
            $emails = User::whereHas('roles', fn ($q) => $q->where('name', 'platform_admin'))
                ->pluck('email')
                ->filter()
                ->unique()
                ->values();

            $html = '<div style="font-family:system-ui,Arial,sans-serif;max-width:600px;margin:0 auto;color:#222">'
                .$bodyHtml
                .'<p style="color:#999;font-size:12px;margin-top:24px">Alerte automatique Qayed — administration plateforme.</p></div>';

            foreach ($emails as $to) {
                Mail::to($to)->send(new SystemMail('[Qayed Admin] '.$subject, $html));
            }
        } catch (\Throwable $e) {
            Log::warning('[AdminNotifier] envoi échoué : '.$e->getMessage());
        }
    }

    /** Ligne clé/valeur pour le corps d'une alerte. */
    public static function row(string $label, ?string $value): string
    {
        return '<tr><td style="padding:4px 12px 4px 0;color:#666">'.e($label).'</td>'
            .'<td style="padding:4px 0;font-weight:600">'.e((string) ($value ?? '—')).'</td></tr>';
    }
}
