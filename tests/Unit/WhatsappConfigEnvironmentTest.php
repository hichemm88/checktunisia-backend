<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La configuration WhatsApp ne doit rien devoir à l'environnement.
 *
 * ── Pourquoi ce test existe ──────────────────────────────────────────────────
 *
 * La suite était verte en local et rouge en CI, et l'écart n'était pas dans le
 * code : la CI fait `cp .env.example .env`, or ce fichier documente chaque
 * réglage optionnel par une ligne « X= ». Une variable qui EXISTE avec une
 * valeur vide n'est pas une variable absente — `env('X', 'defaut')` rend alors
 * la chaîne vide, et le défaut est perdu.
 *
 * Concrètement : `WHATSAPP_API_BASE_URL=` effaçait `https://graph.facebook.com`,
 * toutes les URL Graph devenaient « », et chaque appel mourait sur « URI must
 * include a scheme and host ». Le canal légal du produit, en panne totale, sans
 * qu'une seule ligne de code applicatif soit en cause.
 *
 * Le même piège attend en production : laisser une variable vide dans Railway
 * ne « prend pas le défaut », cela l'efface.
 *
 * ── Ce que ce test verrouille ────────────────────────────────────────────────
 *
 * Il recharge le fichier de configuration avec TOUTES les variables WhatsApp
 * posées à vide — la situation exacte de la CI — et exige que chaque valeur
 * ayant un défaut reste utilisable. Il échouera à la première option ajoutée
 * avec `env()` au lieu du helper, avant que la CI ne l'apprenne à quelqu'un.
 *
 * Volontairement en test unitaire pur (TestCase de PHPUnit) : il manipule
 * l'environnement du processus, il n'a aucune raison de démarrer Laravel ni de
 * toucher la base.
 */
class WhatsappConfigEnvironmentTest extends TestCase
{
    /**
     * Réglages qui DOIVENT survivre à une variable vide, avec ce qu'on attend.
     *
     * Les secrets n'y figurent pas : eux n'ont pas de défaut, et leur absence
     * doit rester détectée (voir WhatsappCloudConfig).
     *
     * @var array<string,mixed>
     */
    private const DEFAULTS_THAT_MUST_HOLD = [
        'cloud.base_url' => 'https://graph.facebook.com',
        'cloud.api_version' => 'v21.0',
        'cloud.timeout' => 30,
        'cloud.template.name' => 'fiche_police_nouvelle',
        'cloud.template.language' => 'fr',
        'channel' => 'cloud',
        'guard.max_sends_per_minute' => 20,
        'guard.max_sends_per_day' => 500,
        'guard.backlog_alert_threshold' => 50,
        'guard.quality_pause_minutes' => 15,
        'max_per_hour' => 30,
        'min_interval_seconds' => 45,
        'warmup_hours' => 24,
        'warmup_max_per_hour' => 6,
        'warmup_min_interval_seconds' => 120,
        'circuit_breaker_failures' => 5,
        'max_age_minutes' => 1440,
    ];

    /** @var array<string,string|false> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function test_every_default_survives_an_empty_environment_variable(): void
    {
        $config = $this->configWithBlankEnvironment();

        foreach (self::DEFAULTS_THAT_MUST_HOLD as $path => $expected) {
            $actual = data_get($config, $path);

            $this->assertSame(
                $expected,
                $actual,
                "config('whatsapp.{$path}') vaut « ".var_export($actual, true)." » quand la variable "
                ."correspondante est vide. Utiliser le helper \$envOr de config/whatsapp.php plutôt "
                .'que env() : une variable vide écrase le défaut.'
            );
        }
    }

    public function test_urls_built_from_defaults_are_absolute(): void
    {
        $config = $this->configWithBlankEnvironment();

        // C'est la panne réellement constatée : une base d'URL vide produit des
        // adresses relatives que Guzzle refuse, sur TOUS les appels Meta.
        foreach ([
            'cloud.base_url',
            'cloud.template.fiche_url_base',
            'cloud.webhook_callback_url',
        ] as $path) {
            $this->assertMatchesRegularExpression(
                '#^https?://#',
                (string) data_get($config, $path),
                "config('whatsapp.{$path}') n'est pas une URL absolue avec un environnement vide."
            );
        }
    }

    /**
     * Recharge config/whatsapp.php avec toutes les variables WhatsApp — et
     * APP_URL, dont deux URL dérivent — posées à la chaîne vide.
     *
     * @return array<string,mixed>
     */
    private function configWithBlankEnvironment(): array
    {
        $path = dirname(__DIR__, 2).'/config/whatsapp.php';

        $variables = [];
        foreach (preg_split('/\R/', (string) file_get_contents($path)) as $line) {
            if (preg_match_all("/env\('(WHATSAPP_[A-Z0-9_]+)'/", $line, $matches)) {
                $variables = array_merge($variables, $matches[1]);
            }
        }
        $variables[] = 'APP_URL';

        foreach (array_unique($variables) as $key) {
            $this->saved[$key] = getenv($key);
            putenv("{$key}=");
            $_ENV[$key] = '';
            $_SERVER[$key] = '';
        }

        // Le fichier appelle env(), qui lit le dépôt de variables : le recharger
        // ici suffit, sans démarrer l'application.
        return require $path;
    }
}
