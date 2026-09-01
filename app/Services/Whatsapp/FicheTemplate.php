<?php

namespace App\Services\Whatsapp;

use App\Models\CheckIn;
use App\Models\Guest;
use App\Models\WhatsappSendLog;

/**
 * Traduit une fiche de police en variables du modèle Cloud API, et ces
 * variables en composants Meta.
 *
 * Pourquoi une classe séparée de FicheFormatter : le texte libre et le modèle
 * approuvé n'obéissent pas aux mêmes règles. FicheFormatter produit un bloc
 * multiligne, lisible tel quel ; un modèle, lui, impose une structure figée à
 * l'approbation et REFUSE certaines valeurs — c'est ce que cette classe
 * garantit :
 *
 *  - aucune variable vide (Meta rejette « » ; on met « — »),
 *  - aucun saut de ligne ni tabulation dans une variable (rejet 132012),
 *  - jamais plus de quatre espaces consécutifs (même rejet).
 *
 * Ces contraintes ne sont pas cosmétiques : une variable mal formée fait
 * échouer la fiche entière, définitivement, pour un espace en trop.
 *
 * ── La forme du message, et pourquoi elle est hybride ──────────────────────
 *
 * Le modèle porte trois choses, chacune pour une raison distincte :
 *
 *  1. Un EN-TÊTE DOCUMENT : le PDF de la fiche, pièce d'identité comprise.
 *     C'est ce qui rétablit la parité avec le relais WhatsApp Web, qui
 *     envoyait la photo du document — et c'est le seul moyen de faire tenir
 *     une fiche multi-ligne dans un modèle, puisqu'aucune variable ne peut
 *     contenir de retour à la ligne.
 *  2. Un CORPS résumé : le destinataire trie dans un fil unique, il doit
 *     savoir de quoi il s'agit sans ouvrir la pièce jointe.
 *  3. Un BOUTON URL : la fiche dans Qayed, pour l'historique et le contexte
 *     que le PDF ne porte pas.
 *
 * Le nom de l'établissement est la PREMIÈRE variable du corps, et non un
 * en-tête texte : l'en-tête est occupé par le document, et un modèle Meta n'en
 * a qu'un.
 */
class FicheTemplate
{
    /** Nombre de variables du corps, tel qu'approuvé chez Meta. */
    public const BODY_VARIABLES = 9;

    /**
     * Longueur maximale d'une variable.
     *
     * Meta plafonne la taille du message rendu ; un nom d'établissement collé
     * depuis un tableur, ou une adresse à rallonge, suffisent à le dépasser et
     * à faire refuser la fiche ENTIÈRE. Couper ici coûte trois points de
     * suspension, ne pas couper coûte la transmission.
     */
    private const MAX_VARIABLE_LENGTH = 200;

    /**
     * Variables de la fiche d'un voyageur.
     *
     * @param  string  $ficheToken  jeton public de la ligne d'envoi (suffixe du bouton)
     * @return array{body: array<int,string>, button: array<int,string>}
     */
    public static function params(CheckIn $checkIn, Guest $guest, string $ficheToken): array
    {
        $fields = FicheFormatter::fields($checkIn, $guest);

        $companions = $checkIn->guests->reject(fn ($g) => $g->id === $guest->id);
        $companionText = $companions->isEmpty()
            ? 'Aucun'
            : $companions->count().' ('.$fields['companions'].')';

        return [
            'body' => [
                // L'établissement d'abord : le destinataire trie sur ce nom
                // dans un fil unique, c'est la première chose qu'il cherche.
                self::clean(mb_strtoupper((string) ($checkIn->hotel?->name ?? 'Propriété'))),
                self::clean(self::address($checkIn)),
                self::clean(trim($fields['last_name'].' '.$fields['first_name'])),
                self::clean($fields['nationality']),
                self::clean($fields['document']),
                self::clean($fields['arrival']),
                self::clean($fields['departure']),
                self::clean($fields['room']),
                self::clean($companionText),
            ],
            /*
             | Suffixe dynamique du bouton URL : le jeton public de CET envoi,
             | pas l'identifiant du voyageur.
             |
             | La base de l'URL est figée à l'approbation Meta. Y mettre une
             | route applicative aurait gravé chez un tiers une adresse que
             | nous voulons faire évoluer ; le jeton pointe sur `/f/{token}`,
             | qui redirige et reste, elle, sous notre contrôle.
             |
             | Le jeton n'autorise rien : la destination exige une session du
             | portail autorité.
             */
            'button' => [
                $ficheToken,
            ],
        ];
    }

