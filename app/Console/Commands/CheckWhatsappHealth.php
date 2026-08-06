<?php

namespace App\Console\Commands;

use App\Models\WhatsappSessionState;
use App\Services\Whatsapp\WhatsappAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 *
 * Chantier B3 : une session WhatsApp cassée doit être visible et notifiée en
 * moins de 10 minutes. Le worker signale lui-même ses déconnexions (alerte
 * sessionDown) — cette commande couvre le cas où il est MORT et ne signale
 * plus rien : battement de cœur périmé → alerte admin, une seule fois par
 * panne (flag en cache, levé au retour du heartbeat).
 */
class CheckWhatsappHealth extends Command
{
    protected $signature = 'whatsapp:check-health';

    protected $description = 'Alerte les admins si le worker WhatsApp est silencieux depuis plus de 10 minutes';

    private const STALE_MINUTES = 10;

    private const ALERT_FLAG = 'whatsapp:worker-silent-alerted';

    // Session non prête alors que le worker répond : cas jamais couvert par les
    // autres garde-fous (le worker n'envoie sessionDown que sur transition vers
    // disconnected/auth_failure ; un redémarrage bloqué en « initializing » ou
    // un POST « ready » perdu ne déclenchait donc AUCUNE alerte — file gelée en
    // silence pendant des heures, incident du 06/08).
    private const NOT_READY_MINUTES = 15;

    private const NOT_READY_FLAG = 'whatsapp:session-not-ready-alerted';

    public function handle(WhatsappAlertService $alerts): void
    {
        if (! config('whatsapp.enabled')) {
            return;
        }

        $state = WhatsappSessionState::current();
        $heartbeat = $state->heartbeat_at;
        $silent = $heartbeat === null || $heartbeat->diffInMinutes(now()) >= self::STALE_MINUTES;

        if ($silent) {
            if (Cache::get(self::ALERT_FLAG)) {
                $this->info('Worker silencieux — alerte déjà envoyée pour cette panne.');

                return;
            }

            $minutes = $heartbeat ? (int) $heartbeat->diffInMinutes(now()) : self::STALE_MINUTES;
            $alerts->workerSilent($minutes);
            Cache::put(self::ALERT_FLAG, true, now()->addDay());
            $this->warn("Worker silencieux depuis {$minutes} min — alerte envoyée.");

            return;
        }

        Cache::forget(self::ALERT_FLAG);

        // Worker vivant : la session est-elle réellement prête ?
        if ($state->isReady()) {
            Cache::forget(self::NOT_READY_FLAG);
            $this->info('Worker vivant, session prête (heartbeat '.$heartbeat?->diffForHumans().').');

            return;
        }

        $downSince = $state->last_ready_at;
        $downMinutes = $downSince ? (int) $downSince->diffInMinutes(now()) : self::NOT_READY_MINUTES;

        if ($downMinutes < self::NOT_READY_MINUTES) {
            $this->info("Session « {$state->status} » depuis {$downMinutes} min — on laisse le temps de se reconnecter.");

            return;
        }

        if (Cache::get(self::NOT_READY_FLAG)) {
            $this->info('Session non prête — alerte déjà envoyée pour cette panne.');

            return;
        }

        $alerts->sessionDown(
            $state->status,
            'La session n\'est plus opérationnelle depuis '
            .($downSince ? $downSince->format('d/m H:i') : 'un moment')
            ." ({$downMinutes} min). Les fiches s'accumulent en attente et repartiront à la reconnexion.",
        );
        Cache::put(self::NOT_READY_FLAG, true, now()->addDay());
        $this->warn("Session « {$state->status} » depuis {$downMinutes} min — alerte envoyée.");
    }
}
