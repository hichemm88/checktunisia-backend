<?php

namespace App\Observers\Prospection;

use App\Models\Prospection\ProspectionAction;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\PipelineStatus;
use App\Services\Prospection\WebPushSender;

/**
 * Déclencheur "activité de l'équipe" (§ Notifications push, 3 déclencheurs
 * exacts — jamais d'auto-notification). Un événement Eloquent plutôt qu'un
 * appel explicite dans chaque contrôleur : ProspectionAction::create() a
 * plusieurs points d'entrée (ActionController, EstablishmentStatusUpdater,
 * ImportService) et tous doivent se comporter pareil sans y penser à chaque
 * fois.
 *
 * Volontairement limité aux types "bonne nouvelle à partager", pas chaque
 * action journalisée (un appel ou une note ne notifient personne) — sinon
 * l'équipe désactiverait la notif au bout d'un jour.
 */
class ProspectionActionObserver
{
    private const SIGNIFICANT_TYPES = ['reponse_recue', 'essai_active', 'changement_statut'];

    public function created(ProspectionAction $action): void
    {
        if (!in_array($action->type, self::SIGNIFICANT_TYPES, true)) {
            return;
        }

        // Un changement de statut n'est "notable" que s'il avance vers une
        // étape positive du pipeline — un retour en arrière ("démo faite →
        // contacté" après une démo annulée) n'a rien d'une bonne nouvelle.
        if ($action->type === 'changement_statut' && !str_contains((string) $action->content, '→ client')
            && !str_contains((string) $action->content, '→ essai_en_cours')
            && !str_contains((string) $action->content, '→ demo_planifiee')) {
            return;
        }

        $establishment = $action->establishment;
        if (!$establishment) {
            return;
        }

        $recipients = ProspectionUser::where('active', true)
            ->where('notif_activity_enabled', true)
            ->where('id', '!=', $action->created_by)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $body = $this->describe($action);
        $sender = app(WebPushSender::class);

        foreach ($recipients as $recipient) {
            $sender->sendToUser($recipient, "Activité — {$establishment->name}", $body);
        }
    }

    private function describe(ProspectionAction $action): string
    {
        return match ($action->type) {
            'reponse_recue' => 'Réponse reçue'.($action->content ? " — {$action->content}" : '.'),
            'essai_active' => 'Essai activé.',
            'changement_statut' => $this->describeStatusChange((string) $action->content),
            default => 'Nouvelle activité.',
        };
    }

    /** @param  string  $content  "ancien_statut → nouveau_statut" (voir EstablishmentStatusUpdater). */
    private function describeStatusChange(string $content): string
    {
        [$previous, $next] = array_pad(explode(' → ', $content, 2), 2, null);

        if (!$previous || !$next) {
            return $content;
        }

        return 'Passé de « '.PipelineStatus::label($previous).' » à « '.PipelineStatus::label($next).' ».';
    }
}
