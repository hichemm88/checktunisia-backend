<?php

namespace App\Services\Webauthn;

use App\Models\User;
use App\Models\WebauthnCredential;
use Cose\Algorithm\Manager as CoseManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Toute la cryptographie WebAuthn est déléguée à web-auth/webauthn-lib (5.3).
 *
 * Ce service ne fait que trois choses : construire les options de cérémonie,
 * traduire entre les objets de la bibliothèque et nos colonnes SQL, et exposer
 * la vérification. Aucune vérification de signature, de challenge, d'origin ou
 * de RP ID n'est réimplémentée ici — les réécrire soi-même est la première
 * cause de failles dans les intégrations WebAuthn.
 *
 * Les seules valeurs de confiance viennent de config/webauthn.php, jamais de
 * la requête : un client ne choisit ni son RP ID, ni son origin, ni le niveau
 * de vérification utilisateur exigé.
 */
class WebauthnService
{
    private SerializerInterface $serializer;

    private CeremonyStepManagerFactory $ceremonyFactory;

    public function __construct()
    {
        $attestationSupport = AttestationStatementSupportManager::create();

        // Nous demandons `attestation: none` : nous ne cherchons pas à
        // identifier le modèle d'authentificateur (ce serait un traceur, et
        // cela n'apporte rien ici).
        $attestationSupport->add(NoneAttestationStatementSupport::create());

        // Mais demander ne suffit pas. La spec dit que le client DEVRAIT
        // remplacer l'attestation par « none » ; certaines implémentations —
        // navigateurs embarquant un moteur tiers, gestionnaires de mots de
        // passe externes, clés FIDO2 — renvoient malgré tout leur format
        // d'origine. N'accepter que « none » revenait alors à refuser un
        // enregistrement parfaitement valide, sans que l'utilisateur ni
        // l'exploitant ne puissent rien y faire.
        //
        // Les déclarer ici ne fait qu'autoriser leur LECTURE et la
        // vérification de leur signature ; aucune chaîne de certificats n'est
        // exigée tant que le support des métadonnées FIDO n'est pas activé
        // (il ne l'est pas). La décision de ne pas se servir de l'attestation
        // reste donc entière.
        $attestationSupport->add(PackedAttestationStatementSupport::create($this->algorithms()));
        $attestationSupport->add(AppleAttestationStatementSupport::create());
        $attestationSupport->add(AndroidKeyAttestationStatementSupport::create());
        $attestationSupport->add(FidoU2FAttestationStatementSupport::create());

        $this->serializer = (new WebauthnSerializerFactory($attestationSupport))->create();

        $this->ceremonyFactory = new CeremonyStepManagerFactory();
        $this->ceremonyFactory->setAttestationStatementSupportManager($attestationSupport);
        $this->ceremonyFactory->setAllowedOrigins($this->allowedOrigins());
    }

    /**
     * Algorithmes de signature acceptés — ES256 (Face ID, Touch ID, Android,
     * Windows Hello) et RS256 (TPM plus anciens). Les mêmes que ceux annoncés
     * dans `pubKeyCredParams`.
     */
    private function algorithms(): CoseManager
    {
        return CoseManager::create()->add(ES256::create(), RS256::create());
    }

    // ── Configuration ────────────────────────────────────────────────────────

    public function rpId(): string
    {
        return (string) config('webauthn.rp_id');
    }

    /** @return string[] */
    public function allowedOrigins(): array
    {
        return (array) config('webauthn.origins', []);
    }

    public function userVerification(): string
    {
        return (string) config('webauthn.user_verification', 'required');
    }

    public function challengeTtl(): int
    {
        return (int) config('webauthn.challenge_ttl', 300);
    }

    // ── Construction des options ─────────────────────────────────────────────

