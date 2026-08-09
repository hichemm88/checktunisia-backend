<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le planificateur dit qu'il est vivant — deux fois, à deux publics.
 *
 * POURQUOI DEUX TÉMOINS. Qayed peut cesser de travailler sans tomber : le
 * serveur web répond pendant que le planificateur est mort, que la file n'est
 * plus drainée et que les fiches n'atteignent plus l'autorité. Il faut donc
 * que quelqu'un remarque le silence — et ce quelqu'un ne peut pas être une
 * tâche planifiée, qui se tairait au même instant.
 *
 *  1. BATTEMENT INTERNE (`beat`) — une clé de cache lue par le tableau de
 *     bord admin. L'observateur est le navigateur de l'administrateur, donc
 *     extérieur au système observé. Couvre les heures ouvrées.
 *
 *  2. SONDE EXTERNE (`ping`) — un appel à une URL tierce. L'alarme vit CHEZ
 *     LE TIERS : c'est lui qui crie quand les appels cessent d'arriver.
 *     C'est le seul montage qui couvre la nuit et le week-end, parce que
 *     rien de ce qui tourne dans notre conteneur ne peut garantir de crier
 *     quand ce conteneur se tait.
 *
 * Les deux sont INDÉPENDANTS : une sonde injoignable n'empêche pas le
 * battement interne, et réciproquement. Une sonde qui casserait ce qu'elle
 * observe serait pire que pas de sonde du tout.
 *
 * Voir docs/observabilite.md pour la configuration côté service tiers.
 */
class SchedulerHeartbeat
{
    /** Au-delà, le tableau de bord considère le planificateur muet. */
    public const CACHE_KEY = 'scheduler:last_run_at';

    /** Court : la sonde ne doit jamais retenir la tournée du planificateur. */
    private const TIMEOUT_SECONDS = 5;

    /**
     * Écrit le battement interne, puis tente la sonde externe.
     *
     * L'ordre compte : le battement d'abord, pour qu'une sonde défaillante ne
     * puisse jamais priver l'administrateur du seul témoin qu'il sait lire.
     */
    public function beat(): void
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addHour());

        $this->ping();
    }

    /**
     * Signale au service tiers que le planificateur a tourné.
     *
     * @return bool true si la sonde a bien été prévenue. false = non
     *              configurée ou injoignable — jamais une exception : le
     *              planificateur doit poursuivre sa tournée dans tous les cas.
     */
    public function ping(): bool
    {
        $url = config('monitoring.scheduler_ping_url');

        // Inerte tant que rien n'est branché : pas de sonde configurée n'est
        // pas une erreur, c'est simplement l'état par défaut du dépôt.
        if (blank($url)) {
            return false;
        }

        try {
            return Http::timeout(self::TIMEOUT_SECONDS)->get($url)->successful();
        } catch (\Throwable $e) {
            // L'URL porte un jeton : la journaliser reviendrait à publier la
            // capacité de faire taire l'alarme. On journalise la cause, jamais
            // la cible.
            Log::warning('[monitoring] sonde externe du planificateur injoignable', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
