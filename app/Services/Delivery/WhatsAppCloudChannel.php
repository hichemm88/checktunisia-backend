<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryChannel;
use App\Contracts\DeliveryResult;
use App\Models\Hotel;
use App\Models\WhatsappSendLog;
use App\Services\Whatsapp\FicheTemplate;
use App\Services\Whatsapp\WhatsappCloudApi;
use App\Services\Whatsapp\WhatsappCloudErrors;
use App\Services\Whatsapp\WhatsappSendingGuard;
use App\Services\Whatsapp\WhatsappTemplateStatus;

/**
 * Canal WhatsApp Cloud API (officiel, Meta Graph). CANAL ACTIF PAR DÉFAUT.
 *
 * Le relais WhatsApp Web non officiel a été banni par WhatsApp : il ne
 * transmet plus rien. Ce canal le remplace.
 *
 * Canal en PUSH : PHP appelle directement l'API. Plus de worker Node, plus de
 * session à appairer, plus de QR à renouveler — donc plus le point unique de
 * défaillance historique. En contrepartie, deux règles de Meta s'imposent :
 *
 *  1. Hors fenêtre de 24 h — le cas de TOUTES les fiches, personne ne répond à
 *     une fiche de police — seul un MODÈLE approuvé passe. D'où l'envoi par
 *     `sendTemplate()` et non par texte libre.
 *  2. Un modèle ne porte qu'un seul média. Ce média est le PDF de la fiche,
 *     pièce d'identité comprise : c'est ce qui rétablit la parité avec le
 *     relais WhatsApp Web, qui envoyait la photo du document. Le bouton
 *     « Consulter la fiche » ajoute le contexte que le PDF ne porte pas.
 *
 * Voir docs/canal-transmission.md.
 */
class WhatsAppCloudChannel implements DeliveryChannel
{
    public function __construct(
        private WhatsappCloudApi $api,
        private WhatsappSendingGuard $guard,
        private WhatsappTemplateStatus $templates,
    ) {}

    public function name(): string
    {
        return 'whatsapp_cloud';
    }

    public function isConfigured(): bool
    {
        return (bool) config('whatsapp.enabled') && $this->api->isConfigured();
    }

    public function supportsPush(): bool
    {
        return true;
    }

