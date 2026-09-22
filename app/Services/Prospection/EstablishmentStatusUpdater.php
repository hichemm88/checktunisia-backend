<?php

namespace App\Services\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use Illuminate\Validation\ValidationException;

/**
 * Change le statut d'un prospect et applique les effets de bord du § Pipeline :
 *
 * - tout changement crée une Action append-only de type changement_statut ;
 * - passer en "contacté" ou "relancé" propose J+4 par défaut si aucune date
 *   de prochaine action n'est fournie explicitement ;
 * - passer en "démo planifiée" EXIGE une date (celle de la démo).
 */
class EstablishmentStatusUpdater
{
    /**
     * @param  string|null  $nextActionAt  Date ISO fournie par l'appelant, ou null.
     */
    public static function apply(
        Establishment $establishment,
        string $newStatus,
        ?string $nextActionAt,
        ?string $userId,
    ): void {
        if (!PipelineStatus::isValid($newStatus)) {
            throw ValidationException::withMessages(['status' => ['Statut inconnu.']]);
        }

        $previousStatus = $establishment->status;

        if ($newStatus === PipelineStatus::DEMO_PLANIFIEE && !$nextActionAt) {
            throw ValidationException::withMessages([
                'next_action_at' => ['La date et l\'heure de la démo sont requises pour ce statut.'],
            ]);
        }

        if ($nextActionAt) {
            $establishment->next_action_at = $nextActionAt;
        } elseif (in_array($newStatus, PipelineStatus::PROPOSES_FOLLOW_UP, true)) {
            $establishment->next_action_at = now()->addDays(PipelineStatus::DEFAULT_FOLLOW_UP_DAYS);
        } elseif (PipelineStatus::isTerminal($newStatus)) {
            // Un statut terminal n'attend plus de relance.
            $establishment->next_action_at = null;
        }

        $establishment->status = $newStatus;
        $establishment->save();

        if ($previousStatus !== $newStatus) {
            ProspectionAction::create([
                'establishment_id' => $establishment->id,
                'type' => 'changement_statut',
                'content' => "{$previousStatus} → {$newStatus}",
                'occurred_at' => now(),
                'created_by' => $userId,
            ]);
        }
    }
}
