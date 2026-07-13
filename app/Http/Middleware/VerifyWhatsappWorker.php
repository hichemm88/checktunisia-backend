<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Authentifie le service Node (worker WhatsApp) sur les routes internes via un
 * secret partagé (en-tête X-Whatsapp-Worker-Secret). Aucune session Sanctum :
 * ce n'est pas un utilisateur, c'est un processus de confiance.
 */
class VerifyWhatsappWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('whatsapp.worker_secret');
        $provided = (string) $request->header('X-Whatsapp-Worker-Secret', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Invalid worker credentials.', 'field' => null]],
            ], 401);
        }

        return $next($request);
    }
}
