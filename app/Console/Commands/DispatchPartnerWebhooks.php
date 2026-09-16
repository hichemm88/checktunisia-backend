<?php

namespace App\Console\Commands;

use App\Services\PartnerApi\FicheSessionService;
use App\Services\PartnerApi\PartnerWebhookOutboxService;
use Illuminate\Console\Command;

/**
 * Livre les webhooks partenaires en attente (§4) et balaie les sessions
 * expirées (émet session.expired). Même pattern outbox que whatsapp:dispatch.
 */
class DispatchPartnerWebhooks extends Command
{
    protected $signature = 'partner-webhooks:dispatch {--max=50 : Nombre maximum de livraisons traitées sur cette passe}';

    protected $description = 'Transmet les webhooks partenaires en attente et expire les sessions de fiche périmées.';

    public function handle(PartnerWebhookOutboxService $outbox, FicheSessionService $sessions): int
    {
        $expired = $sessions->expireStaleSessions();
        $result = $outbox->dispatchPending((int) $this->option('max'));

        $this->info(sprintf(
            '%d session(s) expirée(s), %d webhook(s) envoyé(s), %d en échec.',
            $expired,
            $result['sent'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
