<?php

namespace App\Services\OCR;

/**
 * Un nom de voyageur qui ne RESSEMBLE PAS à un nom — dernier filet avant
 * qu'une lecture OCR/vision ne parte sur une fiche transmise à la police.
 *
 * Né d'un incident réel : une fiche transmise portait comme nom
 * « ABEEXPEDIGAQIDATEOFISSUEAUTORIDADEAUTHO » — l'OCR avait lu les LÉGENDES
 * imprimées de la page bio-data d'un passeport brésilien (« Data de
 * Emissão/Date of Issue », « Autoridade/Authority »...) au lieu du nom
 * réellement imprimé. Aucun chiffre de contrôle MRZ ne pouvait le détecter :
 * la ligne 1 (qui porte le nom) n'en a AUCUN. Le seul filet possible est un
 * jugement sur la FORME du texte lui-même.
 *
 * Même logique que `frontend/src/lib/namePlausibility.ts` (et sa copie
 * `frontend/api/_lib/`) — dupliquée ici parce que ce service tourne côté
 * Laravel (widget), un runtime différent. Toute modification doit être
 * répercutée dans les trois fichiers.
 *
 * Volontairement conservateur (peu de faux positifs) : un vrai nom refusé
 * bloque un enregistrement légitime (récupérable — l'invité le retape), un
 * faux nom accepté part sur une fiche transmise à une autorité.
 */
class NamePlausibility
{
    private const MAX_TOTAL_LENGTH = 60;

    /**
     * Un « mot » plus long que ceci est structurellement improbable pour un
     * nom humain, mais typique d'un bloc de texte d'étiquette happé sans
     * espaces par l'OCR — la forme exacte de l'incident d'origine.
     */
    private const MAX_TOKEN_LENGTH = 30;

    /**
     * Fragments bureaucratiques longs et distinctifs (FR/EN/PT) : jamais des
     * mots courts comme « DATE » ou « SEX », qui collisionnent avec de vrais
     * noms/lieux (Essex, Sussex...).
     *
     * Toujours SANS ACCENT : la normalisation ci-dessous SUPPRIME les
     * caractères accentués plutôt que de les transformer — un fragment
     * accentué ne matcherait donc jamais rien.
     */
    private const LABEL_FRAGMENTS = [
        'AUTORIDADE', 'AUTHORITY', 'EXPEDICAO', 'NASCIMENTO',
        'PASSAPORTE', 'REPUBLICA', 'REPUBLIQUE', 'NATIONALITY', 'NACIONALIDADE',
        'ASSINATURA', 'SIGNATURE', 'VALIDADE', 'EMISSAO', 'ENDERECO',
        'DATEOFISSUE', 'DATEOFBIRTH', 'DATEOFEXPIRY', 'PLACEOFBIRTH',
        'GIVENNAME', 'SURNAME', 'DOCUMENTNUMBER', 'ISSUINGAUTHORITY',
    ];

    /**
     * Un champ, seul : est-ce que sa forme évoque un texte d'étiquette plutôt
     * qu'un nom ? Un champ absent/vide n'est PAS suspect — il n'y a rien à
     * distruster, et le traiter comme tel effacerait à tort le champ voisin.
     */
    public static function isSuspicious(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $v = trim($value);

        if ($v === '') {
            return false;
        }

        if (mb_strlen($v) > self::MAX_TOTAL_LENGTH) {
            return true;
        }

        // Lettres (accents compris), espaces, apostrophes, tirets, points.
        if (! preg_match('/^[\p{L}\p{M} \'\-.]+$/u', $v)) {
            return true;
        }

        $tokens = preg_split('/[\s\-]+/u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) > self::MAX_TOKEN_LENGTH) {
                return true;
            }
        }

        $letters = mb_strtoupper(preg_replace('/[^a-zA-Z]/u', '', $v) ?? '');

        foreach (self::LABEL_FRAGMENTS as $fragment) {
            if (mb_strpos($letters, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Deux champs identiques (hors casse/espaces) : jamais un vrai prénom + nom. */
    public static function same(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null || trim($a) === '' || trim($b) === '') {
            return false;
        }

        return mb_strtoupper(trim($a)) === mb_strtoupper(trim($b));
    }
}
