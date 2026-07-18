<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie la fonction serverless Vercel (scan CIN / repli passeport) sur la
 * route interne de tracking via un secret partage (en-tete
 * `Authorization: Bearer <secret>`). Aucune session Sanctum : ce n'est pas un
 * utilisateur, c'est un processus de confiance. Meme principe que
 * VerifyWhatsappWorker.
 */
class VerifyAiTrackingSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ai_tracking.secret');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'UNAUTHENTICATED', 'message' => 'Invalid tracking credentials.', 'field' => null]],
            ], 401);
        }

        return $next($request);
    }
}
