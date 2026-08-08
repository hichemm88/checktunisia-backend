<?php

namespace App\Services\Subscription;

use App\Models\CheckIn;
use App\Models\CheckinUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Écriture du registre de consommation des check-ins.
 *
 * Ce qui déclenche une consommation : la FINALISATION d'une fiche
 * (brouillon → active), c'est-à-dire l'acte déclaratif effectivement rendu.
 * Rien d'autre. En particulier :
 *  - un brouillon ne consomme rien tant qu'il n'est pas finalisé ;
 *  - une création qui échoue laisse la transaction annulée, donc aucune ligne ;
 *  - modifier, faire le départ, revenir sur le départ ne consomment rien de
 *    plus (la ligne existe déjà, l'unicité SQL absorbe la tentative) ;
 *  - annuler horodate `cancelled_at` mais ne rend PAS la consommation.
 *
 * L'idempotence est garantie par la contrainte d'unicité sur `check_in_id`
 * et un INSERT ... ON CONFLICT DO NOTHING (`insertOrIgnore`) : deux requêtes
 * concurrentes n'en écrivent qu'une, sans exception ni verrou applicatif.
 *
 * Aucun appel ici ne doit pouvoir empêcher une déclaration : la déclaration
 * d'un voyageur est une obligation légale. `recordSafely` avale et journalise.
 */
class CheckinUsageRecorder
{
    /**
     * Pose la consommation d'une fiche finalisée. Sans effet si la fiche
     * n'est pas finalisée, si l'établissement n'a pas d'organisation, ou si
     * la consommation existe déjà.
     *
     * @return bool true si une ligne vient d'être créée (utile aux tests et
     *              au backfill ; jamais utilisé pour piloter du métier).
     */
    public static function record(CheckIn $checkIn, ?Carbon $consumedAt = null): bool
    {
        $hotel = $checkIn->relationLoaded('hotel') ? $checkIn->hotel : $checkIn->hotel()->first();
        $orgId = $hotel?->organization_id;
        if (!$orgId) {
            return false; // établissement hors organisation (données historiques) — rien à facturer
        }

        $consumedAt ??= $checkIn->completed_at ?? now();
        $now = now();

        // Abonnement au moment de la déclaration : conservé pour l'audit du
        // rattachement, jamais relu pour recalculer un montant (le calcul se
        // fait sur le plan effectif du mois, à la clôture).
        $subscriptionId = DB::table('subscriptions')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['active', 'trial'])
            ->orderByDesc('started_at')
            ->value('id');

        return DB::table('checkin_usage_events')->insertOrIgnore([
            'check_in_id'     => $checkIn->id,
            'organization_id' => $orgId,
            'hotel_id'        => $checkIn->hotel_id,
            'subscription_id' => $subscriptionId,
            'period'          => $consumedAt->copy()->startOfMonth()->toDateString(),
            'consumed_at'     => $consumedAt,
            'cancelled_at'    => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]) > 0;
    }

    /**
     * Version tolérante aux pannes : une déclaration n'est JAMAIS bloquée par
     * la facturation.
     *
     * Le point de vigilance : cet appel a lieu DANS la transaction de
     * finalisation. Sous PostgreSQL, une requête en erreur avorte la
     * transaction entière — avaler l'exception ne suffirait pas, tout ce qui
     * suit (watchlist, notifications, fiche de police) échouerait et la
     * finalisation serait perdue. On isole donc l'écriture derrière un
     * SAVEPOINT (transaction imbriquée) : si elle échoue, elle seule est
     * annulée et la déclaration se poursuit normalement.
     */
    public static function recordSafely(CheckIn $checkIn): void
    {
        try {
            DB::transaction(fn () => self::record($checkIn));
        } catch (\Throwable $e) {
            Log::error('[quota] consommation non enregistrée', [
                'check_in_id' => $checkIn->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Horodate l'annulation d'un séjour déjà déclaré — trace de transparence
     * pour le client et l'admin. La consommation RESTE due : la fiche a été
     * transmise, et un remboursement rétroactif rendrait l'historique de
     * facturation mutable (et le quota contournable en fin de mois).
     */
    public static function markCancelled(CheckIn $checkIn): void
    {
        try {
            // Même isolation par SAVEPOINT que recordSafely : appelé dans la
            // transaction d'annulation, un échec ici ne doit pas empêcher
            // l'annulation du séjour.
            DB::transaction(fn () => CheckinUsageEvent::where('check_in_id', $checkIn->id)
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now(), 'updated_at' => now()]));
        } catch (\Throwable $e) {
            Log::warning('[quota] annulation non horodatée', [
                'check_in_id' => $checkIn->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