    /**
     * Options d'ENREGISTREMENT (navigator.credentials.create).
     *
     * `excludeCredentials` évite qu'un même appareil enregistre deux passkeys
     * pour le même compte : le système affiche alors « déjà enregistré » au
     * lieu de créer un doublon silencieux.
     */
    public function creationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        $exclude = $user->webauthnCredentials()
            ->get()
            ->map(fn (WebauthnCredential $c) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($c->credential_id),
                $c->transports ?? [],
            ))
            ->all();

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create((string) config('webauthn.rp_name'), $this->rpId()),
            user: PublicKeyCredentialUserEntity::create(
                $user->email,
                Base64UrlSafe::decodeNoPadding($user->webauthnUserHandle()),
                trim($user->full_name) ?: $user->email,
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                // ES256 d'abord : c'est ce que produisent Face ID / Touch ID,
                // Android et Windows Hello. RS256 en repli (TPM plus anciens).
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // Aucun `authenticatorAttachment` : l'utilisateur reste libre
                // d'enregistrer la biométrie de l'appareil, son téléphone en
                // relais, ou une clé de sécurité physique.
                userVerification: $this->userVerification(),
                residentKey: (string) config('webauthn.resident_key', 'required'),
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: (int) config('webauthn.timeout', 120_000),
        );
    }

    /**
     * Options de CONNEXION (navigator.credentials.get).
     *
     * `allowCredentials` reste VIDE volontairement : la connexion se fait sans
     * identifiant, à partir des passkeys découvrables. Renvoyer la liste des
     * credentials d'une adresse e-mail donnée transformerait cet endpoint
     * public en oracle d'existence de compte.
     */
    public function requestOptions(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->rpId(),
            allowCredentials: [],
            userVerification: $this->userVerification(),
            timeout: (int) config('webauthn.timeout', 120_000),
        );
    }

    // ── Sérialisation ────────────────────────────────────────────────────────

    /**
     * Options → tableau JSON tel qu'attendu par le navigateur.
     */
    public function normalizeOptions(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options
    ): array {
        return json_decode(
            $this->serializer->serialize($options, 'json', [
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            ]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  class-string<PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions>  $type
     */
    public function denormalizeOptions(array $data, string $type): PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
    {
        return $this->serializer->denormalize($data, $type);
    }

    /**
     * Réponse brute du navigateur → objet PublicKeyCredential.
     *
     * Toute donnée mal formée s'arrête ici : la bibliothèque lève, et l'appelant
     * répond 422 sans jamais toucher la base.
     */
    public function parseCredential(array $payload): PublicKeyCredential
    {
        return $this->serializer->deserialize(
            json_encode($payload, JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
            'json',
        );
    }

    // ── Vérification ─────────────────────────────────────────────────────────

    /**
     * Vérifie une attestation (enregistrement d'une nouvelle passkey).
     *
     * @throws Throwable si le challenge, l'origin, le RP ID, l'algorithme ou
     *                   la vérification utilisateur ne conviennent pas.
     */
    public function verifyRegistration(
        AuthenticatorAttestationResponse $response,
        PublicKeyCredentialCreationOptions $options,
    ): CredentialRecord {
        return AuthenticatorAttestationResponseValidator::create($this->ceremonyFactory->creationCeremony())
            ->check($response, $options, $this->rpId());
    }

    /**
     * Vérifie une assertion (connexion).
     *
     * Contrôle le challenge, l'origin, le RP ID, la signature, la présence et
     * la vérification de l'utilisateur, la cohérence des bits de sauvegarde et
     * le compteur anti-clonage.
     *
     * @throws Throwable
     */
    public function verifyAuthentication(
        CredentialRecord $record,
        AuthenticatorAssertionResponse $response,
        PublicKeyCredentialRequestOptions $options,
        ?string $userHandle = null,
    ): CredentialRecord {
        return AuthenticatorAssertionResponseValidator::create($this->ceremonyFactory->requestCeremony())
            ->check($record, $response, $options, $this->rpId(), $userHandle);
    }

    // ── Conversion modèle ↔ bibliothèque ─────────────────────────────────────

    public function toCredentialRecord(WebauthnCredential $credential): CredentialRecord
    {
        return $this->serializer->denormalize([
            'publicKeyCredentialId' => $credential->credential_id,
            'type'                  => 'public-key',
            'transports'            => $credential->transports ?? [],
            'attestationType'       => $credential->attestation_type,
            'trustPath'             => $credential->trust_path ?? [],
            'aaguid'                => $credential->aaguid ?? '00000000-0000-0000-0000-000000000000',
            'credentialPublicKey'   => $credential->public_key,
            'userHandle'            => $credential->user_handle,
            'counter'               => $credential->sign_count,
            'backupEligible'        => $credential->backup_eligible,
            'backupStatus'          => $credential->backed_up,
            'uvInitialized'         => $credential->uv_initialized,
        ], CredentialRecord::class);
    }

    /**
     * CredentialRecord → colonnes SQL. Aucune clé privée, aucune biométrie :
     * seulement ce que la spec appelle « credential record ».
     */
    public function toDatabaseColumns(CredentialRecord $record): array
    {
        $normalized = $this->serializer->normalize($record);

        return [
            'credential_id'    => $normalized['publicKeyCredentialId'],
            'public_key'       => $normalized['credentialPublicKey'],
            'attestation_type' => $record->attestationType,
            'trust_path'       => $normalized['trustPath'] ?? [],
            'aaguid'           => $record->aaguid->__toString(),
            'user_handle'      => $normalized['userHandle'],
            'transports'       => $record->transports,
            'sign_count'       => $record->counter,
            'backup_eligible'  => $record->backupEligible,
            'backed_up'        => $record->backupStatus,
            'uv_initialized'   => $record->uvInitialized,
        ];
    }

    public static function encodeBase64Url(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }
}
