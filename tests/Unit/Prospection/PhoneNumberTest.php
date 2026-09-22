<?php

namespace Tests\Unit\Prospection;

use App\Support\Prospection\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_it_normalizes_valid_tunisian_numbers_to_e164(string $raw, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::toTunisianE164($raw));
    }

    public static function validNumbers(): array
    {
        return [
            '8 digits alone' => ['20123456', '+21620123456'],
            '8 digits with spaces' => ['20 123 456', '+21620123456'],
            '8 digits with dashes' => ['20-123-456', '+21620123456'],
            'already E.164' => ['+21620123456', '+21620123456'],
            'E.164 with spaces' => ['+216 20 123 456', '+21620123456'],
            '216 prefix without plus' => ['21620123456', '+21620123456'],
            '00216 international prefix' => ['0021620123456', '+21620123456'],
            'stray leading zero' => ['020123456', '+21620123456'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_it_rejects_numbers_it_cannot_interpret(?string $raw): void
    {
        $this->assertNull(PhoneNumber::toTunisianE164($raw));
    }

    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too short' => ['1234'],
            'too long' => ['123456789012'],
            'foreign number' => ['+33612345678'],
            'letters' => ['abcdefgh'],
        ];
    }

    public function test_is_valid_tunisian_e164(): void
    {
        $this->assertTrue(PhoneNumber::isValidTunisianE164('+21620123456'));
        $this->assertFalse(PhoneNumber::isValidTunisianE164('+33612345678'));
        $this->assertFalse(PhoneNumber::isValidTunisianE164(null));
    }
}
