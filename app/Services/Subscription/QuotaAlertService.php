<?php

namespace App\Services\Subscription;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\QuotaAlert;
use App\Services\Email\SystemMailer;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Alertes quota de check-ins (grille V2) — comptes sur plan avec quota
 * uniquement (Essentiel, Pro… y compris legacy Essentiel 100). Les comptes
 * illimités (Hôtel, anciens Pro/Multi-sites grandfathered) ne reçoivent
 * JAMAIS d'alerte.
 *
 * Deux seuils, chacun envoyé UNE seule fois par mois calendaire (anti-spam
 * garanti par l'unicité organisation+mois+seuil de quota_alerts) :
 *  - 80 %  : bandeau in-app informatif + email « Vous avez utilisé X de vos Y »
 *  - 100 % : bandeau in-app persistant (ambre) + email quota atteint — les
 *            check-ins CONTINUENT de fonctionner ; la mention de facturation
 *            du dépassement n'apparaît que pour les comptes réellement
 *            facturables (jamais pour les comptes legacy).
 *
 * Appelé après chaque création de check-in (jamais bloquant — enveloppé
 * try/catch, déclenché après la réponse HTTP).
 */
class QuotaAlertService
{
    /** Évaluation tolérante aux pannes : ne casse jamais l'enregistrement d'un voyageur. */
    public static function evaluateSafely(Organization $org): void
    {
        try {
            self::evaluate($org);
        } catch (\Throwable $e) {
            Log::warning('[quota] alert evaluation failed', ['organization_id' => $org->id, 'error' => $e->getMessage()]);
        }
    }

    public static function evaluate(Organization $org): void
    {
        $status = CheckinQuota::status($org);
        if ($status['unlimited'] || $status['quota'] < 1) {
            return;
        }

        if ($status['used'] >= $status['quota']) {
            self::sendOnce($org, QuotaAlert::THRESHOLD_REACHED, $status);
        } elseif ($status['used'] * 5 >= $status['quota'] * 4) { // ≥ 80 %
            self::sendOnce($org, QuotaAlert::THRESHOLD_WARN_80, $status);
        }
    }

    /** Envoie l'alerte du seuil si (et seulement si) elle n'est pas déjà partie ce mois-ci. */
    private static function sendOnce(Organization $org, string $threshold, array $status, ?Carbon $month = null): void
    {
        $sub   = $org->activeSubscription()->first();
        $alert = QuotaAlert::firstOrCreate(
            [
                'organization_id' => $org->id,
                'period'          => ($month ?? now())->copy()->startOfMonth()->toDateString(),
                'threshold'       => $threshold,
            ],
            [
                'subscription_id' => $sub?->id,
                'checkins_count'  => $status['used'],
                'quota'           => $status['quota'],
                'sent_at'         => now(),
            ],
        );

        if (!$alert->wasRecentlyCreated) {
            return;
        }

        $locale = EmailTemplate::normalizeLocale($org->locale);

        if ($threshold === QuotaAlert::THRESHOLD_REACHED) {
            self::sendReached($org, $status, $locale);
        } else {
            self::sendWarning($org, $status, $locale);
        }
    }

    private static function sendWarning(Organization $org, array $status, string $locale): void
    {
        [$title, $body] = match ($locale) {
            'en' => [
                'Check-in quota — 80% used',
                "You have used {$status['used']} of your {$status['quota']} check-ins this month.",
            ],
            'ar' => [
                'حصة تسجيلات الوصول — استُخدم 80%',
                "لقد استخدمتم {$status['used']} من أصل {$status['quota']} تسجيل وصول هذا الشهر.",
            ],
            default => [
                'Quota de check-ins — 80 % utilisés',
                "Vous avez utilisé {$status['used']} de vos {$status['quota']} check-ins ce mois-ci.",
            ],
        };

        app(PushNotificationService::class)->notifyQuota($org, PushNotificationService::TYPE_QUOTA_WARNING, $title, $body, $status);

        SystemMailer::send('quota_warning', $org->contact_email, [
            'name'       => $org->name,
            'used'       => (string) $status['used'],
            'quota'      => (string) $status['quota'],
            'percent'    => (string) ($status['percent'] ?? 0),
            'cta_button' => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('view_subscriptions', $locale)),
        ], $locale);
    }

    private static function sendReached(Organization $org, array $status, string $locale): void
    {
        [$title, $body] = match ($locale) {
            'en' => [
                'Monthly check-in quota reached',
                "Your {$status['quota']} monthly check-ins are used. Check-ins keep working normally.",
            ],
            'ar' => [
                'تم بلوغ حصة تسجيلات الوصول الشهرية',
                "استُخدمت حصتكم البالغة {$status['quota']} تسجيل وصول. يستمر تسجيل الوصول في العمل بشكل طبيعي.",
            ],
            default => [
                'Quota mensuel de check-ins atteint',
                "Vos {$status['quota']} check-ins mensuels sont utilisés. Les check-ins continuent de fonctionner normalement.",
            ],
        };

        app(PushNotificationService::class)->notifyQuota($org, PushNotificationService::TYPE_QUOTA_REACHED, $title, $body, $status);

        SystemMailer::send('quota_reached', $org->contact_email, [
            'name'           => $org->name,
            'quota'          => (string) $status['quota'],
            'overage_notice' => self::overageNotice($status, $locale),
            'cta_button'     => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('upgrade_plan', $locale)),
        ], $locale);
    }

    /**
     * Encadré « facturation du dépassement » — uniquement pour les comptes
     * réellement facturables. Un compte legacy atteint son quota sans qu'on
     * lui annonce une facturation qui n'aura pas lieu (grandfathering).
     */
    public static function overageNotice(array $status, string $locale): string
    {
        if (!$status['billable'] || $status['unit_price'] === null || $status['bundle_size'] === null) {
            return '';
        }

        $price  = rtrim(rtrim(number_format($status['unit_price'], 3, '.', ''), '0'), '.');
        $bundle = $status['bundle_size'];

        $text = match ($locale) {
            'en' => "Each additional bundle of {$bundle} check-ins started will be billed {$price} TND at the end of the month. Your check-ins are never blocked.",
            'ar' => "كل شريحة إضافية من {$bundle} تسجيل وصول يبدأ استخدامها ستُفوتر بـ {$price} دينار في نهاية الشهر. لن يتم حظر تسجيلات الوصول أبدًا.",
            default => "Chaque tranche supplémentaire de {$bundle} check-ins entamée sera facturée {$price} TND en fin de mois. Vos check-ins ne sont jamais bloqués.",
        };

        return '<div class="warning">'.e($text).'</div>';
    }

    /**
     * Encadré comparatif « ce que vous payez avec dépassements vs le plan
     * supérieur » de l'email d'upsell — mêmes styles email-safe que les
     * boîtes de SystemMailer.
     *
     * @param array{label:string,amount:string} $current
     * @param array{label:string,amount:string} $suggested
     */
    public static function comparisonBox(array $current, array $suggested, string $locale = 'fr'): string
    {
        $cell = fn (string $label, string $value, bool $border) =>
            '<td style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #DDD9CF;' : '').'font-size:13px;font-weight:600;color:#10222E;font-family:\'IBM Plex Sans\',-apple-system,\'Segoe UI\',Arial,sans-serif;">'.e($label).'</td>'
            .'<td align="right" style="padding:14px 24px;'.($border ? 'border-bottom:1px solid #DDD9CF;' : '').'font-size:14px;font-weight:600;color:#10222E;font-family:\'IBM Plex Mono\',monospace;white-space:nowrap;">'.e($value).'</td>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F6F5F1;border:1px solid #DDD9CF;border-radius:14px;margin:24px 0;">'
            .'<tr>'.$cell($current['label'], $current['amount'], true).'</tr>'
            .'<tr>'.$cell($suggested['label'], $suggested['amount'], false).'</tr>'
            .'</table>';
    }

    /**
     * Email d'upsell dédié (2 mois consécutifs de dépassement) avec les
     * chiffres réels du compte — envoyé une seule fois par mois (période de
     * clôture) et marque le compte « Candidat upsell » dans l'admin.
     * Appelé par la clôture mensuelle (quota:close-month).
     */
    public static function sendUpsellSuggestion(Organization $org, array $figures, Carbon $period): void
    {
        $alert = QuotaAlert::firstOrCreate(
            [
                'organization_id' => $org->id,
                'period'          => $period->copy()->startOfMonth()->toDateString(),
                'threshold'       => QuotaAlert::THRESHOLD_UPSELL,
            ],
            [
                'subscription_id' => $org->activeSubscription()->first()?->id,
                'checkins_count'  => $figures['used'] ?? 0,
                'quota'           => $figures['quota'] ?? 0,
                'sent_at'         => now(),
            ],
        );

        if (!$alert->wasRecentlyCreated) {
            return;
        }

        $org->forceFill(['upsell_flagged_at' => now()])->save();

        $locale = EmailTemplate::normalizeLocale($org->locale);
        SystemMailer::send('quota_upsell', $org->contact_email, [
            'name'           => $org->name,
            'suggested_plan' => $figures['suggested_plan'],
            'comparison_box' => self::comparisonBox($figures['current'], $figures['suggested'], $locale),
            'cta_button'     => SystemMailer::ctaButton(SystemMailer::frontendUrl('/hotel/settings'), SystemMailer::label('upgrade_plan', $locale)),
        ], $locale);
    }
}
