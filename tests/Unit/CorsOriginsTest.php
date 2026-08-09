<?php

namespace Tests\Unit;

use App\Support\CorsOrigins;
use PHPUnit\Framework\TestCase;

/**
 * Origines autorisées : la variable d'environnement AJOUTE, elle ne REMPLACE
 * jamais.
 *
 * `CORS_ALLOWED_ORIGINS` existait en production sans être lue nulle part, et
 * l'audit a conclu un peu vite qu'il fallait en faire la source de vérité.
 * Sa valeur réelle ne contenait QUE l'ancien domaine Vercel : la lire seule
 * aurait bloqué toutes les requêtes venant de www.qayed.tn, c'est-à-dire
 * éteint l'application entière.
 *
 * D'où la règle retenue : le socle du dépôt est inconditionnel, la variable
 * ne peut qu'ajouter des origines, et chaque ajout doit être une origine
 * absolue explicite. Une variable mal renseignée reste ainsi sans effet —
 * jamais une panne, jamais une ouverture.
 */
class CorsOriginsTest extends TestCase
{
    public function test_the_repository_baseline_holds_without_any_variable(): void
    {
        foreach ([null, '', '   '] as $absent) {
            $origins = CorsOrigins::resolve($absent, 'production');

            $this->assertContains('https://www.qayed.tn', $origins);
            $this->assertContains('https://qayed.tn', $origins);
        }
    }

    /**
     * LA régression à empêcher : la valeur réellement posée en production ne
     * mentionne pas le domaine de production.
     */
    public function test_a_partial_variable_can_never_drop_the_production_frontend(): void
    {
        $origins = CorsOrigins::resolve('https://checktunisia.vercel.app', 'production');

        $this->assertContains('https://www.qayed.tn', $origins);
        $this->assertContains('https://checktunisia.vercel.app', $origins);
    }

    public function test_the_variable_can_add_a_new_domain(): void
    {
        $origins = CorsOrigins::resolve('https://app.qayed.tn, https://staging.qayed.tn', 'production');

        $this->assertContains('https://app.qayed.tn', $origins);
        $this->assertContains('https://staging.qayed.tn', $origins);
        $this->assertContains('https://www.qayed.tn', $origins);
    }

    public function test_a_wildcard_is_never_honoured(): void
    {
        foreach (['*', 'https://*', 'https://*.qayed.tn', '*.qayed.tn'] as $wildcard) {
            $origins = CorsOrigins::resolve($wildcard, 'production');

            $this->assertNotContains('*', $origins);
            $this->assertSame(CorsOrigins::BUILT_IN, $origins, "« {$wildcard} » ne doit rien ajouter");
        }
    }

    public function test_anything_that_is_not_a_bare_origin_is_ignored(): void
    {
        $rejected = [
            'https://evil.tn/callback',   // chemin
            'https://evil.tn?x=1',        // requête
            'qayed.tn',                   // pas de schéma
            'ftp://qayed.tn',             // schéma non web
            'null',
            'https://',
        ];

        foreach ($rejected as $value) {
            $this->assertSame(
                CorsOrigins::BUILT_IN,
                CorsOrigins::resolve($value, 'production'),
                "« {$value} » ne doit rien ajouter",
            );
        }
    }

    public function test_production_never_accepts_a_non_tls_origin(): void
    {
        $origins = CorsOrigins::resolve('http://qayed.tn, http://localhost:4173', 'production');

        $this->assertSame(CorsOrigins::BUILT_IN, $origins);
    }

    public function test_outside_production_the_local_preview_is_allowed(): void
    {
        $origins = CorsOrigins::resolve(null, 'local');

        $this->assertContains('http://localhost:4173', $origins);
    }

    public function test_the_local_preview_is_never_allowed_in_production(): void
    {
        $this->assertNotContains('http://localhost:4173', CorsOrigins::resolve(null, 'production'));
    }

    public function test_a_repeated_origin_is_listed_once(): void
    {
        $origins = CorsOrigins::resolve('https://www.qayed.tn, https://www.qayed.tn', 'production');

        $this->assertSame(1, count(array_keys($origins, 'https://www.qayed.tn', true)));
        // La liste reste une liste indexée : `array_unique` laisse des trous.
        $this->assertSame(array_values($origins), $origins);
    }
}
