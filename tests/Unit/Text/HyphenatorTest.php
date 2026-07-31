<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\Hyphenator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Hyphenator::class)]
final class HyphenatorTest extends TestCase
{
    #[Test]
    public function a_pattern_casts_an_odd_vote_that_permits_a_break(): void
    {
        // "a1b" votes odd in the a|b gap; with min 1/1 the only break is at 2.
        $h = new Hyphenator('a1b', '', 1, 1);

        self::assertSame([2], $h->hyphenate('aabb'));
    }

    #[Test]
    public function an_even_vote_from_another_pattern_vetoes_an_odd_one(): void
    {
        // "b2c" (even) wins over "b1c" (odd) at the same gap → no break there.
        $odd = new Hyphenator('b1c', '', 1, 1);
        $vetoed = new Hyphenator('b1c b2c', '', 1, 1);

        self::assertSame([2], $odd->hyphenate('abcd'));
        self::assertSame([], $vetoed->hyphenate('abcd'));
    }

    #[Test]
    public function an_exception_overrides_the_patterns(): void
    {
        $h = new Hyphenator('', 'ta-ble', 1, 1);

        self::assertSame([2], $h->hyphenate('table'));
    }

    #[Test]
    public function it_respects_left_and_right_minimums(): void
    {
        // "memory" as an exception breaks at 3 and 4; rightMin 3 drops the 4.
        $h = new Hyphenator('', 'mem-o-ry', 2, 3);

        self::assertSame([3], $h->hyphenate('memory'));
    }

    #[Test]
    public function it_skips_short_mixed_case_and_non_alpha_words(): void
    {
        $h = new Hyphenator('', 'ta-ble', 1, 1);

        self::assertSame([], $h->hyphenate('a1b2'));      // not alphabetic
        self::assertSame([2], $h->hyphenate('TABLE'));    // case-insensitive lookup
        self::assertSame([], (new Hyphenator())->hyphenate('the')); // too short / unknown
    }

    #[Test]
    public function the_default_dictionary_hyphenates_common_words(): void
    {
        $h = new Hyphenator();

        self::assertSame([2, 5, 7], $h->hyphenate('typography'));  // ty-pog-ra-phy
        self::assertSame([2, 5, 7], $h->hyphenate('information')); // in-for-ma-tion
        self::assertSame([], $h->hyphenate('xyzzy')); // unknown → no break
    }
}
