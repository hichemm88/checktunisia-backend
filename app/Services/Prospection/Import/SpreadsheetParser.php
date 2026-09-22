<?php

namespace App\Services\Prospection\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

/**
 * Lit un fichier CSV ou XLSX et retourne des lignes déjà réassociées aux
 * champs canoniques (via ColumnMapper), prêtes pour RowNormalizer.
 */
class SpreadsheetParser
{
    /**
     * @return array{
     *     unmapped_columns: list<string>,
     *     rows: list<array{row_number: int, raw: array<string, string|null>}>,
     * }
     */
    public static function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        $isCsv = in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true);

        $reader = $isCsv ? self::csvReader($path) : IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $sheetRows = $sheet->toArray(null, true, true, false);
        if (count($sheetRows) === 0) {
            return ['unmapped_columns' => [], 'rows' => []];
        }

        $headers = array_map(fn ($h) => trim((string) $h), array_shift($sheetRows));
        $mapping = ColumnMapper::map($headers);

        $rows = [];
        foreach ($sheetRows as $i => $line) {
            // Ligne entièrement vide (fin de tableau Excel avec cellules
            // fantômes) : ignorée silencieusement, ce n'est pas une erreur.
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $raw = [];
            foreach ($mapping['mapped'] as $colIndex => $field) {
                if ($field === null) {
                    continue;
                }
                $raw[$field] = isset($line[$colIndex]) ? trim((string) $line[$colIndex]) : null;
            }

            // +2 : +1 pour l'index 0-based, +1 pour la ligne d'en-tête déjà consommée.
            $rows[] = ['row_number' => $i + 2, 'raw' => $raw];
        }

        return ['unmapped_columns' => $mapping['unmapped'], 'rows' => $rows];
    }

    /**
     * Détecte le séparateur ("," ou ";", les exports Excel français utilisent
     * souvent ce dernier) en comptant les occurrences sur la première ligne.
     */
    private static function csvReader(string $path): Csv
    {
        $reader = new Csv;

        $firstLine = '';
        $handle = fopen($path, 'r');
        if ($handle) {
            $firstLine = (string) fgets($handle);
            fclose($handle);
        }

        $reader->setDelimiter(substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');
        $reader->setInputEncoding(Csv::guessEncoding($path) ?: 'UTF-8');

        return $reader;
    }
}
