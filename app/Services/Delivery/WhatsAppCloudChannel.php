<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryChannel;
use App\Contracts\DeliveryResult;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use Illuminate\Support\Facades\Http;

/**
 * Canal WhatsApp Cloud API (officiel, Meta Graph API).
 *
 * Destiné à remplacer WhatsAppWebChannel avant le 10 septembre 2026, date à
 * laquelle expire le contournement par pin de version du relais non officiel.
 *
 * Canal en PUSH : PHP appelle directement l'API, il n'y a plus de worker Node,
 * plus de session à appairer, plus de QR code à renouveler — donc plus le
 * point unique de défaillance actuel.
 *
 * ⚠️ NON ACTIF tant que `whatsapp.channel` n'est pas basculé sur « cloud ».
 * Il se construit à côté de l'existant, qui reste en production jusqu'à
 * validation. Voir docs/canal-transmission.md.
 */
class WhatsAppCloudChannel implements DeliveryChannel
{
    public function name(): string
    {
        return 'whatsapp_cloud';
    }

    public function isConfigured(): bool
    {
        return (bool) config('whatsapp.enabled')
            && filled(config('whatsapp.cloud.token'))
            && filled(config('whatsapp.cloud.phone_number_id'));
    }

    public function supportsPush(): bool
    {
        return true;
    }

    /**
     * La Cloud API attend un numéro international NU, sans « + » ni suffixe —
     * là où WhatsApp Web exige un JID « …@c.us ». C'est précisément le genre
     * de détail qui aurait fuité partout sans cette interface.
     */
    public function formatRecipient(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        // Un numéro international plausible fait au moins 8 chiffres ; en deçà
        // c'est une saisie tronquée, que l'API rejetterait de toute façon.
        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    /**
     * Même règle de résolution que le canal historique — routage direct par
     * établissement, repli sur le destinataire global — mais formatée pour ce
     * canal. La règle métier ne dépend pas du transport.
     *
     * @return array<int,string>
     */
    public function recipientsFor(?Hotel $hotel): array
    {
        $global = $this->formatRecipient((string) config('whatsapp.recipient'));

        if ($hotel && config('whatsapp.direct_routing', true)) {
            $numbers = $hotel->whatsappRecipientProfiles()
                ->where('receives_whatsapp_fiches', true)
                ->whereNotNull('whatsapp_number')
                ->pluck('whatsapp_number')
                ->map(fn ($n) => $this->formatRecipient($n))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (! empty($numbers)) {
                return $numbers;
            }
        }

        return $global !== null ? [$global] : [];
    }

    public function send(WhatsappSendLog $job): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failedPermanently('Canal Cloud API non configuré.');
        }

        $recipient = $this->formatRecipient($job->recipient);

        if ($recipient === null) {
            // Inutile de retenter : le numéro ne deviendra pas valide.
            return DeliveryResult::failedPermanently('Destinataire invalide : '.$job->recipient);
        }

        $endpoint = sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            config('whatsapp.cloud.phone_number_id'),
        );

        try {
            $response = Http::withToken((string) config('whatsapp.cloud.token'))
                ->timeout((int) config('whatsapp.cloud.timeout', 30))
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => (string) $job->caption,
                    ],
                ]);
        } catch (\Throwable $e) {
            // Réseau injoignable : temporaire par nature.
            return DeliveryResult::failedTemporarily('Appel Cloud API impossible : '.$e->getMessage());
        }

        if ($response->successful()) {
            return DeliveryResult::sent($response->json('messages.0.id'));
        }

        $error = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

        // 4xx = la requête est fautive (numéro invalide, jeton expiré, message
        // refusé) : retenter à l'identique ne changera rien. 429 et 5xx sont
        // les seuls cas où réessayer a du sens.
        if ($response->status() === 429 || $response->serverError()) {
            return DeliveryResult::failedTemporarily($error);
        }

        return DeliveryResult::failedPermanently($error);
    }
}
