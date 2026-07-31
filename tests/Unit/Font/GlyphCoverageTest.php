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
use MejiroPDF\Font\GlyphCoverage;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlyphCoverage::class)]
final class GlyphCoverageTest extends TestCase
{
    use FindFont;

    #[Test]
    public function empty_text_is_covered_by_anything(): void
    {
        self::assertTrue(GlyphCoverage::covers(StandardFont::HELVETICA, ''));
    }

    #[Test]
    public function a_standard_font_covers_latin1_but_not_kana(): void
    {
        self::assertTrue(GlyphCoverage::covers(StandardFont::HELVETICA, 'Café'));
        self::assertFalse(GlyphCoverage::covers(StandardFont::HELVETICA, 'かんじ'));
    }

    #[Test]
    public function a_japanese_cidfont_covers_kana(): void
    {
        self::assertTrue(GlyphCoverage::covers(CidFontJapanese::HEISEI_MIN_W3, 'かんじ'));
    }

    #[Test]
    public function a_truetype_font_is_asked_its_own_cmap(): void
    {
        // DejaVuSans carries Latin but no CJK, so its cmap answers both ways.
        $font = TrueTypeFont::fromFile($this->findTrueTypeFont());

        self::assertTrue(GlyphCoverage::covers($font, 'Hello'));
        self::assertFalse(GlyphCoverage::covers($font, 'かんじ'));
    }
}
