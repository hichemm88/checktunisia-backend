<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\SessionIssuer;
use App\Services\Webauthn\ChallengeStore;
use App\Services\Webauthn\WebauthnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Gestion des passkeys d'un compte AUTHENTIFIÉ :
 * Profil → Sécurité → Passkeys (ajouter, renommer, supprimer).
 *
 * Ces routes exigent un token complet. Le même code sert tous les rôles :
 * établissement, administrateur plateforme, autorité — la passkey appartient
 * au compte, pas au rôle.
 */
class PasskeyController extends Controller
{
    public function __construct(
        private readonly WebauthnService $webauthn,
        private readonly ChallengeStore $challenges,
    ) {
    }

    // ── GET /auth/passkeys ───────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'passkeys'                 => $user->webauthnCredentials()
                    ->orderByDesc('last_used_at')
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn (WebauthnCredential $c) => $c->toDisplayArray())
                    ->values(),
                'max_allowed'              => (int) config('webauthn.max_credentials_per_user', 10),
                'recovery_codes_remaining' => RecoveryCodeService::remaining($user),
            ],
        ]);
    }

    // ── POST /auth/passkeys/options ──────────────────────────────────────────

    /**
     * Étape 1 : le serveur génère un challenge et les options d'enregistrement.
     * Le navigateur les passe à navigator.credentials.create(), qui déclenche
     * Face ID / Touch ID / Windows Hello / empreinte / PIN selon l'appareil.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        $max = (int) config('webauthn.max_credentials_per_user', 10);
        if ($user->webauthnCredentials()->count() >= $max) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PASSKEY_LIMIT_REACHED', 'message' => "Nombre maximum de passkeys atteint ({$max}). Supprimez-en une avant d'en ajouter une nouvelle.", 'field' => null]],
            ], 422);
        }

        $options   = $this->webauthn->creationOptions($user);
        $serialized = $this->webauthn->normalizeOptions($options);

        $challenge = $this->challenges->issue(
            WebauthnChallenge::CEREMONY_REGISTRATION,
            $serialized,
            $user,
        );

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->id,
                'public_key'   => $serialized,
            ],
        ]);
    }

    // ── POST /auth/passkeys ──────────────────────────────────────────────────

    /**
     * Étape 2 : vérification de l'attestation, puis enregistrement.
     *
     * Le serveur revalide le challenge qu'il a lui-même émis, l'origin, le
     * RP ID et l'algorithme. Ce qui est stocké est public : identifiant de
     * credential et clé publique. Aucune donnée biométrique ne transite.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'name'         => ['nullable', 'string', 'max:60'],
            'credential'   => ['required', 'array'],
        ]);

        $user = $request->user();

        $challenge = $this->challenges->consume(
            $validated['challenge_id'],
            WebauthnChallenge::CEREMONY_REGISTRATION,
            $user,
        );

        if (! $challenge) {
            return $this->challengeError();
        }

        try {
            $credential = $this->webauthn->parseCredential($validated['credential']);
            $response   = $credential->response;

            if (! $response instanceof AuthenticatorAttestationResponse) {
                throw new \RuntimeException('Expected an attestation response.');
            }

            /** @var PublicKeyCredentialCreationOptions $options */
            $options = $this->webauthn->denormalizeOptions(
                $challenge->options,
                PublicKeyCredentialCreationOptions::class,
            );

            $record = $this->webauthn->verifyRegistration($response, $options);
        } catch (Throwable $e) {
            AuditLogger::log('auth.passkey_registration_failed', $user, actor: $user, newValues: [
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PASSKEY_INVALID', 'message' => "Cette passkey n'a pas pu être vérifiée. Réessayez.", 'field' => null]],
            ], 422);
        }

        $columns = $this->webauthn->toDatabaseColumns($record);

        // Un credential ne peut appartenir qu'à un seul compte. Le cas normal
        // est l'utilisateur qui réenregistre le même appareil : on répond 409
        // plutôt que de laisser la contrainte d'unicité remonter en 500.
        if (WebauthnCredential::where('credential_id', $columns['credential_id'])->exists()) {
            return response()->json([
                'data'   => null,
                'errors' => [['code' => 'PASSKEY_ALREADY_REGISTERED', 'message' => 'Cet appareil possède déjà une passkey pour un compte.', 'field' => null]],
            ], 409);
        }

        $name = trim((string) ($validated['name'] ?? '')) ?: \App\Support\DeviceName::fromUserAgent($request->userAgent());

        [$passkey, $recoveryCodes] = DB::transaction(function () use ($user, $columns, $name) {
            $isFirst = ! $user->hasPasskey();

            $passkey = $user->webauthnCredentials()->create([
                ...$columns,
                'device_name'  => $name,
                'last_used_at' => now(),
                'last_used_ip' => \Illuminate\Support\Facades\Request::ip(),
            ]);

            // À la PREMIÈRE passkey, on remet des codes de récupération : c'est
            // ce qui évite l'enfermement le jour où l'appareil est perdu. Les
            // codes existants sont remplacés — l'utilisateur voit la nouvelle
            // liste une seule fois, à cet instant.
            $codes = $isFirst ? RecoveryCodeService::regenerate($user) : null;

            return [$passkey, $codes];
        });

        AuditLogger::log('auth.passkey_registered', $passkey, actor: $user, newValues: [
            'device_name' => $passkey->device_name,
            'transports'  => $passkey->transports,
            'backed_up'   => $passkey->backed_up,
        ]);

        return response()->json([
            'data' => [
                'passkey'        => $passkey->toDisplayArray(),
                // Affichés UNE seule fois. Non renvoyés pour les passkeys suivantes.
                'recovery_codes' => $recoveryCodes,
                'security'       => SessionIssuer::securityState($user->fresh()),
            ],
        ], 201);
    }

    // ── PATCH /auth/passkeys/{id} ────────────────────────────────────────────

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $user    = $request->user();
        $passkey = $user->webauthnCredentials()->find($id);

        if (! $passkey) {
            return $this->notFound();
        }

        $old = $passkey->device_name;
        $passkey->update(['device_name' => trim($validated['name'])]);

        AuditLogger::log('auth.passkey_renamed', $passkey, ['device_name' => $old], ['device_name' => $passkey->device_name], actor: $user);

        return response()->json(['data' => $passkey->fresh()->toDisplayArray()]);
    }

    // ── DELETE /auth/passkeys/{id} ───────────────────────────────────────────

    /**
     * Révocation. La suppression de la ligne EST la révocation : sans clé
     * publique, aucune assertion de cet appareil ne peut plus être vérifiée,
     * y compris avec une réponse capturée auparavant.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user    = $request->user();
        $passkey = $user->webauthnCredentials()->find($id);

        if (! $passkey) {
            return $this->notFound();
        }

        $name = $passkey->device_name;
        $passkey->delete();

        AuditLogger::log('auth.passkey_revoked', $user, actor: $user, newValues: [
            'passkey_id'  => $id,
            'device_name' => $name,
        ]);

        Log::info('Passkey révoquée', ['user_id' => $user->id, 'passkey_id' => $id]);

        return response()->json([
            'data' => ['security' => SessionIssuer::securityState($user->fresh())],
        ]);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function challengeError(): JsonResponse
    {
        return response()->json([
            'data'   => null,
            'errors' => [['code' => 'PASSKEY_CHALLENGE_INVALID', 'message' => 'Demande expirée ou déjà utilisée. Recommencez.', 'field' => null]],
        ], 422);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'data'   => null,
            'errors' => [['code' => 'PASSKEY_NOT_FOUND', 'message' => 'Passkey introuvable.', 'field' => null]],
        ], 404);
    }
}
