<?php

namespace Tests\Unit;

use App\Services\OCR\WidgetVisionScanService;
use Tests\TestCase;

/**
 * Fusion MRZ ↔ lecture libre du modèle (voir WidgetVisionScanService::normalize).
 * Partie pure, éprouvée sans appeler quoi que ce soit (même discipline que
 * FicheScanCropperTest pour le cadrage).
 */
class WidgetVisionScanServiceTest extends TestCase
{
    private const MRZ_LINE_1 = 'P<TUNBEN<ALI<<MOHAMED<<<<<<<<<<<<<<<<<<<<<<<';

    private const MRZ_LINE_2 = 'TN12345674TUN9001010M3012319<<<<<<<<<<<<<<<<';

    public function test_a_valid_mrz_pair_overrides_the_free_reading_with_the_deterministic_decode(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.7,
            'first_name' => 'Lecture approximative',
            'last_name' => 'Illisible',
            'date_of_birth' => null,
            'sex' => null,
            'nationality_code' => null,
            'document_type' => 'passport',
            'document_number' => null,
            'issuing_country_code' => null,
            'expiry_date' => null,
            'mrz_line1' => self::MRZ_LINE_1,
            'mrz_line2' => self::MRZ_LINE_2,
        ]);

        $this->assertSame('TN1234567', $extracted['document_number']);
        $this->assertSame('1990-01-01', $extracted['date_of_birth']);
        $this->assertSame('M', $extracted['sex']);
        $this->assertSame('TUN', $extracted['nationality_code']);
        $this->assertSame('passport', $extracted['document_type']);
    }

    public function test_without_a_readable_mrz_the_free_reading_is_kept_as_is(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.85,
            'first_name' => 'Amine',
            'last_name' => 'Trabelsi',
            'date_of_birth' => '1988-05-12',
            'sex' => 'M',
            'nationality_code' => 'TUN',
            'document_type' => 'national_id',
            'document_number' => '12345678',
            'issuing_country_code' => 'TUN',
            'expiry_date' => null,
            'mrz_line1' => null,
            'mrz_line2' => null,
        ]);

        $this->assertSame('Amine', $extracted['first_name']);
        $this->assertSame('12345678', $extracted['document_number']);
        $this->assertSame('national_id', $extracted['document_type']);
    }

    public function test_an_implausible_mrz_pair_is_ignored_rather_than_crashing(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.5,
            'first_name' => 'Amine',
            'last_name' => 'Trabelsi',
            'date_of_birth' => null,
            'sex' => null,
            'nationality_code' => null,
            'document_type' => 'passport',
            'document_number' => null,
            'issuing_country_code' => null,
            'expiry_date' => null,
            'mrz_line1' => 'trop court',
            'mrz_line2' => 'trop court aussi',
        ]);

        $this->assertSame('Amine', $extracted['first_name']);
        $this->assertNull($extracted['document_number']);
    }

    public function test_an_unknown_document_type_or_sex_value_is_dropped_rather_than_trusted(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.5,
            'first_name' => 'Amine',
            'last_name' => 'Trabelsi',
            'date_of_birth' => null,
            'sex' => 'inconnu',
            'nationality_code' => 'TUN',
            'document_type' => 'permis de conduire',
            'document_number' => '123',
            'issuing_country_code' => 'TUN',
            'expiry_date' => null,
            'mrz_line1' => null,
            'mrz_line2' => null,
        ]);

        $this->assertNull($extracted['sex']);
        $this->assertNull($extracted['document_type']);
    }
}
