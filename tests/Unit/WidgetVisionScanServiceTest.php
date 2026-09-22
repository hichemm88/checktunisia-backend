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

    // ── Garde-fou sur la forme du nom ────────────────────────────────────────

    public function test_a_free_reading_name_that_looks_like_a_printed_label_is_nulled_not_trusted(): void
    {
        // Incident réel : la fiche transmise portait ce texte comme nom, lu à
        // la place de la légende imprimée « Autoridade/Authority » sur un
        // passeport brésilien. Aucun chiffre de contrôle MRZ ne protège le
        // nom — c'est la FORME du texte qui doit être jugée ici.
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.6,
            'first_name' => null,
            'last_name' => 'ABEEXPEDIGAQIDATEOFISSUEAUTORIDADEAUTHO',
            'date_of_birth' => '1994-07-21',
            'sex' => 'F',
            'nationality_code' => 'BRA',
            'document_type' => 'passport',
            'document_number' => 'FW972947',
            'issuing_country_code' => 'BRA',
            'expiry_date' => '2028-09-13',
            'mrz_line1' => null,
            'mrz_line2' => null,
        ]);

        $this->assertNull($extracted['last_name']);
        // Les autres champs, non concernés, restent intacts.
        $this->assertSame('FW972947', $extracted['document_number']);
        $this->assertSame('1994-07-21', $extracted['date_of_birth']);
    }

    public function test_a_name_decoded_from_a_valid_mrz_pair_is_still_checked_for_plausibility(): void
    {
        // Même incident, mais cette fois le texte d'étiquette est allé jusque
        // dans les 44 caractères de la ligne 1 — MrzParser::parse() le
        // décoderait tel quel sans ce garde-fou APRÈS la fusion.
        $garbledSurname = substr('ABEEXPEDIGAQIDATEOFISSUEAUTORIDADEAUTHO', 0, 39);
        $line1 = str_pad('P<BRA'.$garbledSurname, 44, '<');
        $line2 = str_pad('FW972947<0BRA9407210F2809130', 44, '<');

        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.6,
            'first_name' => 'Lecture approximative',
            'last_name' => 'Illisible',
            'date_of_birth' => null,
            'sex' => null,
            'nationality_code' => null,
            'document_type' => 'passport',
            'document_number' => null,
            'issuing_country_code' => null,
            'expiry_date' => null,
            'mrz_line1' => $line1,
            'mrz_line2' => $line2,
        ]);

        // La fusion MRZ a bien remplacé la lecture libre (numéro repris de la
        // ligne 2)... mais le nom qu'elle a produit reste implausible, et
        // doit donc être nullifié comme n'importe quelle autre source.
        $this->assertSame('FW972947', $extracted['document_number']);
        $this->assertNull($extracted['last_name']);
    }

    public function test_identical_first_and_last_names_are_nulled_even_when_individually_plausible(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.6,
            'first_name' => 'Trabelsi',
            'last_name' => 'Trabelsi',
            'date_of_birth' => null,
            'sex' => null,
            'nationality_code' => 'TUN',
            'document_type' => 'national_id',
            'document_number' => '12345678',
            'issuing_country_code' => 'TUN',
            'expiry_date' => null,
            'mrz_line1' => null,
            'mrz_line2' => null,
        ]);

        $this->assertNull($extracted['first_name']);
        $this->assertNull($extracted['last_name']);
    }

    public function test_an_ordinary_real_looking_name_is_left_untouched(): void
    {
        $extracted = WidgetVisionScanService::normalize([
            'found' => true,
            'confidence' => 0.9,
            'first_name' => 'Anna Maria',
            'last_name' => "Da Silva Hoepers",
            'date_of_birth' => null,
            'sex' => null,
            'nationality_code' => 'BRA',
            'document_type' => 'passport',
            'document_number' => null,
            'issuing_country_code' => 'BRA',
            'expiry_date' => null,
            'mrz_line1' => null,
            'mrz_line2' => null,
        ]);

        $this->assertSame('Anna Maria', $extracted['first_name']);
        $this->assertSame('Da Silva Hoepers', $extracted['last_name']);
    }
}
