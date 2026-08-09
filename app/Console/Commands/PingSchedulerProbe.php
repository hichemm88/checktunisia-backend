<?php

namespace App\Console\Commands;

use App\Services\Observability\SchedulerHeartbeat;
use Illuminate\Console\Command;

/**
 * Vérifie à la main que la sonde externe est réellement branchée.
 *
 * Une sonde mal configurée est pire qu'aucune sonde : elle donne l'illusion
 * d'être couvert. Cette commande permet de le vérifier AVANT de compter
 * dessus — et de le revérifier après un changement d'environnement.
 */
class PingSchedulerProbe extends Command
{
    protected $signature = 'qayed:scheduler-ping';

    protected $description = 'Appelle la sonde externe du planificateur (dead-man\'s switch) et rapporte si elle est branchée';

    public function handle(SchedulerHeartbeat $heartbeat): int
    {
        if (blank(config('monitoring.scheduler_ping_url'))) {
            // Pas une erreur : c'est l'état par défaut du dépôt. Mais il faut
            // que l'exploitant sache qu'il n'est PAS couvert la nuit.
            $this->warn('Aucune sonde configurée (SCHEDULER_PING_URL vide).');
            $this->line('La mort du planificateur hors heures ouvrées ne sera pas détectée.');
            $this->line('Voir docs/observabilite.md.');

            return self::SUCCESS;
        }

        // L'URL n'est jamais affichée : elle porte un jeton.
        if ($heartbeat->ping()) {
            $this->info('Sonde externe prévenue.');

            return self::SUCCESS;
        }

        $this->error('Sonde configurée mais injoignable — vérifiez l\'URL et le réseau sortant.');

        return self::FAILURE;
    }
}
