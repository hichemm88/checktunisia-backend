<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Liste les modèles du compte WhatsApp Business et leur état d'approbation.
 *
 * L'approbation d'un modèle est le seul délai de la bascule que nous ne
 * contrôlons pas, et c'est aussi la cause d'échec la plus opaque : l'API
 * renvoie « #132001 Template name does not exist in the translation » aussi
 * bien pour un modèle absent que pour un modèle existant dans une AUTRE langue
 * que celle demandée. Voir la liste réelle, avec nom, langue et statut, tranche
 * la question en une commande — au lieu d'un aller-retour dans le Gestionnaire
 * WhatsApp à chaque vérification.
 *
 * La confrontation avec la configuration locale est faite ici, parce que c'est
 * l'écart entre les deux qui casse les envois, pas l'état pris isolément.
 */
class ShowWhatsappTemplates extends Command
{
    /**
     * Le compte est surchargeable en argument : on en exploite plusieurs à la
     * fois pendant la bascule — celui du numéro de test et celui de production,
     * encore en revue. Un modèle créé sur le mauvais compte est invisible du
     * numéro qui émet, et l'erreur d'envoi est alors la même que s'il n'existait
     * pas du tout. Pouvoir interroger l'autre compte tranche la question sans
     * toucher aux variables d'environnement.
     */
    protected $signature = 'whatsapp:cloud-templates
        {waba? : Compte WhatsApp Business à interroger. Défaut : WHATSAPP_CLOUD_WABA_ID}';

    protected $description = 'Liste les modèles WhatsApp Cloud et leur état d\'approbation';

    public function handle(): int
    {
        $waba = (string) ($this->argument('waba') ?: config('whatsapp.cloud.waba_id'));
        $token = (string) config('whatsapp.cloud.token');

        if ($waba === '' || $token === '') {
            $this->error('WHATSAPP_CLOUD_WABA_ID ou WHATSAPP_CLOUD_TOKEN absent.');

            return self::FAILURE;
        }

        $url = sprintf(
            '%s/%s/%s/message_templates',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            $waba,
        );

        $response = Http::withToken($token)
            ->timeout((int) config('whatsapp.cloud.timeout', 30))
            ->get($url, ['fields' => 'name,language,status,category,rejected_reason', 'limit' => 100]);

        if (!$response->successful()) {
            // Rendu verbatim : c'est le message de Meta qui indique quoi corriger.
            $this->error('Meta : '.(string) ($response->json('error.message') ?? 'HTTP '.$response->status()));

            return self::FAILURE;
        }

        $templates = $response->json('data') ?? [];

        if ($templates === []) {
            $this->warn('Aucun modèle sur ce compte. Créez-le dans le Gestionnaire WhatsApp.');

            return self::SUCCESS;
        }

        $this->table(
            ['Nom', 'Langue', 'Catégorie', 'Statut', 'Motif de rejet'],
            array_map(fn (array $t) => [
                $t['name'] ?? '—',
                $t['language'] ?? '—',
                $t['category'] ?? '—',
                $t['status'] ?? '—',
                $t['rejected_reason'] ?? '',
            ], $templates),
        );

        $this->verdict($templates);

        return self::SUCCESS;
    }

    /**
     * Dit si la configuration locale correspond à un modèle réellement
     * utilisable. Le couple NOM + LANGUE doit correspondre exactement : un
     * modèle approuvé en anglais alors que la config demande « fr » échoue avec
     * la même erreur qu'un modèle inexistant.
     *
     * @param  array<int,array<string,mixed>>  $templates
     */
    private function verdict(array $templates): void
    {
        $wanted = (string) config('whatsapp.cloud.template_name');
        $lang = (string) config('whatsapp.cloud.template_language', 'fr');

        $this->newLine();

        if ($wanted === '') {
            $this->comment('WHATSAPP_CLOUD_TEMPLATE non défini : les envois partiraient en texte libre (fenêtre de 24 h uniquement).');

            return;
        }

        foreach ($templates as $t) {
            if (($t['name'] ?? null) !== $wanted || ($t['language'] ?? null) !== $lang) {
                continue;
            }

            if (($t['status'] ?? null) === 'APPROVED') {
                $this->info("« {$wanted} » ({$lang}) est approuvé — les envois peuvent partir.");
            } else {
                $this->warn("« {$wanted} » ({$lang}) existe mais son statut est ".($t['status'] ?? '?').'.');
            }

            return;
        }

        // Le cas qui coûte le plus de temps : le modèle est là, mais pas dans
        // la langue demandée. L'API ne distingue pas ce cas de l'absence.
        $sameName = array_filter($templates, fn (array $t) => ($t['name'] ?? null) === $wanted);

        if ($sameName !== []) {
            $langs = implode(', ', array_map(fn (array $t) => (string) $t['language'], $sameName));
            $this->error("« {$wanted} » existe en [{$langs}] mais PAS en « {$lang} » — c'est ce que dit l'erreur #132001.");
            $this->line('Corrigez WHATSAPP_CLOUD_TEMPLATE_LANG, ou créez la traduction manquante.');

            return;
        }

        $this->error("Aucun modèle nommé « {$wanted} » sur ce compte.");
    }
}
