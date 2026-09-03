<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Une requête non authentifiée doit recevoir un 401 JSON — quel que soit
 * l'en-tête `Accept` qu'elle envoie, ou son absence.
 *
 * ── Ce qui n'allait pas ─────────────────────────────────────────────────
 *
 * Cette application n'a AUCUNE route web nommée « login » : pas de
 * `routes/web.php`, tout est API. Sans `redirectGuestsTo(fn () => null)`,
 * un appelant qui n'envoie pas `Accept: application/json` — un `curl` brut,
 * un ancien client, une sonde de santé, un webhook mal formé — faisait
 * échouer `auth:sanctum` par une `AuthenticationException` que Laravel
 * tentait de rendre en REDIRECTION plutôt qu'en JSON. La redirection visait
 * `route('login')`, qui n'existe pas : `RouteNotFoundException`, et
 * l'appelant recevait une page d'erreur 500 — avec la trace complète tant
 * qu'`APP_DEBUG=true` — au lieu du 401 structuré que l'API promet partout
 * ailleurs.
 *
 * Le frontend n'était jamais exposé : `src/lib/api.ts` fixe
 * `Accept: application/json` sur chaque requête. C'est tout appelant qui ne
 * le fait pas qui recevait une page cassée plutôt qu'une erreur exploitable
 * — et, en debug, une fuite d'informations (chemins de fichiers, version du
 * framework).
 *
 * ── Pourquoi ce test appelle une VRAIE route protégée ────────────────────
 *
 * Simuler l'exception directement contournerait le pipeline de middleware —
 * précisément l'endroit où le bug se produisait. Le test passe donc par une
 * route authentifiée réelle, sans jouer `actingAs()`.
 */
class UnauthenticatedApiResponseTest extends TestCase
{
    public function test_an_unauthenticated_request_without_an_accept_header_still_gets_clean_json(): void
    {
        $response = $this->call('GET', '/api/v1/admin/whatsapp/inbox');

        $response->assertStatus(401);
        $response->assertJson([
            'data' => null,
            'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Unauthenticated.', 'field' => null]],
        ]);
    }

    public function test_an_unauthenticated_request_with_an_invalid_bearer_token_gets_the_same_clean_json(): void
    {
        $response = $this->call('GET', '/api/v1/admin/whatsapp/inbox', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token-xyz',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    }

    public function test_the_json_expecting_request_keeps_working_as_before(): void
    {
        // Non-régression : c'est le chemin qu'emprunte réellement le frontend
        // (Accept: application/json posé sur chaque requête), il ne doit pas
        // changer de comportement.
        $response = $this->getJson('/api/v1/admin/whatsapp/inbox');

        $response->assertStatus(401);
        $response->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    }
}
