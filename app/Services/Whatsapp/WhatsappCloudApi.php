<?php

namespace App\Services\Whatsapp;

use App\Contracts\DeliveryResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client bas niveau de la WhatsApp Cloud API (Meta Graph).
 *
 * Un seul endroit sait parler à Meta : construire les URL, porter le jeton,
 * lire la forme de réponse, traduire les erreurs. Le reste du produit ne
 * connaît que `DeliveryResult`.
 *
 * Ce que cette classe ne fait PAS, volontairement :
 *  - pas de retry interne. La file (whatsapp_send_log) porte déjà le backoff,
 *    la limite d'âge de 24 h et l'alerte finale ; un second mécanisme de
 *    reprise caché ici rendrait les tentatives impossibles à compter.
 *  - pas de journalisation du contenu. Une fiche de police est une donnée
 *    personnelle : ni le corps du message, ni le jeton, ni le numéro complet
 *    du destinataire ne sortent dans les journaux.
 */
class WhatsappCloudApi
{
    public function isConfigured(): bool
    {
        return filled($this->token()) && filled($this->phoneNumberId());
    }

    /** La gestion des modèles vise le compte (WABA), pas le numéro émetteur. */
    public function canManageTemplates(): bool
    {
        return filled($this->token()) && filled(config('whatsapp.cloud.waba_id'));
    }

    /**
     * Envoi d'un modèle approuvé — le SEUL mode qui passe hors fenêtre de 24 h,
     * donc le mode nominal pour une fiche de police (personne ne « répond » à
     * une fiche : la fenêtre est toujours fermée).
     *
     * @param  string  $to  numéro international nu (ex. « 21620123456 »)
     * @param  array<int,array<string,mixed>>  $components  composants Meta (header/body/button)
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components = []): DeliveryResult
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if (! empty($components)) {
            $template['components'] = array_values($components);
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ], 'template:'.$templateName);
    }

    /**
     * Texte libre. N'aboutit QUE dans les 24 h suivant un message entrant du
     * destinataire — sinon Meta répond 131047. Conservé pour le diagnostic
     * (répondre à un agent qui vient d'écrire), pas pour les fiches.
     */
    public function sendText(string $to, string $body): DeliveryResult
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ], 'text');
    }

    /**
     * Modèles déclarés sur le compte, indexés par « nom:langue ».
     *
     * @return array<string,array<string,mixed>>
     *
     * @throws \RuntimeException si l'API refuse la lecture
     */
    public function listTemplates(?string $name = null): array
    {
        $query = ['limit' => 100];
        if ($name !== null) {
            $query['name'] = $name;
        }

        $response = $this->client()->get($this->wabaEndpoint('message_templates'), $query);

        if (! $response->successful()) {
            throw new \RuntimeException($this->describeFailure($response));
        }

        $indexed = [];
        foreach ((array) $response->json('data', []) as $template) {
            if (! isset($template['name'], $template['language'])) {
                continue;
            }
            $indexed[$template['name'].':'.$template['language']] = $template;
        }

        return $indexed;
    }

    /**
     * Crée un modèle. Meta répond immédiatement avec un identifiant et un
     * statut initial (généralement PENDING) : l'approbation, elle, est
     * asynchrone et hors de notre contrôle.
     *
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     *
     * @throws \RuntimeException si la création est refusée
     */
    public function createTemplate(array $definition): array
    {
        $response = $this->client()->post($this->wabaEndpoint('message_templates'), $definition);

        if (! $response->successful()) {
            throw new \RuntimeException($this->describeFailure($response));
        }

        return (array) $response->json();
    }

    // ── Interne ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $payload
     * @param  string  $kind  étiquette de journalisation (jamais le contenu)
     */
    private function postMessage(array $payload, string $kind): DeliveryResult
    {
        if (! $this->isConfigured()) {
            return DeliveryResult::failedPermanently(
                'Cloud API non configurée (jeton ou identifiant de numéro manquant).'
            );
        }

        try {
            $response = $this->client()->post($this->messagesEndpoint(), $payload);
        } catch (\Throwable $e) {
            // Réseau injoignable, DNS, délai dépassé : transitoire par nature.
            // Le message de l'exception ne porte pas de secret — le jeton
            // voyage en en-tête, jamais dans l'URL.
            return DeliveryResult::failedTemporarily('Appel Cloud API impossible : '.$e->getMessage());
        }

        if ($response->successful()) {
            $wamid = $response->json('messages.0.id');

            // Un 200 SANS identifiant signifie qu'on ne pourra jamais corréler
            // l'accusé de réception du webhook : succès inexploitable, il doit
            // se voir plutôt que de passer pour un envoi normal.
            if (blank($wamid)) {
                Log::warning('[whatsapp-cloud] envoi accepté sans wamid', [
                    'kind' => $kind,
                    'to' => $this->maskNumber($payload['to'] ?? null),
                ]);
            }

            return DeliveryResult::sent(is_string($wamid) ? $wamid : null);
        }

        return $this->toFailure($response, $kind, $payload['to'] ?? null);
    }

    private function toFailure(Response $response, string $kind, ?string $to): DeliveryResult
    {
        $rawCode = $response->json('error.code');
        $code = is_numeric($rawCode) ? (int) $rawCode : null;
        $metaMessage = $response->json('error.message');
        $metaMessage = is_string($metaMessage) ? $metaMessage : null;

        $description = WhatsappCloudErrors::describe($code, $metaMessage);
        $retryable = WhatsappCloudErrors::isRetryable($code, $response->status());
        $critical = WhatsappCloudErrors::isCritical($code);

        Log::warning('[whatsapp-cloud] envoi refusé', [
            'kind' => $kind,
            'to' => $this->maskNumber($to),
            'http' => $response->status(),
            'code' => $code,
            'subcode' => $response->json('error.error_subcode'),
            'retryable' => $retryable,
            'critical' => $critical,
            // Libellé Meta uniquement : jamais le corps du message, jamais le jeton.
            'meta' => $metaMessage,
        ]);

        return $retryable
            ? DeliveryResult::failedTemporarily($description, $code !== null ? (string) $code : null)
            : DeliveryResult::failedPermanently($description, $code !== null ? (string) $code : null, $critical);
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->token())
            ->acceptJson()
            ->timeout((int) config('whatsapp.cloud.timeout', 30));
    }

    private function messagesEndpoint(): string
    {
        return $this->graph($this->phoneNumberId().'/messages');
    }

    private function wabaEndpoint(string $resource): string
    {
        return $this->graph(config('whatsapp.cloud.waba_id').'/'.$resource);
    }

    private function graph(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            ltrim($path, '/'),
        );
    }

    private function token(): ?string
    {
        return config('whatsapp.cloud.token');
    }

    private function phoneNumberId(): ?string
    {
        return config('whatsapp.cloud.phone_number_id');
    }

    private function describeFailure(Response $response): string
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        return WhatsappCloudErrors::describe(
            is_numeric($code) ? (int) $code : null,
            is_string($message) ? $message : 'HTTP '.$response->status(),
        );
    }

    /**
     * Numéro tronqué pour les journaux : « 216…456 ». Assez pour recouper un
     * incident avec une ligne du journal admin, pas assez pour reconstituer un
     * annuaire de policiers à partir des logs.
     */
    private function maskNumber(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        return strlen($number) <= 6
            ? str_repeat('•', strlen($number))
            : substr($number, 0, 3).'…'.substr($number, -3);
    }
}
