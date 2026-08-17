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

            if (!empty($numbers)) {
                return $numbers;
            }
        }

        return $global !== null ? [$global] : [];
    }

    public function send(WhatsappSendLog $job): DeliveryResult
    {
        if (!$this->isConfigured()) {
            return DeliveryResult::failedPermanently('Canal Cloud API non configuré.');
        }

        $recipient = $this->formatRecipient($job->recipient);

        if ($recipient === null) {
            // Inutile de retenter : le numéro ne deviendra pas valide.
            return DeliveryResult::failedPermanently('Destinataire invalide : '.$job->recipient);
        }

        try {
            $payload = $this->buildPayload($job, $recipient);
        } catch (TransientDeliveryFailure $e) {
            // Le téléversement de la pièce a échoué passagèrement : la fiche est
            // intacte, seule cette tentative est perdue.
            return DeliveryResult::failedTemporarily($e->getMessage());
        }

        try {
            $response = Http::withToken((string) config('whatsapp.cloud.token'))
                ->timeout((int) config('whatsapp.cloud.timeout', 30))
                ->post($this->endpoint('messages'), $payload);
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

    /** URL Graph d'une ressource du numéro émetteur (« messages », « media »). */
    private function endpoint(string $resource): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            config('whatsapp.cloud.phone_number_id'),
            $resource,
        );
    }

    /**
     * Corps de la requête d'envoi : modèle avec fiche en pièce jointe, ou
     * texte libre en repli.
     *
     * ── Pourquoi un modèle ───────────────────────────────────────────────────
     *
     * Hors de la fenêtre de 24 h suivant un message du destinataire, la Cloud
     * API refuse le texte libre (erreur 131047). Nos destinataires ne répondent
     * jamais — le module ignore tout message entrant, par exigence de sécurité.
     * Autrement dit : en production, TOUS nos envois sont hors fenêtre. Le
     * repli texte n'existe que pour les essais avec le numéro de test, une fois
     * la fenêtre ouverte à la main.
     *
     * ── Pourquoi la fiche part en pièce jointe ───────────────────────────────
     *
     * Une variable de modèle ne peut contenir ni retour à la ligne, ni
     * tabulation, ni plus de quatre espaces consécutifs. La fiche est
     * multi-ligne : aucune variable ne peut l'accueillir. Elle part donc en PDF
     * dans l'en-tête « document », ce qui ramène du même coup la photo de la
     * pièce d'identité — que ce canal ne savait pas transmettre.
     *
     * @return array<string,mixed>
     *
     * @throws TransientDeliveryFailure si la pièce ne peut pas être téléversée
     */
    private function buildPayload(WhatsappSendLog $job, string $recipient): array
    {
        $base = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $recipient];
        $template = (string) config('whatsapp.cloud.template_name');

        if ($template === '') {
            return $base + [
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => (string) $job->caption],
            ];
        }

        $components = [];

        // Un job de test n'a pas de check-in : pas de PDF, mais le modèle doit
        // rester envoyable — c'est précisément ce qui sert à valider la chaîne.
        $pdf = FichePdf::forJob($job);
        if ($pdf !== null) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => [
                        'id' => $this->uploadMedia($pdf, FichePdf::filenameFor($job)),
                        'filename' => FichePdf::filenameFor($job),
                    ],
                ]],
            ];
        }

        $components[] = [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => $this->templateParam($job->hotel?->name ?? 'Établissement')],
                ['type' => 'text', 'text' => $this->templateParam($this->guestLabel($job))],
            ],
        ];

        return $base + [
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => (string) config('whatsapp.cloud.template_language', 'fr')],
                'components' => $components,
            ],
        ];
    }

    /**
     * Assainit une valeur destinée à une variable de modèle.
     *
     * Meta rejette le message entier — pas seulement la variable — si un
     * paramètre contient un saut de ligne, une tabulation ou plus de quatre
     * espaces consécutifs. Un simple nom d'établissement collé depuis un
     * tableur suffit à faire tomber l'envoi ; mieux vaut le normaliser ici que
     * découvrir la règle en production.
     */
    private function templateParam(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', $value);

        return mb_substr(trim((string) $flat), 0, 200) ?: '—';
    }

    private function guestLabel(WhatsappSendLog $job): string
    {
        $guest = $job->guest;

        if (!$guest) {
            return 'Fiche';
        }

        return trim(mb_strtoupper((string) $guest->last_name).' '.(string) $guest->first_name) ?: 'Fiche';
    }

    /**
     * Téléverse la fiche PDF et renvoie son identifiant média.
     *
     * La Cloud API n'accepte pas de pièce jointe en ligne : il faut un appel
     * préalable à /media, dont l'identifiant est valable 30 jours. On ne met
     * rien en cache — une fiche n'est envoyée qu'une fois, et un identifiant
     * périmé coûterait plus cher à diagnostiquer que le téléversement à refaire.
     *
     * @throws TransientDeliveryFailure
     */
    private function uploadMedia(string $pdf, string $filename): string
    {
        try {
            $response = Http::withToken((string) config('whatsapp.cloud.token'))
                ->timeout((int) config('whatsapp.cloud.timeout', 30))
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post($this->endpoint('media'), ['messaging_product' => 'whatsapp']);
        } catch (\Throwable $e) {
            throw new TransientDeliveryFailure('Téléversement de la fiche impossible : '.$e->getMessage());
        }

        $id = $response->successful() ? $response->json('id') : null;

        if (!$id) {
            throw new TransientDeliveryFailure(
                'Téléversement de la fiche refusé : '
                .(string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        return (string) $id;
    }
}
