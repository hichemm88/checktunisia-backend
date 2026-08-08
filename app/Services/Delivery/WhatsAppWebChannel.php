<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryChannel;
use App\Contracts\DeliveryResult;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;

/**
 * MODULE PROVISOIRE — relais WhatsApp Web (à retirer après homologation MI).
 *
 * EXTRACTION À COMPORTEMENT IDENTIQUE : cette classe ne fait que déplacer,
 * sans le modifier, ce qui vivait dans WhatsappOutboxService (formatage JID,
 * résolution des destinataires, condition d'activation). Aucune règle n'a été
 * ajoutée, retirée ni réordonnée — c'est ce que vérifient les 19 tests
 * existants de WhatsappRelayTest, qui passent sans retouche.
 *
 * Canal en PULL : le worker Node réclame les jobs via /internal/whatsapp/next
 * et rend compte du résultat. PHP ne transmet rien lui-même, d'où `send()`
 * qui refuse d'être appelé.
 */
class WhatsAppWebChannel implements DeliveryChannel
{
    public function name(): string
    {
        return 'whatsapp_web';
    }

    public function isConfigured(): bool
    {
        // Condition historique, reprise telle quelle depuis
        // WhatsappOutboxService::enabled().
        return (bool) config('whatsapp.enabled') && (bool) config('whatsapp.recipient');
    }

    public function supportsPush(): bool
    {
        return false;
    }

    /** Numéro (chiffres internationaux) → JID WhatsApp individuel. */
    public function formatRecipient(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        return $digits === '' ? null : $digits.'@c.us';
    }

    /**
     * Envoi direct : si le routage direct est actif ET que l'établissement a
     * des agents assignés qui reçoivent les fiches → leurs numéros. Sinon
     * REPLI sur le numéro global (comportement historique). Ainsi un
     * établissement non configuré ne change pas, et on bascule établissement
     * par établissement.
     *
     * @return array<int,string>
     */
    public function recipientsFor(?Hotel $hotel): array
    {
        $global = (string) config('whatsapp.recipient');

        if ($hotel && config('whatsapp.direct_routing', true)) {
            $jids = $hotel->whatsappRecipientProfiles()
                ->where('receives_whatsapp_fiches', true)
                ->whereNotNull('whatsapp_number')
                ->pluck('whatsapp_number')
                ->map(fn ($n) => $this->formatRecipient($n))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($jids)) {
                return $jids;
            }
        }

        return $global !== '' ? [$global] : [];
    }

    public function send(WhatsappSendLog $job): DeliveryResult
    {
        throw new \LogicException(
            'WhatsApp Web est un canal en pull : la transmission passe par le worker Node '
            .'(/internal/whatsapp/next), jamais par un appel direct depuis PHP.'
        );
    }
}
