<?php

namespace App\Http\Middleware;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Models\ApiPartner;
use App\Services\PartnerApi\ErrorCodes;
use App\Services\PartnerApi\RequestOrigin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Défense en profondeur pour le widget (§3/§8) : la CSP frame-ancestors n'est
 * appliquée QUE par le navigateur — ce middleware rejette aussi côté serveur
 * un appel JSON dont l'Origin/Referer ne correspond à aucune origine
 * autorisée pour ce partenaire, et pose l'en-tête CSP sur toute réponse HTML.
 */
class PartnerWidgetFrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ApiPartner|null $partner */
        $partner = app()->bound(ApiPartner::class) ? app(ApiPartner::class) : null;

        $origin = RequestOrigin::of($request);

        if ($partner && $origin !== null && ! $partner->allowsOrigin($origin)) {
            throw new PartnerApiException(ErrorCodes::FRAME_DISALLOWED);
        }

        $response = $next($request);

        if ($partner) {
            $origins = $partner->allowed_widget_origins ?: [];
            $directive = $origins === [] ? "'none'" : "'self' ".implode(' ', $origins);
            $response->headers->set('Content-Security-Policy', "frame-ancestors {$directive}");
        }

        return $response;
    }
}
