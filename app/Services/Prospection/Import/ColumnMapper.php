<?php

namespace App\Services\Prospection\Import;

/**
 * Reconnaissance tolérante des en-têtes du fichier existant (§ Écran 7
 * Import : "Mapping tolérant (en-têtes approximatifs)"). Le fichier réel
 * n'utilise pas forcément l'orthographe ni la ponctuation exactes du prompt
 * ("Numéro WhatsApp" vs "N° whatsapp" vs "Whatsapp") : chaque en-tête est
 * normalisé (accents, casse, ponctuation) puis comparé à une liste de
 * synonymes par champ.
 */
class ColumnMapper
{
    /** @var array<string, list<string>> champ canonique => synonymes normalisés */
    private const SYNONYMS = [
        'name' => ['nom', 'etablissement', 'nom etablissement', 'name'],
        'whatsapp_phone' => ['numero whatsapp', 'whatsapp', 'numero', 'telephone', 'tel', 'numero de telephone'],
        'address' => ['adresse repere', 'adresse', 'repere', 'adresse / repere'],
        'size' => ['taille estimee', 'taille'],
        'segment' => ['segment'],
        'decision_maker' => ['decideur contact', 'decideur', 'contact', 'decideur / contact'],
        'origin_channel' => ['canal pour trouver le numero', 'canal', 'source'],
        'qualification_notes' => ['notes de qualification', 'notes'],
        'status' => ['statut', 'status'],
        'last_action' => ['derniere action'],
        'next_action_at' => ['date relance', 'relance', 'prochaine action', 'date de relance'],
        'objections' => ['objections retours', 'objections', 'retours', 'objections / retours'],
    ];

    /**
     * @param  list<string>  $headers  En-têtes brutes de la première ligne.
     * @return array{mapped: array<int, string|null>, unmapped: list<string>}
     *                                                                        'mapped' associe l'INDEX de colonne au champ canonique (ou null
     *                                                                        si non reconnue) ; 'unmapped' liste les en-têtes non reconnues
     *                                                                        (affichées à l'utilisateur avant validation de l'import).
     */
    public static function map(array $headers): array
    {
        $mapped = [];
        $unmapped = [];
        $used = [];

        foreach ($headers as $index => $header) {
            $normalized = self::normalize($header);
            $field = null;

            foreach (self::SYNONYMS as $canonical => $synonyms) {
                if (isset($used[$canonical])) {
                    continue;
                }
                if (in_array($normalized, $synonyms, true)) {
                    $field = $canonical;
                    break;
                }
            }

            $mapped[$index] = $field;

            if ($field) {
                $used[$field] = true;
            } elseif (trim((string) $header) !== '') {
                $unmapped[] = $header;
            }
        }

        return ['mapped' => $mapped, 'unmapped' => $unmapped];
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        // Translittération basique des accents français les plus courants
        // (iconv/Transliterator ne sont pas garantis disponibles partout).
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        // Toute ponctuation devient un espace, puis les espaces multiples
        // sont réduits à un seul : "Adresse / Repère" et "Adresse - Repère"
        // normalisent tous deux vers "adresse repere".
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
