<?php

namespace App\Services\OCR;

use App\Models\DocumentScan;

class MrzParser
{
    /**
     * Parse a TD3 MRZ (standard passport — 2 lines of 44 chars).
     * Returns structured data or null on failure.
     */
    public static function parse(string $line1, string $line2): ?array
    {
        $line1 = strtoupper(trim($line1));
        $line2 = strtoupper(trim($line2));

        if (strlen($line1) !== 44 || strlen($line2) !== 44) {
            return null;
        }

        // Document type + issuing country + names (line 1)
        $documentType    = rtrim(substr($line1, 0, 2), '<');
        $issuingCountry  = str_replace('<', '', substr($line1, 2, 3));
        $nameField       = substr($line1, 5, 39);

        // Split names by << separator
        $nameParts = explode('<<', $nameField, 2);
        $lastName  = str_replace('<', ' ', $nameParts[0] ?? '');
        $firstName = str_replace('<', ' ', trim($nameParts[1] ?? '', '<'));

        // Line 2 fields
        $documentNumber = rtrim(substr($line2, 0, 9), '<');
        $nationalityCode = str_replace('<', '', substr($line2, 10, 3));
        $dobRaw          = substr($line2, 13, 6);
        $sex             = substr($line2, 20, 1);
        $expiryRaw       = substr($line2, 21, 6);

        $dateOfBirth = static::parseMrzDate($dobRaw, true);
        $expiryDate  = static::parseMrzDate($expiryRaw, false);

        if (!$dateOfBirth || !$expiryDate) {
            return null;
        }

        return [
            'document_type'        => $documentType,
            'issuing_country_code' => strtoupper($issuingCountry),
            'last_name'            => strtoupper(trim($lastName)),
            'first_name'           => ucwords(strtolower(trim($firstName))),
            'document_number'      => $documentNumber,
            'nationality_code'     => strtoupper($nationalityCode),
            'date_of_birth'        => $dateOfBirth,
            'sex'                  => in_array($sex, ['M', 'F']) ? $sex : 'X',
            'expiry_date'          => $expiryDate,
            'mrz_line1'            => $line1,
            'mrz_line2'            => $line2,
        ];
    }

    private static function parseMrzDate(string $yymmdd, bool $isBirthDate): ?string
    {
        if (!preg_match('/^\d{6}$/', $yymmdd)) return null;

        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);

        if ($mm < 1 || $mm > 12 || $dd < 1 || $dd > 31) return null;

        $currentYear = (int) date('Y');
        $century     = $isBirthDate
            ? ($yy > (int) date('y') ? 1900 : 2000)  // birth: 2-digit > today → 1900s
            : ($yy < 70 ? 2000 : 1900);               // expiry: < 70 → 2000s

        $year = $century + $yy;

        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }

    /**
     * Mock OCR: generates plausible MRZ data from an uploaded image.
     * Used when OCR_DRIVER=mock. Replace with real service in production.
     */
    public static function mockExtract(DocumentScan $scan): array
    {
        // In real implementation, call external OCR API here
        return [
            'status'     => 'completed',
            'confidence' => 0.9512,
            'extracted'  => [
                'document_type'        => 'P',
                'issuing_country_code' => 'TN',
                'last_name'            => 'MOCK',
                'first_name'           => 'Test',
                'document_number'      => 'TN' . rand(1000000, 9999999),
                'nationality_code'     => 'TUN',
                'date_of_birth'        => '1990-01-01',
                'sex'                  => 'M',
                'expiry_date'          => '2030-12-31',
                'mrz_line1'            => 'P<TUNMOCK<<TEST<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<',
                'mrz_line2'            => 'TN1234567<4TUN9001010M3012319<<<<<<<<<<<<2',
            ],
        ];
    }
}
