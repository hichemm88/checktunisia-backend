<?php

namespace Tests\Unit;

use App\Services\OCR\NamePlausibility;
use Tests\TestCase;

/**
 * Voir App\Services\OCR\NamePlausibility pour le contexte : aucun chiffre de
 * contrôle MRZ ne protège le nom, ce filtre sur la FORME du texte est le seul
 * garde-fou possible.
 */
class NamePlausibilityTest extends TestCase
{
    public function test_flags_the_exact_string_from_the_production_incident(): void
    {
        $this->assertTrue(NamePlausibility::isSuspicious('ABEEXPEDIGAQIDATEOFISSUEAUTORIDADEAUTHO'));
    }

    public function test_flags_a_single_unbroken_token_far_longer_than_any_real_name_component(): void
    {
        $this->assertTrue(NamePlausibility::isSuspicious(str_repeat('A', 35)));
    }

    public function test_flags_a_field_with_digits_or_symbols(): void
    {
        $this->assertTrue(NamePlausibility::isSuspicious('DUPONT123'));
        $this->assertTrue(NamePlausibility::isSuspicious('DUPONT/JEAN'));
    }

    public function test_does_not_flag_ordinary_real_world_names(): void
    {
        foreach ([
            'BEN SALAH', 'DA SILVA HOEPERS', "O'BRIEN", 'JEAN-CLAUDE', 'ERIKSSON',
            'ANNA MARIA', 'AL FOULANI', 'MARIE-ANGE DE LA FONTAINE', 'MOHAMED',
        ] as $name) {
            $this->assertFalse(NamePlausibility::isSuspicious($name), "\"$name\" ne devrait pas être suspect");
        }
    }

    public function test_does_not_flag_surnames_that_merely_contain_a_short_blacklisted_looking_substring(): void
    {
        // Vrais patronymes anglais contenant « SEX » — exactement pourquoi la
        // liste ne retient que des mots longs et distinctifs.
        $this->assertFalse(NamePlausibility::isSuspicious('ESSEX'));
        $this->assertFalse(NamePlausibility::isSuspicious('SUSSEX'));
    }

    public function test_treats_an_absent_or_empty_field_as_not_suspicious(): void
    {
        $this->assertFalse(NamePlausibility::isSuspicious(null));
        $this->assertFalse(NamePlausibility::isSuspicious(''));
        $this->assertFalse(NamePlausibility::isSuspicious('   '));
    }

    public function test_same_flags_two_fields_identical_once_trimmed_and_uppercased(): void
    {
        $this->assertTrue(NamePlausibility::same('DUPONT', 'dupont'));
        $this->assertTrue(NamePlausibility::same(' Dupont ', 'DUPONT'));
    }

    public function test_same_does_not_flag_two_different_names_or_an_absent_side(): void
    {
        $this->assertFalse(NamePlausibility::same('DUPONT', 'MARTIN'));
        $this->assertFalse(NamePlausibility::same(null, 'DUPONT'));
        $this->assertFalse(NamePlausibility::same('DUPONT', null));
    }
}
