<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionIssuer;
use App\Services\Auth\WhatsappOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connexion du portail autorité par code reçu sur WhatsApp.
 *
 * Troisième chemin, à côté du mot de passe (+ 2FA) et des passkeys. Il ne
 * remplace ni ne modifie les deux autres : il existe pour les agents dont
 * l'adresse e-mail est fictive et qui, de ce fait, ne peuvent ni activer un
 * mot de passe ni recevoir un lien.
 *
 * ── Ce contrôleur ne décide de rien ─────────────────────────────────────
 *
 * Éligibilité, génération, envoi, verrouillage : tout est dans
 * WhatsappOtpService. Ici il n'y a que la forme HTTP, et une règle qui doit
 * rester lisible d'un coup d'œil : les deux endpoints répondent la MÊME chose,
 * quoi qu'il arrive. Une seule branche d'exception ajoutée par mégarde — un
 * 404 « numéro inconnu », un 429 qui n'arrive que pour les numéros réels —
 * transformerait cette route publique en annuaire des agents de police.
 */
class WhatsappOtpController extends Controller
{
    public function __construct(private WhatsappOtpService $otp) {}

    /**
     * POST /auth/otp/request — demande d'un code.
     *
     * 202 « accepté » et non 200 : rien n'est promis. Ni que le numéro est
     * connu, ni que le message est parti. C'est le code de statut qui décrit le
     * plus honnêtement ce qui vient de se passer.
     */
    public function request(Request $request): JsonResponse
    {
        // Validation de FORME seulement — une chaîne, une longueur plausible.
        // Rien ici ne doit dépendre du contenu : un 422 qui ne tomberait que
        // sur certains numéros serait déjà un oracle.
        $request->validate([
            'phone' => ['required', 'string', 'max:25'],
        ]);

        $this->otp->request($request->input('phone'), $request->ip());

        return response()->json(['ok' => true], 202);
    }

    /**
     * POST /auth/otp/verify — vérification du code, et ouverture de session.
     *
     * En cas de succès, la session est émise par le MÊME SessionIssuer que le
     * mot de passe et les passkeys : même forme de payload, même token Sanctum,
     * même profil renvoyé au frontend. La destination visée (`next`) est
     * portée par le frontend, qui la connaît déjà — le backend n'a pas à la
     * transporter pour lui.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:25'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $user = $this->otp->verify(
            $request->input('phone'),
            $request->input('code'),
            $request->ip(),
        );

        if ($user === null) {
            // Un seul message pour tous les échecs : code faux, code périmé,
            // code déjà utilisé, numéro verrouillé, numéro inconnu. Distinguer
            // ces cas dirait à un attaquant lequel de ses paramètres est bon.
            return response()->json([
                'data' => null,
                'errors' => [[
                    'code' => 'OTP_INVALID',
                    'message' => 'Code invalide ou expiré.',
                    'field' => 'code',
                ]],
            ], 422);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $payload = SessionIssuer::issue($user, SessionIssuer::METHOD_WHATSAPP_OTP, $request->userAgent());

        // Deux lignes de journal et non une : « le code était bon » et « une
        // session de 30 jours vient de s'ouvrir » sont deux événements
        // différents pour qui relit un incident. Le second est celui qui
        // compte quand un admin se demande quoi révoquer.
        AuditLogger::log('user.login', $user, actor: $user, newValues: ['via' => SessionIssuer::METHOD_WHATSAPP_OTP]);
        AuditLogger::log('authority.otp_session_opened', $user, actor: $user, newValues: [
            'via' => SessionIssuer::METHOD_WHATSAPP_OTP,
            'expires_at' => $payload['expires_at']?->toIso8601String(),
            // L'appareil, pour que la révocation ait un sens : un admin doit
            // pouvoir dire « ce téléphone-là ».
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return response()->json(['data' => $payload]);
    }
}
