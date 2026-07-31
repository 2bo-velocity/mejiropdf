<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Font;

use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\CidFontMetrics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CidFontMetrics::class)]
#[CoversClass(CidFontJapanese::class)]
final class CidFontMetricsTest extends TestCase
{
    private function metrics(): CidFontMetrics
    {
        return new CidFontMetrics(CidFontJapanese::HEISEI_KAKU_GO);
    }

    #[Test]
    public function ascii_is_half_width(): void
    {
        self::assertSame(500, $this->metrics()->charWidth('A'));
    }

    #[Test]
    public function japanese_is_full_width(): void
    {
        self::assertSame(1000, $this->metrics()->charWidth('日'));
    }

    #[Test]
    public function it_measures_mixed_text_in_points(): void
    {
        // "A日" = 500 + 1000 units => 1500/1000 * 10pt = 15pt
        self::assertEqualsWithDelta(15.0, $this->metrics()->textWidth('A日', 10.0), 0.001);
    }

    #[Test]
    public function full_width_run_scales_with_size(): void
    {
        // "日本語" = 3000 units => 3000/1000 * 12pt = 36pt
        self::assertEqualsWithDelta(36.0, $this->metrics()->textWidth('日本語', 12.0), 0.001);
    }

    #[Test]
    public function it_exposes_the_predefined_cmap(): void
    {
        self::assertSame('UniJIS-UTF16-H', CidFontJapanese::HEISEI_KAKU_GO->cmap());
        self::assertSame('Adobe', CidFontJapanese::HEISEI_MIN_W3->registry());
        self::assertSame('Japan1', CidFontJapanese::HEISEI_MIN_W3->ordering());
    }
}
