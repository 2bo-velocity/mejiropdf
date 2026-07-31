<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Font;

use MejiroPDF\Font\FontKind;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrueTypeFont::class)]
final class TrueTypeFontTest extends TestCase
{
    use FindFont;

    private function font(): TrueTypeFont
    {
        return TrueTypeFont::fromFile($this->findTrueTypeFont());
    }

    #[Test]
    public function it_parses_the_core_head_metrics(): void
    {
        $font = $this->font();

        self::assertGreaterThan(0, $font->unitsPerEm);
        self::assertGreaterThan(100, $font->numGlyphs);
        self::assertGreaterThan(0, $font->ascent1000);
        self::assertLessThan(0, $font->descent1000);
        self::assertSame(FontKind::TRUETYPE, $font->kind());
    }

    #[Test]
    public function it_reads_the_postscript_base_name(): void
    {
        // The DejaVu family names all glyphs under a "DejaVu…" PostScript name.
        self::assertStringContainsString('DejaVu', $this->font()->baseFontName());
    }

    #[Test]
    public function it_maps_ascii_to_a_real_glyph(): void
    {
        $font = $this->font();

        $gid = $font->gidForCodepoint(\ord('A'));
        self::assertGreaterThan(0, $gid);
        self::assertSame('<' . \sprintf('%04X', $gid) . '>', $font->encodeToGidHex('A'));
    }

    #[Test]
    public function unmapped_code_points_fall_back_to_notdef(): void
    {
        // A Plane-15 private-use code point DejaVu does not cover.
        self::assertSame(0, $this->font()->gidForCodepoint(0xF0000));
    }

    #[Test]
    public function it_measures_text_width(): void
    {
        $font = $this->font();

        self::assertGreaterThan(0.0, $font->textWidth('Hello', 12.0));
        self::assertSame(0.0, $font->textWidth('', 12.0));
        // Wider strings are wider.
        self::assertGreaterThan($font->textWidth('ii', 12.0), $font->textWidth('WW', 12.0));
    }
}
