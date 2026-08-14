<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionIssuer;
use App\Services\Webauthn\ChallengeStore;
use App\Services\Webauthn\WebauthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Connexion par passkey — routes PUBLIQUES (l'utilisateur n'est pas encore
 * authentifié), limitées en débit.
 *
 * Le facteur présenté ici vaut à lui seul une authentification forte : il
 * prouve la possession de l'appareil (clé privée inextractible) ET la présence
 * de son porteur (Face ID / Touch ID / Windows Hello / empreinte / code), le
 * niveau `user_verification` exigé étant `required` par défaut. C'est pourquoi
 * aucun TOTP n'est redemandé ensuite : ce serait un troisième facteur, pas un
 * second.
 *
 * Aucune donnée biométrique n'atteint ce contrôleur. Le seul témoin de la
 * vérification est un bit dans authenticatorData, signé par l'appareil.
 */
class PasskeyAuthController extends Controller
{
    public function __construct(
        private readonly WebauthnService $webauthn,
        private readonly ChallengeStore $challenges,
    ) {
    }

    // ── POST /auth/passkey/options ───────────────────────────────────────────

    /**
     * Challenge de connexion. Aucun identifiant n'est demandé : la liste des
     * credentials autorisés reste vide, et c'est l'appareil qui propose les
     * passkeys qu'il détient pour ce domaine. Cet endpoint ne révèle donc
     * jamais si une adresse e-mail correspond à un compte.
     */
    public function options(Request $request): JsonResponse
    {
        $options    = $this->webauthn->requestOptions();
        $serialized = $this->webauthn->normalizeOptions($options);

        $challenge = $this->challenges->issue(
            WebauthnChallenge::CEREMONY_AUTHENTICATION,
            $serialized,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->id,
                'public_key'   => $serialized,
            ],
        ]);
    }

    // ── POST /auth/passkey/verify ────────────────────────────────────────────

    /**
     * Vérifie l'assertion et ouvre la session.
     *
     * La bibliothèque contrôle : challenge (celui que NOUS avons émis), origin,
     * RP ID, signature, présence utilisateur, vérification utilisateur,
     * cohérence des bits de sauvegarde et compteur anti-clonage. Le challenge
     * est consommé AVANT la vérification : une réponse rejouée ne retrouve
     * aucun challenge ouvert.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'credential'   => ['required', 'array'],
        ]);

        $challenge = $this->challenges->consume(
            $validated['challenge_id'],
            WebauthnChallenge::CEREMONY_AUTHENTICATION,
        );

        if (! $challenge) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PASSKEY_CHALLENGE_INVALID', 'message' => 'Demande expirée ou déjà utilisée. Recommencez.', 'field' => null]],
            ], 422);
        }

        try {
            $publicKeyCredential = $this->webauthn->parseCredential($validated['credential']);
            $response            = $publicKeyCredential->response;

            if (! $response instanceof AuthenticatorAssertionResponse) {
                throw new \RuntimeException('Expected an assertion response.');
            }
        } catch (Throwable) {
            return $this->rejected('PASSKEY_INVALID_PAYLOAD', 422);
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
        $credential   = WebauthnCredential::where('credential_id', $credentialId)->first();

        // Credential inconnu = passkey révoquée, ou appartenant à une autre
        // installation. Réponse volontairement identique à un échec de
        // signature : on n'apprend rien à un attaquant.
        if (! $credential) {
            AuditLogger::log('auth.passkey_login_failed', newValues: ['reason' => 'unknown_credential']);

            return $this->rejected();
        }

        $user = $credential->user;

        if (! $user) {
            return $this->rejected();
        }

        if ($user->status !== 'active') {
            AuditLogger::log('auth.passkey_login_failed', $user, actor: $user, newValues: ['reason' => 'inactive_account']);

            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'AUTH_ACCOUNT_SUSPENDED', 'message' => 'Your account is suspended.', 'field' => null]],
            ], 403);
        }

        try {
            /** @var PublicKeyCredentialRequestOptions $options */
            $options = $this->webauthn->denormalizeOptions(
                $challenge->options,
                PublicKeyCredentialRequestOptions::class,
            );

            $record = $this->webauthn->verifyAuthentication(
                $this->webauthn->toCredentialRecord($credential),
                $response,
                $options,
                Base64UrlSafe::decodeNoPadding($credential->user_handle),
            );
        } catch (Throwable $e) {
            AuditLogger::log('auth.passkey_login_failed', $user, actor: $user, newValues: [
                'reason'     => 'verification_failed',
                'passkey_id' => $credential->id,
                'detail'     => $e->getMessage(),
            ]);

            return $this->rejected();
        }

        // Compteur, état de sauvegarde et dernière utilisation : mis à jour à
        // partir de ce que la bibliothèque a validé, jamais de la requête brute.
        $credential->update([
            'sign_count'      => $record->counter,
            'backup_eligible' => $record->backupEligible,
            'backed_up'       => $record->backupStatus,
            'uv_initialized'  => $record->uvInitialized,
            'last_used_at'    => now(),
            'last_used_ip'    => $request->ip(),
        ]);

        $user->update(['last_login_at' => now()]);

        AuditLogger::log('auth.passkey_login', $user, actor: $user, newValues: [
            'passkey_id'  => $credential->id,
            'device_name' => $credential->device_name,
        ]);

        return response()->json([
            'data' => SessionIssuer::issue($user, SessionIssuer::METHOD_PASSKEY),
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function rejected(string $code = 'PASSKEY_VERIFICATION_FAILED', int $status = 401): JsonResponse
    {
        return response()->json([
            'data'   => null,
            'errors' => [['code' => $code, 'message' => "La connexion par passkey a échoué. Essayez une autre méthode de connexion.", 'field' => null]],
        ], $status);
    }
}
