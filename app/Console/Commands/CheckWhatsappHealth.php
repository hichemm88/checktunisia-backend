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
    protected $signature   = 'whatsapp:check-health';
    protected $description = 'Alerte les admins si le worker WhatsApp est silencieux depuis plus de 10 minutes';

    private const STALE_MINUTES = 10;
    private const ALERT_FLAG    = 'whatsapp:worker-silent-alerted';

    public function handle(WhatsappAlertService $alerts): void
    {
        if (! config('whatsapp.enabled')) {
            return;
        }

        $state = WhatsappSessionState::current();
        $heartbeat = $state->heartbeat_at;
        $silent = $heartbeat === null || $heartbeat->diffInMinutes(now()) >= self::STALE_MINUTES;

        if (! $silent) {
            Cache::forget(self::ALERT_FLAG);
            $this->info('Worker vivant (heartbeat '.$heartbeat?->diffForHumans().').');

            return;
        }

        if (Cache::get(self::ALERT_FLAG)) {
            $this->info('Worker silencieux — alerte déjà envoyée pour cette panne.');

            return;
        }

        $minutes = $heartbeat ? (int) $heartbeat->diffInMinutes(now()) : self::STALE_MINUTES;
        $alerts->workerSilent($minutes);
        Cache::put(self::ALERT_FLAG, true, now()->addDay());
        $this->warn("Worker silencieux depuis {$minutes} min — alerte envoyée.");
    }
}
