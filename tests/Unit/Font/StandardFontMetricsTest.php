<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Font;

use MejiroPDF\Font\StandardFont;
use MejiroPDF\Font\StandardFontMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardFontMetrics::class)]
final class StandardFontMetricsTest extends TestCase
{
    #[Test]
    public function courier_is_monospaced_at_600_units(): void
    {
        $metrics = new StandardFontMetrics(StandardFont::COURIER);

        // 10 chars * 600/1000 * 10pt = 60pt, regardless of which chars.
        self::assertEqualsWithDelta(60.0, $metrics->textWidth('WWWWWWWWWW', 10.0), 0.001);
        self::assertEqualsWithDelta(60.0, $metrics->textWidth('iiiiiiiiii', 10.0), 0.001);
    }

    #[Test]
    public function helvetica_space_width_is_278_units(): void
    {
        $metrics = new StandardFontMetrics(StandardFont::HELVETICA);

        // ' ' = 278 units => 278/1000 * 12 = 3.336 pt
        self::assertEqualsWithDelta(3.336, $metrics->textWidth(' ', 12.0), 0.001);
    }

    #[Test]
    public function empty_string_has_zero_width(): void
    {
        $metrics = new StandardFontMetrics(StandardFont::TIMES_ROMAN);

        self::assertSame(0.0, $metrics->textWidth('', 12.0));
    }

    #[Test]
    public function unknown_bytes_fall_back_to_default_width(): void
    {
        $metrics = new StandardFontMetrics(StandardFont::HELVETICA);

        // A byte outside the tabulated 32..126 range -> 500 units.
        self::assertSame(500, $metrics->charWidth("\x01"));
    }
}
