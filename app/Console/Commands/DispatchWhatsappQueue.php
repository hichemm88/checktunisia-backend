<?php

namespace App\Console\Commands;

use App\Services\Delivery\DeliveryChannelManager;
use App\Services\Whatsapp\WhatsappCloudConfig;
use App\Services\Whatsapp\WhatsappOutboxService;
use Illuminate\Console\Command;

/**
 * Vide la file WhatsApp par le canal actif, quand celui-ci transmet lui-même.
 *
 * Le relais WhatsApp Web fonctionnait en PULL : un worker Node venait
 * réclamer les jobs. La Cloud API fonctionne en PUSH — sans cette commande,
 * plus rien ne consomme la file et les fiches s'accumulent en silence.
 *
 * Inerte quand le canal actif est en pull : les deux peuvent coexister
 * pendant la période de bascule sans se marcher dessus (le verrou
 * `claimed_at` garantit qu'une fiche n'est prise qu'une fois).
 */
class DispatchWhatsappQueue extends Command
{
    protected $signature = 'whatsapp:dispatch {--max=50 : Nombre maximum de fiches traitées sur cette passe}';

    protected $description = 'Transmet les fiches WhatsApp en attente via le canal actif (Cloud API).';

    public function handle(WhatsappOutboxService $outbox, DeliveryChannelManager $channels): int
    {
        $channel = $channels->active();

        if (! $channel->supportsPush()) {
            $this->line("Canal actif « {$channel->name()} » : transmission en pull, rien à faire ici.");

            return self::SUCCESS;
        }

        if ($missing = WhatsappCloudConfig::missing()) {
            // Échec bruyant, avec la liste exacte : un canal mal configuré qui
            // se tait ressemble exactement à un canal qui n'a rien à envoyer.
            // C'est ainsi qu'un arriéré se constitue sans que personne ne voie.
            $this->error(WhatsappCloudConfig::explain($missing));

            return self::FAILURE;
        }

        if (! $channel->isConfigured()) {
            $this->error("Canal « {$channel->name()} » non configuré — aucune fiche ne peut partir.");

            return self::FAILURE;
        }

        $result = $outbox->dispatchPending((int) $this->option('max'));

        if ($result['blocked'] !== null) {
            $this->warn('Envoi suspendu — '.$result['blocked']);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d envoyée(s), %d en échec, %d annulée(s) (arriéré antérieur à la bascule).',
            $result['sent'],
            $result['failed'],
            $result['cancelled'],
        ));

        return self::SUCCESS;
    }
}
