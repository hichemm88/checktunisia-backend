<?php

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Services\PartnerApi\WidgetTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Page HTML du widget (§3). Servie par une vue Blade dédiée — et non le
 * index.html statique du SPA — car la CSP frame-ancestors doit varier par
 * partenaire, ce qu'un fichier statique ne peut pas faire (voir
 * API-V1-DECISIONS.md).
 */
class WidgetShellController extends Controller
{
    public function __construct(private WidgetTokenService $tokens) {}

    public function show(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        // Décodage "à blanc" : ne consomme rien (le usage unique réel est
        // appliqué au bootstrap JSON), sert seulement à connaître le
        // partenaire pour poser la bonne CSP sur CETTE page HTML.
        $partner = null;
        try {
            $claims = $this->tokens->decode($token);
            $partner = ApiPartner::find($claims->partner_id);
        } catch (\Throwable) {
            // Jeton invalide/expiré : la page se charge quand même, le widget
            // React affichera l'erreur proprement via le bootstrap JSON.
        }

        $origins = $partner?->allowed_widget_origins ?: [];
        $directive = $origins === [] ? "'none'" : "'self' ".implode(' ', $origins);

        // APP_DEBUG=true en production (constaté) : sans ce garde, une
        // exception ici (vue Blade cassée, etc.) afficherait la page de
        // débogage Laravel — trace complète, chemins serveur — À L'INTÉRIEUR
        // de l'iframe d'un partenaire. `report()` maintient la remontée vers
        // Sentry/les logs malgré le catch ; la CSP reste posée sur la réponse
        // de repli, qui ne révèle rien de l'erreur réelle.
        try {
            return response()
                ->view('widget-shell', ['token' => $token])
                ->header('Content-Security-Policy', "frame-ancestors {$directive}");
        } catch (\Throwable $e) {
            report($e);

            return response('', 500)->header('Content-Security-Policy', "frame-ancestors {$directive}");
        }
    }
}
