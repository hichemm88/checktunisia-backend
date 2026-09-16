<?php

namespace App\Http\Middleware;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Services\PartnerApi\ErrorCodes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite de débit ET en-têtes X-RateLimit-* (§2) en une seule passe : évite de
 * dupliquer le compteur entre `throttle:partner-api` et un middleware
 * d'affichage séparé, qui dériveraient l'un de l'autre.
 *
 * Indexé sur la clé API (pas l'IP) : un partenaire peut être derrière une IP
 * partagée, une limite par IP punirait tout le monde derrière elle.
 */
class AddRateLimitHeaders
{
    public function handle(Request $request, Closure $next, int $maxAttempts = 120, int $decaySeconds = 60): Response
    {
        $key = 'partner-api:'.($request->attributes->get('api_key')?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);
            $exception = new PartnerApiException(ErrorCodes::RATE_LIMITED);

            $response = response()->json($exception->toResponseArray(), $exception->status());
            $response->headers->set('Retry-After', (string) $retryAfter);

            return $this->withHeaders($response, $key, $maxAttempts);
        }

        RateLimiter::hit($key, $decaySeconds);

        $response = $next($request);

        return $this->withHeaders($response, $key, $maxAttempts);
    }

    private function withHeaders(Response $response, string $key, int $maxAttempts): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($key, $maxAttempts)));
        $response->headers->set('X-RateLimit-Reset', (string) (time() + RateLimiter::availableIn($key)));

        return $response;
    }
}
