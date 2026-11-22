<?php

namespace App\Services\Subscription;

use App\Models\EstablishmentPartnerLink;
use App\Models\Hotel;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Garde-fous des comptes dont l'accès API partenaire est EN SERVICE.
 *
 * Un tel compte ne déclare plus ses voyageurs dans Qayed : c'est le PMS du
 * partenaire qui crée les fiches par l'API. Le couper automatiquement pour
 * une facture en retard ne se voit pas côté hébergeur (il ne se connecte
 * pas à Qayed), mais toutes ses déclarations réglementaires cessent d'un coup
 * — FicheSessionService refuse dès que l'abonnement n'est plus actif.
 * D'où deux règles, posées ici et nulle part ailleurs :
 *
 *  1. une échéance proche est signalée aux administrateurs de la plateforme
 *     15 jours avant, y compris pour un compte interne (billing_mode=internal)
 *     que les relances commerciales ignorent ;
 *  2. aucune suspension automatique pour impayé : elle est retenue et
 *     remplacée par une alerte admin, une seule fois par motif.
 *
 * « Accès API en service » = le pack (ou l'override client) accorde
 * `api_access` à CET abonnement ET au moins un établissement du compte a une
 * liaison active avec un partenaire actif. L'option seule ne suffit pas : un
 * pack qui l'inclut d'office rendrait sinon tout un palier insuspendable sans
 * qu'aucune intégration ne tourne.
 */
class ApiAccessGuard
{
    /** Délai d'alerte avant l'échéance d'un compte à accès API en service. */
    public const EXPIRY_ALERT_DAYS = 15;

    public static function isActive(Subscription $sub): bool
    {
        $sub->loadMissing('plan');

        if (! (PlanEntitlements::resolveForSubscription($sub)['api_access'] ?? false)) {
            return false;
        }

        return self::activeLinks($sub)->exists();
    }

    /** Liaisons actives (partenaire actif) des établissements couverts par l'abonnement. */
    public static function activeLinks(Subscription $sub): Builder
    {
        $orgId = $sub->organization_id ?? $sub->hotel?->organization_id;

        $hotelIds = $orgId
            ? Hotel::where('organization_id', $orgId)->select('id')
            : [$sub->hotel_id];

        return EstablishmentPartnerLink::query()
            ->active()
            ->whereIn('hotel_id', $hotelIds)
            ->whereHas('partner', fn ($q) => $q->where('status', 'active'));
    }

    /**
     * Point de passage UNIQUE de toute suspension/expiration automatique pour
     * impayé. Renvoie true si la coupure doit être retenue (accès API en
     * service) ; l'alerte admin correspondante part alors une seule fois par
     * $alertKey (ex. une facture, une échéance donnée).
     */
    public static function withholdsAutomaticSuspension(Subscription $sub, string $trigger, string $alertKey): bool
    {
        if (! self::isActive($sub)) {
            return false;
        }

        $firstTime = self::markOnce($sub, 'api_suspension_withheld', $alertKey);

        if ($firstTime) {
            AuditLogger::log('subscription.auto_suspension_withheld', $sub, newValues: [
                'trigger' => $trigger,
                'key' => $alertKey,
            ], hotelId: $sub->hotel_id);

            $reason = match ($trigger) {
                'invoice_overdue' => 'Facture impayée au-delà du délai de recouvrement : la suspension automatique aurait dû intervenir.',
                default => "Échéance dépassée et délai de grâce écoulé : l'expiration automatique aurait dû intervenir.",
            };

            AdminNotifier::notify(
                'Suspension automatique retenue — accès API en service',
                '<p><strong>Aucune coupure n\'a été appliquée</strong> : ce compte a un accès API partenaire en service '
                .'(ses fiches de police sont créées par le logiciel du partenaire). '
                .'Une action manuelle est requise : régulariser, prolonger ou suspendre depuis le back-office.</p>'
                .'<p>'.e($reason).'</p>'
                .self::summaryTable($sub, ['Motif' => $alertKey]),
            );
        }

        return true;
    }

    /**
     * Alerte admin J-15 avant l'échéance des abonnements à accès API en
     * service, comptes internes compris. Idempotente : une seule alerte par
     * abonnement et par date d'échéance (une prolongation produit une
     * nouvelle échéance, donc une nouvelle alerte le moment venu).
     *
     * @return int nombre d'alertes envoyées
     */
    public static function alertUpcomingExpiries(): int
    {
        $sent = 0;

        Subscription::with(['plan', 'organization', 'hotel'])
            ->whereIn('status', ['active', 'trial'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays(self::EXPIRY_ALERT_DAYS)->endOfDay())
            ->orderBy('expires_at')
            ->get()
            ->each(function (Subscription $sub) use (&$sent) {
                if (! self::isActive($sub)) {
                    return;
                }

                $key = $sub->expires_at->toDateString();
                if (! self::markOnce($sub, 'api_expiry_alerts', $key)) {
                    return;
                }

                $days = max(0, (int) now()->startOfDay()->diffInDays($sub->expires_at->copy()->startOfDay()));

                AuditLogger::log('subscription.api_expiry_alert_sent', $sub, newValues: [
                    'expires_at' => $key,
                    'days_remaining' => $days,
                ], hotelId: $sub->hotel_id);

                AdminNotifier::notify(
                    "Échéance dans {$days} jour(s) — compte à accès API en service",
                    '<p>L\'abonnement ci-dessous arrive à échéance alors que son accès API partenaire est en service. '
                    .'S\'il n\'est pas renouvelé, les fiches créées par le partenaire seront refusées dès l\'expiration.</p>'
                    .self::summaryTable($sub, ['Jours restants' => (string) $days]),
                );

                $sent++;
            });

        return $sent;
    }

    /**
     * Marque $key dans subscription.metadata[$bucket] sous verrou ; renvoie
     * true seulement la première fois (donc une seule alerte par clé, même
     * si deux exécutions se chevauchent).
     */
    private static function markOnce(Subscription $sub, string $bucket, string $key): bool
    {
        return DB::transaction(function () use ($sub, $bucket, $key) {
            $locked = Subscription::whereKey($sub->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $meta = $locked->metadata ?? [];
            $done = (array) ($meta[$bucket] ?? []);

            if (in_array($key, $done, true)) {
                return false;
            }

            $meta[$bucket] = array_values(array_merge($done, [$key]));
            $locked->update(['metadata' => $meta]);
            $sub->setAttribute('metadata', $meta);

            return true;
        });
    }

    /** Récapitulatif sans donnée personnelle : compte, pack, échéance, intégrations. */
    private static function summaryTable(Subscription $sub, array $extra = []): string
    {
        $org = $sub->organization ?? $sub->hotel?->organization;
        $partners = self::activeLinks($sub)->with('partner:id,name')->get()
            ->pluck('partner.name')->filter()->unique()->implode(', ');

        $rows = [
            'Compte' => $org?->name ?? $sub->hotel?->name ?? '—',
            'Facturation' => $sub->isInternal() ? 'Compte interne' : 'Commercial',
            'Pack' => $sub->plan?->name ?? '—',
            'Statut' => $sub->status,
            'Échéance' => $sub->expires_at?->timezone('Africa/Tunis')->format('d/m/Y') ?? '—',
            'Établissements liés' => (string) self::activeLinks($sub)->distinct()->count('hotel_id'),
            'Partenaires' => $partners !== '' ? $partners : '—',
            'Abonnement' => $sub->id,
        ] + $extra;

        $html = '<table style="border-collapse:collapse;margin-top:12px">';
        foreach ($rows as $label => $value) {
            $html .= AdminNotifier::row($label, $value);
        }

        return $html.'</table>';
    }
}