    /**
     * La Cloud API attend un numéro international NU, sans « + » ni suffixe —
     * là où WhatsApp Web exige un JID « …@c.us ». C'est précisément le genre
     * de détail qui aurait fuité partout sans cette interface.
     *
     * Conséquence utile : un JID hérité (« 21620123456@c.us ») posé en
     * configuration ou stocké sur une ligne enfilée avant la bascule se
     * ramène tout seul au bon format — les fiches en attente repartent sans
     * reprise de données.
     */
    public function formatRecipient(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        // Un numéro international plausible fait au moins 8 chiffres ; en deçà
        // c'est une saisie tronquée, que l'API rejetterait de toute façon.
        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    /**
     * Même règle de résolution que le canal historique — un envoi par agent
     * destinataire de l'établissement, repli sur le numéro global — mais
     * formatée pour ce canal. La règle métier ne dépend pas du transport.
     *
     * La liste vient de `hotel_whatsapp_recipients` (agents cochés dans
     * Établissement > Destinataires WhatsApp) ; elle n'a jamais été en dur.
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

        /*
         * Dernière barrière avant Meta.
         *
         * La boucle d'envoi consulte déjà les garde-fous, mais elle n'est pas
         * le seul chemin possible : un renvoi manuel depuis l'administration,
         * une commande lancée à la main, un futur appelant. Le contrôle est
         * donc ici aussi, au point de passage obligé — une fiche de l'arriéré
         * ne doit JAMAIS partir, quelle que soit la façon dont on la relance.
         */
        if ($this->guard->isPreCutover($job)) {
            return DeliveryResult::failedPermanently(
                'pre_cutover_backlog : fiche antérieure à la bascule Cloud API — envoi refusé.',
                'pre_cutover_backlog',
            );
        }

        if ($reason = $this->guard->blockingReason()) {
            // Temporaire : la fiche repartira au créneau suivant, rien n'est perdu.
            return DeliveryResult::failedTemporarily('Envoi suspendu — '.$reason);
        }

        $recipient = $this->formatRecipient($job->recipient);

        if ($recipient === null) {
            // Inutile de retenter : le numéro ne deviendra pas valide.
            return DeliveryResult::failedPermanently('Destinataire invalide : '.$job->recipient);
        }

        $params = $job->template_params ?: FicheTemplate::paramsForJob($job);

        if (empty($params)) {
            // Fiche non reconstituable (check-in ou voyageur supprimé depuis
            // l'enfilage). Envoyer un texte libre échouerait en 131047 hors
            // fenêtre de 24 h : autant le dire franchement plutôt que de
            // maquiller l'échec en erreur réseau.
            return DeliveryResult::failedPermanently(
                'Variables du modèle indisponibles : la fiche ne peut plus être reconstituée.'
            );
        }

        /*
         * En-tête DOCUMENT : le PDF de la fiche, pièce d'identité comprise.
         *
         * OBLIGATOIRE. Le modèle est approuvé avec un en-tête média ; un envoi
         * sans média est refusé (132000). Il n'y a donc pas de branche « sans
         * pièce jointe » : une fiche sans PDF n'est pas une fiche dégradée,
         * c'est un envoi impossible, et le dire tout de suite vaut mieux que
         * de le découvrir dans un code d'erreur Meta.
         */
        $pdf = FichePdf::forJob($job);

        if ($pdf === null) {
            return DeliveryResult::failedPermanently(
                'Fiche PDF impossible à produire : le modèle exige une pièce jointe.'
            );
        }

        try {
            $document = [
                'id' => $this->api->uploadMedia($pdf, FichePdf::filenameFor($job)),
                'filename' => FichePdf::filenameFor($job),
            ];
        } catch (TransientDeliveryFailure $e) {
            // Le téléversement a échoué passagèrement : la fiche est intacte,
            // seule cette tentative est perdue. Surtout PAS un échec définitif
            // — /media momentanément indisponible ne dit rien de la fiche.
            return DeliveryResult::failedTemporarily($e->getMessage());
        }

        $result = $this->api->sendTemplate(
            $recipient,
            $job->template_name ?: (string) config('whatsapp.cloud.template.name'),
            $job->template_language ?: (string) config('whatsapp.cloud.template.language'),
            FicheTemplate::components($params, $document),
        );

        if ($result->success) {
            // Comptabilisé APRÈS acceptation : un refus ne doit pas consommer
            // le budget de réputation d'un émetteur encore neuf.
            $this->guard->recordSend();

            return $result;
        }

        $code = is_numeric($result->errorCode) ? (int) $result->errorCode : null;

        if (WhatsappCloudErrors::isConfiguration($code)) {
            /*
             * Meta nous contredit sur le modèle : celui que nous croyions
             * utilisable ne l'est pas. Deux gestes, dans cet ordre.
             *
             * D'abord OUBLIER le statut mémorisé — c'est maintenant la donnée
             * la moins fiable dont nous disposions, et la garder quinze minutes
             * de plus, c'est réessayer quinze minutes de plus dans le vide.
             * La passe suivante ira redemander le vrai statut à Meta, et le
             * garde-fou d'approbation prendra le relais : plus une seule
             * tentative tant que le modèle n'est pas APPROVED.
             *
             * Ensuite SUSPENDRE le canal, plus longuement qu'une limitation de
             * débit : une approbation Meta se compte en heures, pas en quarts
             * d'heure, et rien ne changera d'ici là.
             */
            $this->templates->forget();

            $this->guard->pauseGlobally(
                (int) config('whatsapp.guard.config_pause_minutes', 60),
                'configuration du modèle — code Meta '.$result->errorCode,
            );

            return $result;
        }

        if (WhatsappCloudErrors::triggersGlobalPause($code)) {
            // Meta vient de dire « trop ». Insister est ce qui transforme une
            // limitation passagère en bannissement — on se tait un moment.
            $this->guard->pauseGlobally(null, 'code Meta '.$result->errorCode);
        }

        return $result;
    }
}
