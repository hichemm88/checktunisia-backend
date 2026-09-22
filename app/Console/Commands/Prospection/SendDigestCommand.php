<?php

namespace App\Console\Commands\Prospection;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionUser;
use App\Services\Prospection\WebPushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Récap du matin (§ Notifications push, déclencheur 1/3) : relances
 * dues/en retard + démos du jour, à l'heure choisie par chaque utilisateur
 * (`notif_digest_hour`, Africa/Tunis). Planifiée toutes les 15 minutes (voir
 * routes/console.php) — l'heure choisie doit donc tomber sur :00/:15/:30/:45
 * pour être atteinte exactement ; l'écran Réglages ne propose que ces
 * valeurs.
 *
 * `last_digest_sent_at` (date, pas timestamp) empêche un doublon si la
 * commande est relancée dans la même fenêtre de 15 minutes.
 */
class SendDigestCommand extends Command
{
    protected $signature = 'prospection:send-digest';

    protected $description = "Envoie le récap du matin aux utilisateurs dont l'heure configurée vient de sonner";

    public function handle(WebPushSender $sender): int
    {
        $now = now('Africa/Tunis');
        $currentHour = $now->format('H:i');
        $today = $now->toDateString();

        $users = ProspectionUser::where('active', true)
            ->where('notif_digest_enabled', true)
            ->where('notif_digest_hour', $currentHour)
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            if ($user->last_digest_sent_at?->toDateString() === $today) {
                continue;
            }

            [$relances, $demos] = $this->countsFor($now);

            $sender->sendToUser(
                $user,
                'Qayed CRM — récap du matin',
                "{$relances} relance(s) à faire, {$demos} démo(s) aujourd'hui.",
            );

            $user->forceFill(['last_digest_sent_at' => $today])->save();
            $sent++;
        }

        $this->info("Done. {$sent} digest(s) envoyé(s).");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} */
    private function countsFor(Carbon $now): array
    {
        $endOfDay = $now->copy()->endOfDay();

        $relances = Establishment::where('archived', false)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', $endOfDay)
            ->where('status', '!=', 'demo_planifiee')
            ->count();

        $demos = Establishment::where('archived', false)
            ->where('status', 'demo_planifiee')
            ->whereNotNull('next_action_at')
            ->whereBetween('next_action_at', [$now->copy()->startOfDay(), $endOfDay])
            ->count();

        return [$relances, $demos];
    }
}
