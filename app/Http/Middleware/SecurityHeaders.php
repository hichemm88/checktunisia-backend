<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds conservative security response headers to every API response.
 *
 * Deliberately minimal for a JSON API: no CSP (the API returns JSON, not HTML,
 * so a CSP would add risk of breakage without benefit — the SPA host sets its
 * own CSP in vercel.json). These headers are safe defence-in-depth: they stop
 * MIME-sniffing, framing and referrer leakage, and strip the framework banner.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Seule exception : une réponse qui pose elle-même sa CSP
        // frame-ancestors (page du widget partenaire, autorisée à être
        // encadrée par les origines déclarées du partenaire). Y ajouter
        // X-Frame-Options: DENY contredirait cette politique.
        if (! str_contains((string) $response->headers->get('Content-Security-Policy'), 'frame-ancestors')) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
