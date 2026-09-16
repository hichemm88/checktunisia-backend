<?php

namespace App\Http\Middleware;

use App\Exceptions\PartnerApi\PartnerApiException;
use App\Services\PartnerApi\ApiKeyService;
use App\Services\PartnerApi\ErrorCodes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie l'API publique v1 (§1) : Authorization: Bearer qyd_live_…/qyd_test_….
 * Aucune session Sanctum — c'est un serveur partenaire, pas un utilisateur.
 */
class VerifyPartnerApiKey
{
    public function __construct(private ApiKeyService $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keys->resolve($request->bearerToken());

        if (! $key) {
            throw new PartnerApiException(ErrorCodes::INVALID_API_KEY);
        }

        $request->attributes->set('api_key', $key);
        $request->attributes->set('api_partner', $key->partner);

        app()->instance(\App\Models\ApiKey::class, $key);
        app()->instance(\App\Models\ApiPartner::class, $key->partner);

        return $next($request);
    }
}
