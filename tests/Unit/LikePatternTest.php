<?php

namespace Tests\Unit;

use App\Support\LikePattern;
use PHPUnit\Framework\TestCase;

/**
 * L'échappement des jokers LIKE, et le piège de l'ordre des remplacements.
 */
class LikePatternTest extends TestCase
{
    public function test_wildcards_lose_their_meaning(): void
    {
        $this->assertSame('\%', LikePattern::escape('%'));
        $this->assertSame('\_', LikePattern::escape('_'));
        $this->assertSame('Sfa\_', LikePattern::escape('Sfa_'));
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        // Un correctif d'échappement qui abîme les valeurs normales serait
        // remplacé par le premier qui trouve un nom de ville déformé.
        $this->assertSame('Grand Tunis', LikePattern::escape('Grand Tunis'));
        $this->assertSame("Béja-l'Ouest", LikePattern::escape("Béja-l'Ouest"));
    }

    public function test_the_escape_character_is_processed_first(): void
    {
        /*
         * Le piège : si l'on échappait `%` et `_` AVANT l'antislash, on
         * ré-échapperait les antislashes qu'on vient d'introduire.
         *
         * « 100%\ » deviendrait « 100\\%\\ » — soit un antislash littéral
         * suivi d'un `%` REDEVENU joker. L'échappement se retournerait alors
         * contre lui-même, précisément sur une valeur qui contient déjà les
         * deux caractères.
         */
        $this->assertSame('100\\\\\\%', LikePattern::escape('100\\%'));
    }

    public function test_contains_wraps_an_escaped_value(): void
    {
        // Les `%` de bordure restent des jokers : c'est tout l'objet d'un
        // motif « contient ». Seuls ceux de la VALEUR sont neutralisés.
        $this->assertSame('%\%%', LikePattern::contains('%'));
        $this->assertSame('%Tunis%', LikePattern::contains('Tunis'));
    }
}
