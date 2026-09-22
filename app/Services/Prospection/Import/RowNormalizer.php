<?php

namespace App\Services\Prospection\Import;

use App\Services\Prospection\PipelineStatus;
use App\Support\Prospection\PhoneNumber;
use Carbon\Carbon;

/**
 * Convertit une ligne brute du fichier (valeurs texte, libellés français
 * approximatifs) en champs Establishment normalisés.
 */
class RowNormalizer
{
    /**
     * @param  array<string, string|null>  $raw  Champ canonique => valeur brute (voir ColumnMapper).
     * @return array{fields: array<string, mixed>, issues: list<string>}
     */
    public static function normalize(array $raw): array
    {
        $issues = [];

        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $issues[] = 'Nom manquant — ligne ignorée.';
        }

        $phoneRaw = trim((string) ($raw['whatsapp_phone'] ?? ''));
        $phone = $phoneRaw !== '' ? PhoneNumber::toTunisianE164($phoneRaw) : null;
        if ($phoneRaw !== '' && !$phone) {
            $issues[] = "Numéro WhatsApp non reconnu : « {$phoneRaw} ».";
        }

        $nextActionAt = self::parseDate($raw['next_action_at'] ?? null);
        if (!empty($raw['next_action_at']) && !$nextActionAt) {
            $issues[] = "Date de relance non reconnue : « {$raw['next_action_at']} ».";
        }

        $notes = trim((string) ($raw['qualification_notes'] ?? ''));
        $objections = trim((string) ($raw['objections'] ?? ''));
        if ($objections !== '') {
            $notes = trim($notes."\n\nObjections/retours importés : ".$objections);
        }

        return [
            'fields' => [
                'name' => $name,
                'whatsapp_phone' => $phone,
                'address' => self::blankToNull($raw['address'] ?? null),
                'size' => self::mapSize($raw['size'] ?? null),
                'segment' => self::mapSegment($raw['segment'] ?? null),
                'decision_maker_name' => self::blankToNull($raw['decision_maker'] ?? null),
                'origin_channel' => self::blankToNull($raw['origin_channel'] ?? null),
                'qualification_notes' => $notes !== '' ? $notes : null,
                'status' => self::mapStatus($raw['status'] ?? null),
                'next_action_at' => $nextActionAt,
                'last_action_note' => self::blankToNull($raw['last_action'] ?? null),
            ],
            'issues' => $issues,
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function parseDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)?->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function mapSize(?string $value): ?string
    {
        $v = ColumnMapper::normalize((string) $value);
        if ($v === '') {
            return null;
        }
        if (str_contains($v, 'petit')) {
            return 'petite';
        }
        if (str_contains($v, 'moyen')) {
            return 'moyenne';
        }
        if (str_contains($v, 'grand')) {
            return 'grande';
        }

        return null;
    }

    private static function mapSegment(?string $value): ?string
    {
        $v = ColumnMapper::normalize((string) $value);
        if ($v === '') {
            return null;
        }

        return match (true) {
            str_contains($v, 'maison') => 'maison_hotes',
            str_contains($v, 'guesthouse'), str_contains($v, 'auberge') => 'guesthouse',
            str_contains($v, 'boutique') => 'boutique_hotel',
            str_contains($v, 'location') => 'location_entiere',
            str_contains($v, 'hotel') => 'hotel',
            default => 'autre',
        };
    }

    private static function mapStatus(?string $value): string
    {
        $v = ColumnMapper::normalize((string) $value);
        if ($v === '') {
            return PipelineStatus::A_CONTACTER;
        }

        return match (true) {
            str_contains($v, 'sans reponse') => PipelineStatus::SANS_REPONSE,
            str_contains($v, 'hors perimetre') => PipelineStatus::HORS_PERIMETRE,
            str_contains($v, 'refus') => PipelineStatus::REFUS,
            str_contains($v, 'client') => PipelineStatus::CLIENT,
            str_contains($v, 'essai') => PipelineStatus::ESSAI_EN_COURS,
            str_contains($v, 'demo') && str_contains($v, 'fait') => PipelineStatus::DEMO_FAITE,
            str_contains($v, 'demo') => PipelineStatus::DEMO_PLANIFIEE,
            str_contains($v, 'relance') => PipelineStatus::RELANCE,
            // AVANT le "contacte" générique ci-dessous : "à contacter" (a
            // contacter, une fois les accents supprimés) contient lui-même
            // "contacte" comme préfixe de "contacter" — sans cette garde, "à
            // contacter" serait mappé à tort sur le statut "Contacté".
            str_contains($v, 'a contacter') => PipelineStatus::A_CONTACTER,
            str_contains($v, 'contacte') => PipelineStatus::CONTACTE,
            default => PipelineStatus::A_CONTACTER,
        };
    }
}
