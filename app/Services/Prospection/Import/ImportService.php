<?php

namespace App\Services\Prospection\Import;

use App\Models\Prospection\Establishment;
use App\Models\Prospection\ProspectionAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Import du fichier existant (§ Écran 7). Deux passes distinctes :
 *
 * - preview() ne persiste RIEN : elle sert à afficher l'aperçu et détecter
 *   les doublons par nom avant toute validation humaine.
 * - commit() persiste, en respectant la décision prise par ligne
 *   ('create' | 'merge' | 'skip', voir $resolutions) — et reste IDEMPOTENT
 *   même si un 'create' est rejoué deux fois : un nom déjà présent au
 *   moment d'écrire retombe automatiquement sur une mise à jour plutôt que
 *   de lever une violation d'unicité.
 */
class ImportService
{
    public static function preview(UploadedFile $file): array
    {
        $parsed = SpreadsheetParser::parse($file);
        $rows = [];

        foreach ($parsed['rows'] as $row) {
            $normalized = RowNormalizer::normalize($row['raw']);
            $name = $normalized['fields']['name'];
            $existing = $name !== '' ? self::findByName($name) : null;

            $rows[] = [
                'row_number' => $row['row_number'],
                'fields' => $normalized['fields'],
                'issues' => $normalized['issues'],
                'is_duplicate' => $existing !== null,
                'duplicate_of' => $existing ? ['id' => $existing->id, 'name' => $existing->name] : null,
            ];
        }

        return [
            'unmapped_columns' => $parsed['unmapped_columns'],
            'rows' => $rows,
            'total' => count($rows),
            'duplicates' => count(array_filter($rows, fn ($r) => $r['is_duplicate'])),
        ];
    }

    /**
     * @param  array<int, string>  $resolutions  row_number => 'create'|'merge'|'skip'.
     * @param  string|null  $userId  Créateur des lignes et de leurs actions initiales.
     */
    public static function commit(UploadedFile $file, array $resolutions, ?string $userId): array
    {
        $parsed = SpreadsheetParser::parse($file);

        $created = 0;
        $merged = 0;
        $skipped = 0;

        foreach ($parsed['rows'] as $row) {
            $normalized = RowNormalizer::normalize($row['raw']);
            $fields = $normalized['fields'];
            $name = $fields['name'];

            if ($name === '') {
                $skipped++;

                continue;
            }

            $existing = self::findByName($name);
            $resolution = $resolutions[$row['row_number']] ?? ($existing ? 'skip' : 'create');

            if ($resolution === 'skip') {
                $skipped++;

                continue;
            }

            DB::connection('prospection')->transaction(function () use ($fields, $existing, $userId, &$created, &$merged) {
                if ($existing) {
                    self::mergeInto($existing, $fields);
                    $merged++;

                    return;
                }

                try {
                    $establishment = Establishment::create(self::forCreate($fields, $userId));
                    self::recordImportedLastAction($establishment, $fields, $userId);
                    $created++;
                } catch (UniqueConstraintViolationException) {
                    // Idempotence : la ligne a déjà été créée par un commit
                    // précédent (même import rejoué) — on fusionne au lieu
                    // d'échouer.
                    $again = self::findByName($fields['name']);
                    if ($again) {
                        self::mergeInto($again, $fields);
                        $merged++;
                    }
                }
            });
        }

        return ['created' => $created, 'merged' => $merged, 'skipped' => $skipped];
    }

    private static function findByName(string $name): ?Establishment
    {
        return Establishment::whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])->first();
    }

    /** @param array<string, mixed> $fields */
    private static function forCreate(array $fields, ?string $userId): array
    {
        $data = collect($fields)->except(['last_action_note'])->toArray();
        $data['created_by'] = $userId;

        return $data;
    }

    /** @param array<string, mixed> $fields */
    private static function mergeInto(Establishment $establishment, array $fields): void
    {
        // Fusion : seuls les champs NON VIDES de l'import écrasent l'existant
        // — un import ne doit jamais effacer une donnée déjà qualifiée à la
        // main avec du vide venu du fichier.
        foreach (collect($fields)->except(['last_action_note', 'name'])->toArray() as $key => $value) {
            if ($value !== null && $value !== '') {
                $establishment->{$key} = $value;
            }
        }
        $establishment->save();

        self::recordImportedLastAction($establishment, $fields, $establishment->created_by);
    }

    /** @param array<string, mixed> $fields */
    private static function recordImportedLastAction(Establishment $establishment, array $fields, ?string $userId): void
    {
        if (empty($fields['last_action_note'])) {
            return;
        }

        // Idempotence : ne pas recréer la même note d'import à chaque
        // réexécution du même fichier.
        $alreadyLogged = $establishment->actions()
            ->where('type', 'note')
            ->where('content', $fields['last_action_note'])
            ->exists();

        if ($alreadyLogged) {
            return;
        }

        ProspectionAction::create([
            'establishment_id' => $establishment->id,
            'type' => 'note',
            'content' => $fields['last_action_note'],
            'occurred_at' => $establishment->created_at ?? now(),
            'created_by' => $userId,
        ]);
    }
}
