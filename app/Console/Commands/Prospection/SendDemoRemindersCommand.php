<?php

namespace App\Console\Commands\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;
use Illuminate\Console\Command;

/**
 * Rappel de démo (§ Notifications push, déclencheur 2/3) : prévient TOUTE
 * l'équipe (pas de notion de "responsable" du prospect dans le modèle de
 * données) qu'une démo planifiée a lieu dans l'heure qui vient.
 *
 * `demo_reminder_sent_at` empêche un second rappel pour la même démo —
 * remis à zéro par EstablishmentController::update si la date est déplacée
 * (voir son commentaire), pour qu'une démo reportée soit re-rappelée.
 */
class SendDemoRemindersCommand extends Command
{
    protected $signature = 'prospection:send-demo-reminders';

    protected $description = "Rappelle les démos planifiées dans l'heure qui vient";

    public function handle(WebPushSender $sender): int
    {
        $now = now();

        $dueDemos = Establishment::where('archived', false)
            ->where('status', 'demo_planifiee')
            ->whereNotNull('next_action_at')
            ->whereBetween('next_action_at', [$now, $now->copy()->addHour()])
            ->whereNull('demo_reminder_sent_at')
            ->get();

        if ($dueDemos->isEmpty()) {
            $this->info('Rien à rappeler.');

            return self::SUCCESS;
        }

        $recipients = ProspectionUser::where('active', true)
            ->where('notif_demo_reminder_enabled', true)
            ->get();

        foreach ($dueDemos as $establishment) {
            $time = $establishment->next_action_at->timezone('Africa/Tunis')->format('H\hi');

            foreach ($recipients as $recipient) {
                $sender->sendToUser($recipient, 'Démo dans moins d\'une heure', "{$establishment->name} — {$time}");
            }

            $establishment->forceFill(['demo_reminder_sent_at' => now()])->save();
            $this->line("Rappelé — {$establishment->name} ({$time}).");
        }

        $this->info(count($dueDemos).' démo(s) rappelée(s).');

        return self::SUCCESS;
    }
}