    /**
     * Fiche factice du bouton « message test » de l'administration.
     *
     * Le modèle étant figé, la mention « [TEST] » ne peut pas être une ligne
     * de plus : elle est préfixée au nom d'établissement, première variable du
     * corps, où elle reste la première chose lue.
     *
     * @return array{body: array<int,string>, button: array<int,string>}
     */
    public static function testParams(?string $propertyName = null, string $ficheToken = 'test'): array
    {
        $now = now('Africa/Tunis');

        return [
            'body' => [
                // `clean()` ici aussi : le nom vient de la base, il peut
                // contenir un saut de ligne ou une rafale d'espaces. Une fiche
                // de TEST qui échoue en 132012 ferait conclure à une panne du
                // canal — le pire diagnostic possible au moment de la bascule.
                self::clean('[TEST] '.mb_strtoupper((string) ($propertyName ?? 'QAYED DEMO'))),
                '12 rue de l\'Exemple, Tunis',
                'EXEMPLE Voyageur',
                'Tunisie',
                'Passeport n° X0000000',
                $now->format('d/m/Y'),
                $now->copy()->addDays(2)->format('d/m/Y'),
                '000',
                'Aucun',
            ],
            'button' => [$ficheToken],
        ];
    }

    /**
     * Variables d'une ligne de journal, reconstruites depuis les données à
     * jour.
     *
     * Sert de rattrapage pour les lignes enfilées AVANT la migration (elles
     * n'ont qu'une légende texte) et de source unique pour le renvoi manuel :
     * l'hébergeur a pu corriger le nom du voyageur depuis l'enfilage, et c'est
     * la fiche corrigée qui doit partir.
     *
     * @return array{body: array<int,string>, button: array<int,string>}|null
     *                                                                                                   null si la fiche n'est plus reconstituable (check-in ou voyageur supprimé)
     */
    public static function paramsForJob(WhatsappSendLog $job): ?array
    {
        if ($job->is_test) {
            return self::testParams($job->hotel?->name, $job->publicToken());
        }

        if (blank($job->check_in_id) || blank($job->guest_id)) {
            return null;
        }

        $checkIn = CheckIn::with(['hotel.address', 'room', 'guests.documents'])->find($job->check_in_id);
        $guest = $checkIn?->guests->firstWhere('id', $job->guest_id);

        // publicToken() crée le jeton au besoin : les lignes enfilées avant sa
        // mise en place doivent produire un lien qui fonctionne, pas un 404.
        return ($checkIn && $guest) ? self::params($checkIn, $guest, $job->publicToken()) : null;
    }

    /**
     * Variables → composants Meta.
     *
     * Le composant `button` porte `sub_type: url` et `index: "0"` : le modèle
     * n'a qu'un bouton, et Meta indexe les boutons à partir de zéro.
     *
     * L'en-tête est un DOCUMENT, jamais du texte. Il est OBLIGATOIRE : un
     * modèle approuvé avec en-tête média est refusé (132000) si l'envoi ne
     * fournit pas le média. C'est pourquoi `$document` n'a pas de valeur par
     * défaut utilisable — l'appelant doit avoir téléversé la pièce.
     *
     * @param  array{body?: array<int,string>, button?: array<int,string>}  $params
     * @param  array{id: string, filename: string}|null  $document  média téléversé (/media)
     * @return array<int,array<string,mixed>>
     */
    public static function components(array $params, ?array $document = null): array
    {
        $components = [];

        if ($document !== null) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => [
                        'id' => $document['id'],
                        'filename' => $document['filename'],
                    ],
                ]],
            ];
        }

        if (! empty($params['body'])) {
            $components[] = [
                'type' => 'body',
                'parameters' => self::textParameters($params['body']),
            ];
        }

        if (! empty($params['button'])) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => self::textParameters($params['button']),
            ];
        }

        return $components;
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,array<string,string>>
     */
    private static function textParameters(array $values): array
    {
        return array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            array_values($values),
        );
    }

    /**
     * Rend une valeur acceptable comme variable de modèle : sur une seule
     * ligne, sans espaces multiples, jamais vide.
     */
    private static function clean(?string $value): string
    {
        // Un seul passage écrase sauts de ligne, tabulations et espaces
        // multiples : les trois formes que Meta refuse, pour le message
        // ENTIER et pas seulement pour la variable fautive.
        $flat = preg_replace('/\s+/u', ' ', (string) $value);
        $flat = trim((string) $flat);

        if ($flat === '') {
            return '—';
        }

        return mb_strlen($flat) > self::MAX_VARIABLE_LENGTH
            ? mb_substr($flat, 0, self::MAX_VARIABLE_LENGTH - 1).'…'
            : $flat;
    }

    /**
     * Adresse de l'établissement sur une ligne.
     *
     * FicheFormatter garde la sienne privée (elle sert son propre rendu) ; on
     * ne l'expose pas pour autant : cette version doit rester monoligne quoi
     * qu'il arrive, contrainte propre au modèle.
     */
    private static function address(CheckIn $checkIn): string
    {
        $addr = $checkIn->hotel?->address;

        if (! $addr) {
            return '—';
        }

        $street = implode(', ', array_filter([$addr->line1, $addr->line2]));
        $locality = implode(', ', array_filter([$addr->city, $addr->governorate]));

        if ($addr->postal_code) {
            $locality = trim($locality.' '.$addr->postal_code);
        }

        $full = trim(implode(' - ', array_filter([$street, $locality])), " -\t\n");

        return $full !== '' ? $full : '—';
    }
}
