<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Codes de récupération : la sortie de secours quand l'appareil portant la
 * passkey — ou l'application d'authentification — n'est plus disponible.
 *
 * Un code remplace le TOTP à l'étape de vérification. Il ne remplace jamais le
 * mot de passe : la liste seule ne permet pas d'entrer.
 */
class RecoveryCodeController extends Controller
{
    // ── GET /auth/recovery-codes ─────────────────────────────────────────────

    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['remaining' => RecoveryCodeService::remaining($request->user())],
        ]);
    }

    // ── POST /auth/recovery-codes ────────────────────────────────────────────

    /**
     * Régénère la liste. Exige le mot de passe actuel : une session volée ne
     * doit pas pouvoir se fabriquer un second accès durable au compte.
     */
    public function regenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $codes = RecoveryCodeService::regenerate($user);

        AuditLogger::log('auth.recovery_codes_regenerated', $user, actor: $user);

        return response()->json([
            'data' => [
                // Affichés une seule fois : ils ne sont stockés que hachés.
                'recovery_codes' => $codes,
            ],
        ]);
    }

    // ── POST /auth/2fa/recovery ──────────────────────────────────────────────

    /**
     * Termine une connexion avec un code de récupération, à la place du TOTP.
     * S'appelle avec le token partiel émis par /auth/login.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        $user         = $request->user();
        $partialToken = $user->currentAccessToken();

        if (! $partialToken->can('2fa-pending') || $partialToken->can('*')) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => '2FA_NOT_REQUIRED', 'message' => 'Full token already issued.', 'field' => null]],
            ], 422);
        }

        if (! RecoveryCodeService::consume($user, $validated['code'])) {
            AuditLogger::log('auth.recovery_code_failed', $user, actor: $user);

            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'RECOVERY_CODE_INVALID', 'message' => 'Code de récupération invalide ou déjà utilisé.', 'field' => null]],
            ], 422);
        }

        $partialToken->delete();

        AuditLogger::log('auth.recovery_code_used', $user, actor: $user, newValues: [
            'remaining' => RecoveryCodeService::remaining($user),
        ]);

        return response()->json([
            'data' => SessionIssuer::issue($user, SessionIssuer::METHOD_RECOVERY_CODE),
        ]);
    }
}
