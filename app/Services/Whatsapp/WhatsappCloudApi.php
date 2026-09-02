<?php

namespace App\Services\Whatsapp;

use App\Contracts\DeliveryResult;
use App\Services\Delivery\TransientDeliveryFailure;
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
     * Téléverse un média et renvoie son identifiant, valable 30 jours.
     *
     * La Cloud API n'accepte aucune pièce jointe en ligne : il faut cet appel
     * préalable à /media. Rien n'est mis en cache — une fiche n'est envoyée
     * qu'une fois, et un identifiant périmé coûterait plus cher à diagnostiquer
     * que le téléversement à refaire.
     *
     * Lève TransientDeliveryFailure plutôt que de renvoyer un DeliveryResult :
     * l'échec survient pendant la PRÉPARATION, la fiche est intacte et
     * repartira au prochain tour. Le confondre avec un refus de Meta ferait
     * marquer « définitivement échouée » une fiche que rien n'empêche
     * d'envoyer — sur un canal légal, la nuance décide si la fiche part ou non.
     *
     * @throws TransientDeliveryFailure
     */
    public function uploadMedia(string $contents, string $filename, string $mimeType = 'application/pdf'): string
    {
        if (! $this->isConfigured()) {
            throw new TransientDeliveryFailure('Cloud API non configurée : téléversement impossible.');
        }

        try {
            $response = $this->client()
                ->attach('file', $contents, $filename, ['Content-Type' => $mimeType])
                ->post($this->graph($this->phoneNumberId().'/media'), [
                    'messaging_product' => 'whatsapp',
                ]);
        } catch (\Throwable $e) {
            throw new TransientDeliveryFailure('Téléversement de la fiche impossible : '.$e->getMessage());
        }

        $id = $response->successful() ? $response->json('id') : null;

        if (blank($id)) {
            $code = $response->json('error.code');
            $message = $response->json('error.message');

            Log::warning('[whatsapp-cloud] téléversement refusé', [
                'http' => $response->status(),
                'code' => $code,
                // Le nom du fichier porte le nom du voyageur : on ne le
                // journalise pas. Sa taille suffit au diagnostic.
                'bytes' => strlen($contents),
            ]);

            throw new TransientDeliveryFailure(
                'Téléversement de la fiche refusé : '.WhatsappCloudErrors::describe(
                    is_numeric($code) ? (int) $code : null,
                    is_string($message) ? $message : 'HTTP '.$response->status(),
                )
            );
        }

        return (string) $id;
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
     * Téléverse un fichier d'EXEMPLE et renvoie son `header_handle`.
     *
     * À ne pas confondre avec `uploadMedia()`, bien que les deux « téléversent
     * un PDF ». Ce sont deux API distinctes, sur deux objets différents :
     *
     *  - `/{phone_number_id}/media` sert à ENVOYER une pièce jointe dans un
     *    message. Renvoie un identifiant valable 30 jours.
     *  - `/{app_id}/uploads` (Resumable Upload) sert à faire APPROUVER un
     *    modèle dont l'en-tête est un média. Renvoie un handle permanent, seul
     *    format accepté dans le champ `example.header_handle`.
     *
     * Sans ce handle, Meta REFUSE la création d'un modèle à en-tête DOCUMENT.
     * C'est le genre de détail qui ne se découvre qu'en essuyant le refus.
     *
     * Deux temps : ouvrir une session d'envoi, puis pousser les octets.
     *
     * @return string handle à placer dans example.header_handle
     *
     * @throws \RuntimeException
     */
    public function uploadTemplateSample(string $contents, string $filename, string $mimeType = 'application/pdf'): string
    {
        $appId = config('whatsapp.cloud.app_id');

        if (blank($appId)) {
            throw new \RuntimeException(
                'WHATSAPP_APP_ID est requis pour téléverser l\'exemple de média du modèle.'
            );
        }

        // 1. Session d'envoi. Le jeton d'application, et non celui de
        //    l'utilisateur système : l'objet visé est l'app, pas le numéro.
        $appToken = $appId.'|'.config('whatsapp.cloud.app_secret');

        $session = Http::acceptJson()
            ->timeout((int) config('whatsapp.cloud.timeout', 30))
            ->post($this->graph($appId.'/uploads'), [
                'file_name' => $filename,
                'file_length' => strlen($contents),
                'file_type' => $mimeType,
                'access_token' => $appToken,
            ]);

        $sessionId = $session->json('id');

        if (! $session->successful() || blank($sessionId)) {
            throw new \RuntimeException(
                'Ouverture de la session de téléversement refusée : '.$this->describeFailure($session)
            );
        }

        // 2. Les octets. `file_offset: 0` — un seul morceau : nos exemples font
        //    quelques dizaines de kilo-octets, la reprise n'a pas d'objet.
        $upload = Http::withHeaders([
            'Authorization' => 'OAuth '.$appToken,
            'file_offset' => '0',
            'Content-Type' => $mimeType,
        ])
            ->timeout((int) config('whatsapp.cloud.timeout', 30))
            ->withBody($contents, $mimeType)
            ->post($this->graph(ltrim((string) $sessionId, '/')));

        $handle = $upload->json('h');

        if (! $upload->successful() || blank($handle)) {
            throw new \RuntimeException(
                'Téléversement de l\'exemple refusé : '.$this->describeFailure($upload)
            );
        }

        return (string) $handle;
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

    /**
     * Analytics de facturation du WABA — les montants RÉELS de Meta.
     *
     * Forme Graph (champ imbriqué, paramètres passés en appel de méthode et
     * non en query string) :
     *
     *   GET /{waba_id}?fields=pricing_analytics
     *       .start(<unix>).end(<unix>)
     *       .granularity(DAILY)
     *       .dimensions(['PRICING_CATEGORY'])
     *       .metric_types(['COST','VOLUME'])
     *
     * Chaque `data_point` porte `start`, `end`, `volume` (messages livrés),
     * `cost` (charges approximatives, devise du WABA) et la dimension
     * demandée — ici `pricing_category` : AUTHENTICATION, MARKETING, SERVICE,
     * UTILITY.
     *
     * Ce champ n'est pas garanti : il dépend de la version de l'API, des
     * permissions du jeton système et du type de compte. La méthode rend donc
     * un tableau VIDE plutôt que de lever quand Meta ne sait pas répondre —
     * l'absence d'analytics n'est pas une panne, c'est le cas nominal du
     * calcul local.
     *
     * @return array<int,array<string,mixed>> data_points, éventuellement vide
     *
     * @throws \RuntimeException si Meta répond une erreur explicite
     */
    public function pricingAnalytics(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $field = sprintf(
            "pricing_analytics.start(%d).end(%d).granularity(DAILY).dimensions(['PRICING_CATEGORY']).metric_types(['COST','VOLUME'])",
            $start->getTimestamp(),
            $end->getTimestamp(),
        );

        $response = $this->client()->get($this->graph((string) config('whatsapp.cloud.waba_id')), [
            'fields' => $field,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException($this->describeFailure($response));
        }

        return (array) $response->json('pricing_analytics.data_points', []);
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
