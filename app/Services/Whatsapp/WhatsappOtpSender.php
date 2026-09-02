<?php

namespace App\Services\Whatsapp;

use App\Contracts\DeliveryResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Envoi des codes de connexion — file séparée de celle des fiches.
 *
 * ── Pourquoi ne PAS passer par WhatsappSendingGuard ──────────────────────
 *
 * Les garde-fous des fiches sont taillés pour un flux de données personnelles
 * poussé vers des gens qui n'ont rien demandé : arriéré bloquant, plafond
 * quotidien, disjoncteur, bascule armée à la main. Chacun d'eux répond à
 * l'incident qui a coûté le numéro précédent.
 *
 * Un code de connexion est l'exact opposé : il est DEMANDÉ, à l'instant, par
 * la personne qui le reçoit, et il ne vaut rien cinq minutes plus tard. Le
 * faire passer par ces freins aurait deux effets, tous deux mauvais :
 *
 *  - un arriéré de fiches — donc une panne d'envoi — fermerait aussi la porte
 *    du portail, au moment précis où un agent cherche à consulter une fiche
 *    qu'il n'a pas reçue ;
 *  - inversement, un afflux de connexions consommerait le quota quotidien des
 *    fiches, qui est une obligation légale.
 *
 * Deux flux, deux budgets. Ce que l'OTP conserve, ce sont les deux protections
 * qui n'ont rien à voir avec le débit des fiches :
 *
 *  1. le COUPE-CIRCUIT global (WHATSAPP_SENDING_ENABLED) — quand il est baissé,
 *     plus rien ne part vers Meta, codes compris ;
 *  2. un PLAFOND HORAIRE propre (WHATSAPP_OTP_MAX_PER_HOUR), sans lequel cette
 *     file serait le seul chemin non borné vers Meta — donc la première que
 *     viserait quelqu'un cherchant à faire sanctionner le numéro émetteur.
 *
 * L'envoi est SYNCHRONE : un code utile cinq minutes ne peut pas attendre le
 * prochain passage d'un ordonnanceur.
 */
class WhatsappOtpSender
{
    public function __construct(
        private WhatsappCloudApi $api,
        private WhatsappCostRecorder $costs,
    ) {}

    /**
     * Envoie un code. Le code en clair ne sort d'ici que vers Meta : il n'est
     * ni journalisé, ni renvoyé, ni conservé.
     *
     * @param  string  $to  numéro international nu (« 21620123456 »)
     */
    public function send(string $to, string $code): DeliveryResult
    {
        if (! config('whatsapp.guard.sending_enabled', true)) {
            // Même formulation que pour les fiches : le coupe-circuit est un
            // geste d'exploitation, il doit se lire pareil partout.
            return DeliveryResult::failedTemporarily(
                'Coupe-circuit actif (WHATSAPP_SENDING_ENABLED=false).'
            );
        }

        if (! $this->hourlyBudgetAvailable()) {
            Log::warning('[whatsapp-otp] plafond horaire atteint', [
                'max_per_hour' => $this->hourlyCap(),
            ]);

            return DeliveryResult::failedTemporarily('Plafond horaire des codes atteint.');
        }

        $result = $this->api->sendTemplate(
            $to,
            (string) config('whatsapp.cloud.template.otp_name'),
            (string) config('whatsapp.cloud.template.otp_language'),
            $this->components($code),
        );

        if ($result->success) {
            $this->recordSend();

            /*
             * Registre de facturation, catégorie AUTHENTICATION.
             *
             * Les codes n'ont pas de ligne d'outbox — ils partent hors file —
             * donc rien d'autre ne saurait relier le wamid que rendra le
             * webhook à une catégorie de prix. Sans cet appel, les connexions
             * seraient le seul poste de dépense Meta invisible, et il monte
             * avec le nombre d'agents.
             */
            $this->costs->registerOtpSend($result->messageId);
        }

        return $result;
    }

    /**
     * Composants d'un modèle de catégorie AUTHENTICATION.
     *
     * Le code apparaît DEUX fois : une dans le corps (le texte que lit l'agent)
     * et une sur le bouton (ce que « Copier le code » place dans le
     * presse-papiers). Ce ne sont pas deux paramètres différents mais deux
     * emplacements du même, et Meta refuse le message si l'un des deux manque.
     *
     * `sub_type: url` sur un bouton COPY_CODE n'est pas une erreur : c'est la
     * forme qu'impose la Cloud API pour les boutons de modèle
     * d'authentification, quel que soit leur type déclaré.
     *
     * @return array<int,array<string,mixed>>
     */
    private function components(string $code): array
    {
        $parameter = [['type' => 'text', 'text' => $code]];

        return [
            ['type' => 'body', 'parameters' => $parameter],
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => $parameter],
        ];
    }

    private function hourlyCap(): int
    {
        return (int) config('whatsapp.otp.max_per_hour', 100);
    }

    private function hourlyBudgetAvailable(): bool
    {
        $max = $this->hourlyCap();

        return $max <= 0 || (int) Cache::get($this->hourKey(), 0) < $max;
    }

    private function recordSend(): void
    {
        $key = $this->hourKey();

        // `Cache::increment` ne crée pas la clé sur tous les pilotes : `add`
        // puis `increment` couvre les deux cas (même motif que le garde-fou
        // des fiches).
        if (! Cache::add($key, 1, now()->addHours(2))) {
            Cache::increment($key);
        }
    }

    private function hourKey(): string
    {
        return 'whatsapp:otp:sends:'.now()->format('YmdH');
    }
}
