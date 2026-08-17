<?php

namespace App\Console\Commands;

use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Vide la file d'envoi par un canal en PUSH (Cloud API).
 *
 * ── Ce que cette commande remplace ───────────────────────────────────────────
 *
 * En PULL (WhatsApp Web), c'est le worker Node qui réclame les fiches et rend
 * compte : PHP ne transmet rien. En PUSH, ce worker n'existe plus — personne
 * ne consommait la file. `WhatsAppCloudChannel::send()` était écrit et testé,
 * mais rien ne l'appelait ; c'était la dernière pièce manquante de la bascule
 * (voir docs/canal-transmission.md).
 *
 * ── Les garde-fous sont ceux du canal historique, délibérément ───────────────
 *
 * Cadence, plafond horaire, montée en charge après appairage, disjoncteur :
 * tout vit déjà dans WhatsappOutboxService, et rien de tout cela n'était propre
 * à WhatsApp Web. Ces règles ont été écrites après que Meta a restreint le
 * numéro émetteur le 17/08/2026 ; les rejouer à l'identique ici évite de
 * réapprendre la leçon sur le canal officiel — où une suspension coûterait le
 * numéro professionnel vérifié, autrement plus cher qu'une SIM.
 *
 * Inerte si le canal actif est en pull : la commande peut donc rester planifiée
 * en permanence, avant comme après la bascule.
 */
class PushWhatsappQueue extends Command
{
    /**
     * Le plafond borne la DURÉE d'une exécution, pas seulement le volume : à
     * 45 s de cadence, 10 fiches font déjà ~7 min. Il doit rester sous le
     * verrou anti-chevauchement de l'ordonnanceur (voir routes/console.php),
     * sinon une exécution lente empêcherait la suivante de démarrer.
     */
    protected $signature = 'whatsapp:push
        {--max=10 : Nombre maximum de fiches transmises par exécution}';

    protected $description = 'Transmet les fiches en attente via le canal push (Cloud API)';

    public function handle(WhatsappOutboxService $outbox, DeliveryChannelManager $channels): int
    {
        $channel = $channels->active();

        if (!$channel->supportsPush()) {
            // Canal en pull : le worker externe s'en charge. Ce n'est pas une
            // erreur, c'est le cas nominal tant que la bascule n'a pas eu lieu.
            return self::SUCCESS;
        }

        if (!$channel->isConfigured()) {
            $this->warn('Canal '.$channel->name().' non configuré — rien à faire.');

            return self::SUCCESS;
        }

        $max = max(1, (int) $this->option('max'));
        $sent = 0;
        $refusals = 0;
        $ceiling = (int) config('whatsapp.circuit_breaker_failures', 5);

        for ($i = 0; $i < $max; $i++) {
            $job = $outbox->claimNextJob();

            // Plus rien à envoyer, ou un garde-fou a fermé le robinet (pause,
            // plafond horaire). Dans les deux cas on rend la main : la
            // prochaine exécution reprendra où celle-ci s'arrête.
            if (!$job) {
                break;
            }

            /*
             * Cadence : le plancher est le même qu'en pull, gigue comprise. Une
             * rafale est ce que les heuristiques anti-spam repèrent en premier,
             * canal officiel ou non.
             *
             * L'attente est placée AVANT l'envoi, et seulement quand une fiche
             * est en main. Placée après, elle s'appliquait aussi au dernier
             * envoi d'une file vidée : une exécution planifiée à la minute
             * restait bloquée 45 s pour rien, verrou compris.
             */
            if ($sent > 0) {
                usleep($this->intervalMicroseconds($outbox));
            }

            $result = $channel->send($job);

            if ($result->success) {
                $outbox->markSent($job, $result->messageId);
                $sent++;
                $refusals = 0;

                continue;
            }

            $outbox->markFailed($job, $result->error);

            // Une erreur définitive rendue par le canal est un refus : numéro
            // invalide, message rejeté, compte suspendu. Une erreur temporaire
            // (réseau, 5xx) ne dit rien de notre légitimité — elle ne compte pas.
            if ($result->retryable) {
                break;
            }

            $refusals++;
            if ($refusals >= $ceiling) {
                $outbox->tripCircuitBreaker(
                    $refusals.' envois refusés d\'affilée par '.$channel->name().' — dernière erreur : '.$result->error,
                );
                break;
            }
        }

        if ($sent > 0) {
            Log::info('[whatsapp-push] '.$sent.' fiche(s) transmise(s) par '.$channel->name().'.');
        }

        $this->info($sent.' fiche(s) transmise(s).');

        return self::SUCCESS;
    }

    /** Plancher de cadence + gigue, tels que définis pour le relais. */
    private function intervalMicroseconds(WhatsappOutboxService $outbox): int
    {
        $seconds = max(1, (int) $outbox->throttle()['min_interval_seconds']);
        $ratio = min(max((float) config('whatsapp.interval_jitter_ratio', 0.4), 0), 1);

        return (int) round(($seconds + mt_rand(0, 1000) / 1000 * $ratio * $seconds) * 1_000_000);
    }
}
