<?php

namespace App\Services\PartnerApi;

/**
 * Codes d'erreur stables de l'API publique v1 (§2). Utilisés à la fois par le
 * gestionnaire d'exceptions (bootstrap/app.php) et par la doc OpenAPI — ne
 * jamais renommer une valeur existante, l'ajout est toujours possible.
 */
class ErrorCodes
{
    public const INVALID_API_KEY = 'invalid_api_key';
    public const INVALID_LINK_CODE = 'invalid_link_code';
    public const LINK_CODE_EXPIRED = 'link_code_expired';
    public const LINK_CODE_ALREADY_USED = 'link_code_already_used';
    public const ESTABLISHMENT_NOT_LINKED = 'establishment_not_linked';
    public const API_ACCESS_DISABLED = 'api_access_disabled';

    // Réservé par contrat (§2) : jamais renvoyé par une limite légale de
    // volume de fiches — voir API-V1-DECISIONS.md. N'existe aujourd'hui que
    // pour rester documentable si une limite commerciale distincte apparaît.
    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const SESSION_EXPIRED = 'session_expired';
    public const SESSION_NOT_FOUND = 'session_not_found';
    public const FICHE_NOT_FOUND = 'fiche_not_found';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const FRAME_DISALLOWED = 'frame_disallowed';
    public const RATE_LIMITED = 'rate_limited';
    public const VALIDATION_ERROR = 'validation_error';

    /** Libellés FR par défaut — surchageables au cas par cas dans PartnerApiException::make(). */
    public const MESSAGES = [
        self::INVALID_API_KEY => 'Clé API invalide ou révoquée.',
        self::INVALID_LINK_CODE => 'Code de liaison invalide.',
        self::LINK_CODE_EXPIRED => 'Ce code de liaison a expiré (validité 24h).',
        self::LINK_CODE_ALREADY_USED => 'Ce code de liaison a déjà été utilisé.',
        self::ESTABLISHMENT_NOT_LINKED => 'Cet établissement n\'est pas lié à ce partenaire.',
        self::API_ACCESS_DISABLED => 'L\'accès API n\'est pas activé pour cet établissement.',
        self::QUOTA_EXCEEDED => 'Quota de fiches dépassé.',
        self::SESSION_EXPIRED => 'Cette session a expiré.',
        self::SESSION_NOT_FOUND => 'Session introuvable.',
        self::FICHE_NOT_FOUND => 'Fiche introuvable.',
        self::INVALID_SIGNATURE => 'Signature invalide.',
        self::FRAME_DISALLOWED => 'Origine non autorisée à charger ce widget.',
        self::RATE_LIMITED => 'Trop de requêtes.',
        self::VALIDATION_ERROR => 'Erreur de validation.',
    ];

    public const HTTP_STATUS = [
        self::INVALID_API_KEY => 401,
        self::INVALID_LINK_CODE => 422,
        self::LINK_CODE_EXPIRED => 422,
        self::LINK_CODE_ALREADY_USED => 422,
        self::ESTABLISHMENT_NOT_LINKED => 403,
        self::API_ACCESS_DISABLED => 403,
        self::QUOTA_EXCEEDED => 422,
        self::SESSION_EXPIRED => 410,
        self::SESSION_NOT_FOUND => 404,
        self::FICHE_NOT_FOUND => 404,
        self::INVALID_SIGNATURE => 401,
        self::FRAME_DISALLOWED => 403,
        self::RATE_LIMITED => 429,
        self::VALIDATION_ERROR => 422,
    ];
}
