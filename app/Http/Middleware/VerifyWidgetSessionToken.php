<?php

namespace App\Http\Middleware;

use App\Models\ApiPartner;
use App\Models\FicheSession;
use App\Services\PartnerApi\PartnerIntegrationActor;
use App\Services\PartnerApi\WidgetSessionService;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie les appels du widget APRÈS le bootstrap (ajout/retrait de
 * voyageur, scan, soumission) via le token de session opaque — jamais le JWT
 * d'origine, déjà consommé à ce stade.
 */
class VerifyWidgetSessionToken
{
    public function __construct(private WidgetSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->resolve((string) $request->bearerToken());

        app()->instance(FicheSession::class, $session);
        app()->instance(ApiPartner::class, $session->partner);
        $request->attributes->set('fiche_session', $session);

        // Tout ce que la saisie déclenche (journal d'audit, created_by des
        // voyageurs, statistiques) est attribué à l'utilisateur pour le compte
        // duquel la session agit : le réceptionniste désigné par le partenaire,
        // sinon l'utilisateur d'intégration — jamais à « personne ».
        Auth::setUser(app(PartnerIntegrationActor::class)->forSession($session));

        return $next($request);
    }
}
