<?php

namespace App\Services\CheckIn;

use App\Models\Country;
use App\Models\Guest;
use Illuminate\Support\Facades\Cache;

/**
 * Forme canonique d'une identité documentaire.
 *
 * Le rapprochement d'un voyageur repose entièrement sur le triplet
 * (type, numéro, pays de délivrance). Tant que ce triplet n'était pas
 * normalisé, une même personne pouvait être créée plusieurs fois :
 *
 *  - « ab 123456 », « AB123456 » et « AB-123456 » étaient trois documents ;
 *  - « TN » (saisie manuelle) et « TUN » (scan CIN) étaient deux pays, donc
 *    deux voyageurs distincts pour le même passeport.
 *
 * Toute écriture ET toute recherche passent désormais par ici, de sorte que
 * la contrainte d'unicité (type, document_number, issuing_country_code) porte
 * enfin sur une valeur comparable.
 */
class DocumentIdentity
{
    /**
     * Numéro de document canonique : majuscules, sans séparateur.
     *
     * Les séparateurs (espaces, tirets, points, barres obliques) sont un
     * artefact de saisie — le MRZ d'un passeport n'en contient aucun.
     */
    public static function normalizeNumber(?string $number): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim((string) $number)));
    }

    /**
     * Pays de délivrance en alpha-3 (« TN » → « TUN »).
     *
     * Toute l'application stocke des alpha-3 (colonne char(3), nationalités,
     * fiches de police) mais les formulaires et le MRZ laissent passer des
     * alpha-2. Un code inconnu est renvoyé tel quel, en majuscules : mieux
     * vaut conserver la saisie de l'agent qu'inventer un pays.
     */
    public static function normalizeCountry(?string $code): string
    {
        $code = mb_strtoupper(trim((string) $code));

        if ($code === '' || strlen($code) === 3) {
            return $code;
        }

        return static::alpha2ToAlpha3()[$code] ?? $code;
    }

    /**
     * Le triplet identifiant, normalisé, tel qu'il doit être écrit en base.
     *
     * @param  array<string,mixed>  $docData
     * @return array{type:string,document_number:string,issuing_country_code:string}
     */
    public static function key(array $docData): array
    {
        return [
            'type' => $docData['type'] ?? 'passport',
            'document_number' => static::normalizeNumber($docData['document_number'] ?? null),
            'issuing_country_code' => static::normalizeCountry($docData['issuing_country_code'] ?? 'TUN'),
        ];
    }

    /**
     * Champs d'identité d'un voyageur existant qui divergent de ce que
     * l'agent vient de saisir.
     *
     * Sert à rendre VISIBLE le cas « même numéro de passeport, identité
     * différente » : le voyageur existant est réutilisé (c'est le
     * comportement correct — un document identifie une personne), mais la
     * réception doit savoir que la fiche partira au nom déjà enregistré et
     * non à celui qu'elle vient de taper.
     *
     * La comparaison est insensible à la casse, aux accents et aux espaces
     * multiples : « jean-pierre » et « Jean Pierre » ne sont pas un écart.
     *
     * @param  array<string,mixed>  $submitted
     * @return array<int,string> noms des champs divergents
     */
    public static function identityMismatch(Guest $existing, array $submitted): array
    {
        $diverging = [];

        foreach (['first_name', 'last_name'] as $field) {
            if (! array_key_exists($field, $submitted) || $submitted[$field] === null) {
                continue;
            }
            if (static::comparableName($existing->{$field}) !== static::comparableName($submitted[$field])) {
                $diverging[] = $field;
            }
        }

        if (! empty($submitted['date_of_birth']) && $existing->date_of_birth) {
            $submittedDob = substr((string) $submitted['date_of_birth'], 0, 10);
            $existingDob = $existing->date_of_birth instanceof \DateTimeInterface
                ? $existing->date_of_birth->format('Y-m-d')
                : substr((string) $existing->date_of_birth, 0, 10);

            if ($submittedDob !== $existingDob) {
                $diverging[] = 'date_of_birth';
            }
        }

        return $diverging;
    }

    /**
     * Forme comparable d'un nom : sans accent, sans séparateur, en majuscules.
     *
     * Utilisée pour la détection de doublons ET pour l'écart d'identité, afin
     * que « MÜLLER », « Muller » et « mueller » ne soient pas traités comme
     * trois personnes différentes par la simple faute d'une saisie.
     */
    public static function comparableName(?string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper(trim((string) $name)));

        return preg_replace('/[^A-Z0-9]/', '', (string) $ascii);
    }

    /**
     * Voyageurs déjà en base qui ressemblent fortement à celui qu'on s'apprête
     * à créer : même nom, même prénom et même date de naissance, à la casse et
     * aux accents près.
     *
     * Volontairement STRICT (les trois champs doivent coïncider) : signaler
     * deux homonymes nés le même jour est utile, bloquer deux « Ben Ali »
     * différents ne le serait pas. Le résultat n'empêche jamais la création —
     * il est remonté à la réception pour décision humaine.
     *
     * @param  array<string,mixed>  $data
     * @return \Illuminate\Support\Collection<int,Guest>
     */
    public static function similarGuests(array $data): \Illuminate\Support\Collection
    {
        if (empty($data['last_name']) || empty($data['date_of_birth'])) {
            return collect();
        }

        // Égalité simple et non `whereDate` : la colonne est déjà de type date
        // et indexée (idx_guests_dob) — enrober la colonne dans une fonction
        // empêcherait le planificateur d'utiliser l'index. La borne protège
        // d'un cas pathologique (date de naissance par défaut saisie en masse).
        return Guest::query()
            ->where('date_of_birth', substr((string) $data['date_of_birth'], 0, 10))
            ->limit(200)
            ->get()
            ->filter(fn (Guest $g) => static::comparableName($g->last_name) === static::comparableName($data['last_name'])
                && static::comparableName($g->first_name) === static::comparableName($data['first_name'] ?? null))
            ->values();
    }

    /**
     * Table alpha-2 → alpha-3.
     *
     * Source d'autorité : la table `countries` (celle qui alimente déjà les
     * libellés de nationalité des fiches). Elle est complétée par le repli
     * statique ci-dessous, qui couvre les provenances réellement vues dans une
     * réception tunisienne — indispensable tant que la table n'est pas
     * disponible (migrations, tests, base fraîche) : sans lui, un passeport lu
     * « DE » et une saisie « DEU » redeviendraient deux documents distincts,
     * c'est-à-dire exactement le doublon qu'on cherche à supprimer.
     *
     * Le cache n'est JAMAIS peuplé avec un résultat vide : une lecture faite
     * pendant une migration figerait sinon une table creuse pour une heure.
     *
     * @return array<string,string>
     */
    private static function alpha2ToAlpha3(): array
    {
        $cached = Cache::get('countries.alpha2_to_alpha3');

        if (is_array($cached) && $cached !== []) {
            return $cached + self::FALLBACK_ALPHA3;
        }

        try {
            $map = Country::query()->pluck('alpha3', 'code')->all();
        } catch (\Throwable) {
            // Table absente (migration initiale) — le repli suffit.
            $map = [];
        }

        if ($map !== []) {
            Cache::put('countries.alpha2_to_alpha3', $map, 3600);
        }

        return $map + self::FALLBACK_ALPHA3;
    }

    /**
     * Repli hors base — Maghreb, Europe et principaux marchés touristiques.
     *
     * @var array<string,string>
     */
    private const FALLBACK_ALPHA3 = [
        'TN' => 'TUN', 'DZ' => 'DZA', 'MA' => 'MAR', 'LY' => 'LBY', 'EG' => 'EGY',
        'FR' => 'FRA', 'DE' => 'DEU', 'IT' => 'ITA', 'ES' => 'ESP', 'PT' => 'PRT',
        'BE' => 'BEL', 'NL' => 'NLD', 'LU' => 'LUX', 'CH' => 'CHE', 'AT' => 'AUT',
        'GB' => 'GBR', 'IE' => 'IRL', 'DK' => 'DNK', 'SE' => 'SWE', 'NO' => 'NOR',
        'FI' => 'FIN', 'IS' => 'ISL', 'PL' => 'POL', 'CZ' => 'CZE', 'SK' => 'SVK',
        'HU' => 'HUN', 'RO' => 'ROU', 'BG' => 'BGR', 'GR' => 'GRC', 'HR' => 'HRV',
        'SI' => 'SVN', 'RS' => 'SRB', 'UA' => 'UKR', 'RU' => 'RUS', 'TR' => 'TUR',
        'US' => 'USA', 'CA' => 'CAN', 'BR' => 'BRA', 'AR' => 'ARG', 'MX' => 'MEX',
        'CN' => 'CHN', 'JP' => 'JPN', 'KR' => 'KOR', 'IN' => 'IND', 'AU' => 'AUS',
        'NZ' => 'NZL', 'ZA' => 'ZAF', 'SN' => 'SEN', 'CI' => 'CIV', 'ML' => 'MLI',
        'NE' => 'NER', 'NG' => 'NGA', 'CM' => 'CMR', 'GA' => 'GAB', 'CD' => 'COD',
        'SA' => 'SAU', 'AE' => 'ARE', 'QA' => 'QAT', 'KW' => 'KWT', 'BH' => 'BHR',
        'OM' => 'OMN', 'JO' => 'JOR', 'LB' => 'LBN', 'SY' => 'SYR', 'IQ' => 'IRQ',
        'PS' => 'PSE', 'YE' => 'YEM', 'SD' => 'SDN', 'MR' => 'MRT', 'IL' => 'ISR',
    ];
}
