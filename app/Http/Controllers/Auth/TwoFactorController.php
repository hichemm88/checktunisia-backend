<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * Handles TOTP two-factor authentication setup and verification.
 *
 * Flow:
 *   1. Authority user logs in with password → receives partial token (ability: 2fa-pending)
 *   2. POST /auth/2fa/verify  → validates TOTP code → issues full token
 *
 * Setup flow (first time):
 *   1. GET  /auth/2fa/setup          → generates secret, returns QR URI
 *   2. POST /auth/2fa/setup/confirm  → validates code → marks two_factor_confirmed_at
 *   3. DELETE /auth/2fa/setup        → disables 2FA (admin-initiated or self with confirmation)
 */
class TwoFactorController extends Controller
{
    private Google2FA $totp;

    public function __construct()
    {
        $this->totp = new Google2FA();
    }

    // ── GET /auth/2fa/setup ───────────────────────────────────────────────────

    /**
     * Return (or generate) the TOTP secret + otpauth URI for QR rendering.
     * The secret is encrypted at rest; we decrypt only here.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate a new secret only if none exists yet
        if (!$user->two_factor_secret) {
            $secret = $this->totp->generateSecretKey(32);
            $user->update(['two_factor_secret' => Crypt::encryptString($secret)]);
        } else {
            $secret = Crypt::decryptString($user->two_factor_secret);
        }

        $qrUri = $this->totp->getQRCodeUrl(
            config('app.name', 'Qayed'),
            $user->email,
            $secret
        );

        return response()->json([
            'data' => [
                'secret'          => $secret,   // for manual entry
                'qr_uri'          => $qrUri,    // render client-side with qrcode.react
                'already_enabled' => (bool) $user->two_factor_confirmed_at,
            ],
        ]);
    }

    // ── POST /auth/2fa/setup/confirm ─────────────────────────────────────────

    /**
     * Confirm the setup by validating the first TOTP code from the user's app.
     */
    public function confirmSetup(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/']]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'errors' => [['code' => '2FA_NOT_INITIALIZED', 'message' => "Lancez d'abord la configuration (GET /auth/2fa/setup)."]],
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (!$this->totp->verifyKey($secret, $request->code)) {
            return response()->json([
                'errors' => [['code' => '2FA_INVALID_CODE', 'message' => 'Code incorrect. Réessayez.']],
            ], 422);
        }

        $user->update(['two_factor_confirmed_at' => now()]);
        AuditLogger::log('auth.2fa_enabled', $user, actor: $user);

        return response()->json(['data' => ['enabled' => true]]);
    }

    // ── POST /auth/2fa/verify ─────────────────────────────────────────────────

    /**
     * Complete login: accepts the partial 2fa-pending token + TOTP code.
     * Revokes the partial token and issues a full Sanctum token.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/']]);

        $user        = $request->user();
        $partialToken = $user->currentAccessToken();

        // Guard: this route is only callable with a 2fa-pending token
        if (!$partialToken->can('2fa-pending') || $partialToken->can('*')) {
            return response()->json([
                'errors' => [['code' => '2FA_NOT_REQUIRED', 'message' => 'La session est déjà pleinement ouverte.']],
            ], 422);
        }

        if (!$user->two_factor_secret || !$user->two_factor_confirmed_at) {
            return response()->json([
                'errors' => [['code' => '2FA_NOT_SETUP', 'message' => "La double authentification n'est pas configurée sur ce compte."]],
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (!$this->totp->verifyKey($secret, $request->code)) {
            AuditLogger::log('auth.2fa_failed', $user, actor: $user);
            return response()->json([
                'errors' => [['code' => '2FA_INVALID_CODE', 'message' => 'Code incorrect. Réessayez.']],
            ], 422);
        }

        // Revoke partial token, issue full token
        $partialToken->delete();

        AuditLogger::log('auth.2fa_verified', $user, actor: $user);

        return response()->json([
            'data' => SessionIssuer::issue($user, SessionIssuer::METHOD_TOTP),
        ]);
    }

    // ── DELETE /auth/2fa/setup ────────────────────────────────────────────────

    /**
     * Disable 2FA for the authenticated user.
     * Requires TOTP confirmation to prevent accidental/malicious disabling.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/']]);

        $user = $request->user();

        if (!$user->two_factor_confirmed_at) {
            return response()->json([
                'errors' => [['code' => '2FA_NOT_ENABLED', 'message' => "La double authentification n'est pas activée."]],
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        if (!$this->totp->verifyKey($secret, $request->code)) {
            return response()->json([
                'errors' => [['code' => '2FA_INVALID_CODE', 'message' => 'Code incorrect. Réessayez.']],
            ], 422);
        }

        $user->update([
            'two_factor_secret'       => null,
            'two_factor_confirmed_at' => null,
        ]);

        AuditLogger::log('auth.2fa_disabled', $user, actor: $user);

        return response()->json(['data' => ['disabled' => true]]);
    }
}
