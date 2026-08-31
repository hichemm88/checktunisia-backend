<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Déclare le webhook WhatsApp auprès de Meta, par l'API.
 *
 * Pourquoi pas par l'interface Meta : une configuration posée à la main dans
 * une console est invisible depuis le dépôt, impossible à rejouer sur un autre
 * environnement, et perdue le jour où elle est modifiée par inadvertance. Ici
 * elle est versionnée et rejouable.
 *
 * Deux abonnements distincts, souvent confondus — et c'est la confusion qui
 * fait qu'un webhook « configuré » ne reçoit rien :
 *
 *  1. L'APP s'abonne à un objet et déclare son URL de rappel. Jeton
 *     d'application (« {app_id}|{app_secret} »).
 *  2. Le COMPTE WhatsApp Business (WABA) s'abonne à l'app. Jeton d'utilisateur
 *     système. Sans cette seconde étape, l'URL est validée mais aucun
 *     événement du WABA n'y parvient.
 *
 * Prérequis : l'endpoint doit être DÉPLOYÉ et joignable publiquement avant de
 * lancer la commande — Meta appelle le GET de vérification pendant l'appel.
 *
 * Idempotente : Meta remplace l'abonnement existant sans créer de doublon.
 */
class ConfigureWhatsappWebhook extends Command
{
    protected $signature = 'whatsapp:configure-webhook
        {--check : Affiche seulement l\'état actuel, sans rien enregistrer}';

    protected $description = 'Enregistre et vérifie le webhook WhatsApp Cloud API auprès de Meta.';

    public function handle(): int
    {
        foreach (['app_id' => 'WHATSAPP_APP_ID', 'app_secret' => 'WHATSAPP_APP_SECRET', 'token' => 'WHATSAPP_API_TOKEN', 'waba_id' => 'WHATSAPP_WABA_ID', 'webhook_verify_token' => 'WHATSAPP_WEBHOOK_VERIFY_TOKEN'] as $key => $var) {
            if (blank(config('whatsapp.cloud.'.$key))) {
                $this->error("Configuration manquante : {$var}.");

                return self::FAILURE;
            }
        }

        $callback = (string) config('whatsapp.cloud.webhook_callback_url');

        $this->line('URL de rappel : '.$callback);
        $this->line('Meta appelle cette URL en GET pendant l\'enregistrement : elle doit être DÉPLOYÉE et joignable.');
        $this->newLine();

        if (! $this->option('check') && ! $this->registerAppSubscription($callback)) {
            return self::FAILURE;
        }

        if (! $this->option('check') && ! $this->subscribeWaba()) {
            return self::FAILURE;
        }

        $this->renderState();

        return self::SUCCESS;
    }

    /** Étape 1 — l'app déclare son URL et s'abonne aux événements du WABA. */
    private function registerAppSubscription(string $callback): bool
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post($this->graph(config('whatsapp.cloud.app_id').'/subscriptions'), [
                'object' => 'whatsapp_business_account',
                'callback_url' => $callback,
                'verify_token' => (string) config('whatsapp.cloud.webhook_verify_token'),
                'fields' => 'messages',
                'access_token' => $this->appAccessToken(),
            ]);

        if ($response->successful()) {
            $this->info('Abonnement de l\'app enregistré — le défi de vérification a été validé par Meta.');

            return true;
        }

        $this->error('Enregistrement refusé : '.$this->reason($response));
        $this->newLine();
        $this->line('Causes fréquentes, dans l\'ordre de probabilité :');
        $this->line('  • l\'URL n\'est pas encore déployée, ou répond autre chose que le hub.challenge brut ;');
        $this->line('  • WHATSAPP_WEBHOOK_VERIFY_TOKEN diffère entre cette commande et l\'application déployée ;');
        $this->line('  • l\'URL n\'est pas en HTTPS avec un certificat valide.');

        return false;
    }

    /** Étape 2 — le WABA s'abonne à l'app. Sans elle, aucun événement n'arrive. */
    private function subscribeWaba(): bool
    {
        $response = Http::withToken((string) config('whatsapp.cloud.token'))
            ->timeout(30)
            ->post($this->graph(config('whatsapp.cloud.waba_id').'/subscribed_apps'));

        if ($response->successful()) {
            $this->info('Compte WhatsApp Business abonné à l\'app.');

            return true;
        }

        $this->error('Abonnement du WABA refusé : '.$this->reason($response));

        return false;
    }

    /** Étape 3 — relire ce que Meta a réellement enregistré. */
    private function renderState(): void
    {
        $this->newLine();
        $this->line('── État chez Meta ────────────────────────────────');

        $subs = Http::timeout(30)->get($this->graph(config('whatsapp.cloud.app_id').'/subscriptions'), [
            'access_token' => $this->appAccessToken(),
        ]);

        if ($subs->successful()) {
            foreach ((array) $subs->json('data', []) as $sub) {
                $this->line('Objet : '.($sub['object'] ?? '?'));
                foreach ((array) ($sub['fields'] ?? []) as $field) {
                    $name = is_array($field) ? ($field['name'] ?? '?') : $field;
                    $this->line('  champ abonné : '.$name);
                }
                if (filled($sub['callback_url'] ?? null)) {
                    $this->line('  callback : '.$sub['callback_url']);
                }
            }
        } else {
            $this->warn('Lecture des abonnements de l\'app impossible : '.$this->reason($subs));
        }

        $apps = Http::withToken((string) config('whatsapp.cloud.token'))
            ->timeout(30)
            ->get($this->graph(config('whatsapp.cloud.waba_id').'/subscribed_apps'));

        if ($apps->successful()) {
            $list = (array) $apps->json('data', []);
            if (empty($list)) {
                $this->error('Aucune app abonnée au WABA : le webhook ne recevra RIEN.');
            }
            foreach ($list as $app) {
                $this->line('App abonnée au WABA : '.($app['whatsapp_business_api_data']['name'] ?? ($app['whatsapp_business_api_data']['id'] ?? '?')));
            }
        } else {
            $this->warn('Lecture des apps abonnées impossible : '.$this->reason($apps));
        }
    }

    /**
     * Jeton d'application, au format imposé par Meta.
     *
     * Il n'est jamais affiché ni journalisé : il équivaut au secret de l'app.
     */
    private function appAccessToken(): string
    {
        return config('whatsapp.cloud.app_id').'|'.config('whatsapp.cloud.app_secret');
    }

    private function graph(string $path): string
    {
        return sprintf(
            '%s/%s/%s',
            rtrim((string) config('whatsapp.cloud.base_url'), '/'),
            config('whatsapp.cloud.api_version'),
            $path,
        );
    }

    private function reason(Response $response): string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : 'HTTP '.$response->status();
    }
}
