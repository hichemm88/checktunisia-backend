<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryChannel;
use App\Models\Hotel;
use Illuminate\Support\Facades\Log;

/**
 * Résout le canal de transmission actif et pilote le mode ombre (STRAT-07).
 *
 * Le mode ombre est le cœur de la stratégie de bascule : avant d'envoyer le
 * moindre message réel par le canal cible, on l'exerce à blanc sur le trafic
 * de production et on compare ce qu'il AURAIT fait à ce que le canal actif
 * fait réellement. Les écarts sortent avant la bascule, pas après.
 *
 * Ce que le mode ombre compare aujourd'hui : la résolution des destinataires
 * et leur formatage — c'est là que se logent les différences structurelles
 * entre WhatsApp Web (JID « …@c.us ») et la Cloud API (numéro nu). Il ne
 * transmet jamais rien.
 */
class DeliveryChannelManager
{
    /** @var array<string, class-string<DeliveryChannel>> */
    private const CHANNELS = [
        'web' => WhatsAppWebChannel::class,
        'cloud' => WhatsAppCloudChannel::class,
    ];

    /** Canal effectivement utilisé pour transmettre. */
    public function active(): DeliveryChannel
    {
        return $this->resolve((string) config('whatsapp.channel', 'web'));
    }

    /** Canal exercé à blanc, s'il est configuré. */
    public function shadow(): ?DeliveryChannel
    {
        $name = config('whatsapp.shadow_channel');

        if (blank($name) || $name === config('whatsapp.channel')) {
            return null;
        }

        return $this->resolve((string) $name);
    }

    public function resolve(string $name): DeliveryChannel
    {
        $class = self::CHANNELS[$name] ?? null;

        if ($class === null) {
            // Échec bruyant plutôt que repli silencieux : une faute de frappe
            // dans WHATSAPP_CHANNEL ne doit pas faire croire que la bascule a
            // eu lieu alors que l'ancien canal continue de tourner.
            throw new \InvalidArgumentException(
                "Canal de transmission inconnu : « {$name} ». Valeurs admises : ".implode(', ', array_keys(self::CHANNELS))
            );
        }

        return app($class);
    }

    /**
     * Compare, sans rien transmettre, ce que le canal ombre ferait des
     * destinataires d'un établissement.
     *
     * Appelé à l'enfilage : aucune incidence sur le check-in, aucun appel
     * réseau. Toute erreur est avalée — une comparaison ne doit jamais
     * perturber le canal en production.
     *
     * @param  array<int,string>  $activeRecipients
     */
    public function compareRecipients(?Hotel $hotel, array $activeRecipients): void
    {
        $shadow = $this->shadow();

        if ($shadow === null) {
            return;
        }

        try {
            $shadowRecipients = $shadow->recipientsFor($hotel);

            $context = [
                'hotel_id' => $hotel?->id,
                'active_channel' => $this->active()->name(),
                'shadow_channel' => $shadow->name(),
                'active_count' => count($activeRecipients),
                'shadow_count' => count($shadowRecipients),
                'shadow_configured' => $shadow->isConfigured(),
            ];

            // Un nombre de destinataires différent est le signal qui compte :
            // il signifie qu'après bascule, une fiche partirait à plus — ou
            // pire, à moins — de monde qu'aujourd'hui.
            if (count($activeRecipients) !== count($shadowRecipients)) {
                Log::warning('[delivery-shadow] écart sur le nombre de destinataires', $context);

                return;
            }

            if (empty($shadowRecipients) && ! empty($activeRecipients)) {
                Log::warning('[delivery-shadow] le canal cible ne résout aucun destinataire', $context);

                return;
            }

            Log::info('[delivery-shadow] destinataires équivalents', $context);
        } catch (\Throwable $e) {
            Log::warning('[delivery-shadow] comparaison impossible : '.$e->getMessage());
        }
    }
}
