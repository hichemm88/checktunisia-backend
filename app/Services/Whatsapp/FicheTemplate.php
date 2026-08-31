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
 * Changement de conception assumé par rapport au relais WhatsApp Web : la
 * photo du document ne part PLUS dans WhatsApp. Un modèle ne porte qu'un seul
 * média et l'envoi hors fenêtre de 24 h impose le modèle ; le destinataire
 * consulte la fiche complète, pièces comprises, derrière le bouton.
 */
class FicheTemplate
{
    /** Nombre de variables du corps, tel qu'approuvé chez Meta. */
    public const BODY_VARIABLES = 8;

    /**
     * Variables de la fiche d'un voyageur.
     *
     * @param  string  $ficheToken  jeton public de la ligne d'envoi (suffixe du bouton)
     * @return array{header: array<int,string>, body: array<int,string>, button: array<int,string>}
     */
    public static function params(CheckIn $checkIn, Guest $guest, string $ficheToken): array
    {
        $fields = FicheFormatter::fields($checkIn, $guest);

        $companions = $checkIn->guests->reject(fn ($g) => $g->id === $guest->id);
        $companionText = $companions->isEmpty()
            ? 'Aucun'
            : $companions->count().' ('.$fields['companions'].')';

        return [
            'header' => [
                self::clean(mb_strtoupper((string) ($checkIn->hotel?->name ?? 'Propriété'))),
            ],
            'body' => [
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
     * Le modèle étant figé, un test ne peut pas préfixer « [TEST] » dans le
     * corps sans changer les variables : la mention part donc là où elle reste
     * lisible, dans le nom d'établissement de l'en-tête.
     *
     * @return array{header: array<int,string>, body: array<int,string>, button: array<int,string>}
     */
    public static function testParams(?string $propertyName = null, string $ficheToken = 'test'): array
    {
        $now = now('Africa/Tunis');

        return [
            'header' => ['[TEST] '.mb_strtoupper($propertyName ?? 'QAYED DEMO')],
            'body' => [
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
     * @return array{header: array<int,string>, body: array<int,string>, button: array<int,string>}|null
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
     * @param  array{header?: array<int,string>, body?: array<int,string>, button?: array<int,string>}  $params
     * @return array<int,array<string,mixed>>
     */
    public static function components(array $params): array
    {
        $components = [];

        if (! empty($params['header'])) {
            $components[] = [
                'type' => 'header',
                'parameters' => self::textParameters($params['header']),
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
        $flat = preg_replace('/\s+/u', ' ', (string) $value);
        $flat = trim((string) $flat);

        return $flat !== '' ? $flat : '—';
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
