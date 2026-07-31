<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentTrueTypeTest extends TestCase
{
    use FindFont;

    private function font(): TrueTypeFont
    {
        return TrueTypeFont::fromFile($this->findTrueTypeFont());
    }

    #[Test]
    public function it_embeds_a_cidfonttype2_with_identity_h(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'Café ¥1,234', font: $this->font(), size: 18.0);

        $bytes = $pdf->output();

        self::assertStringContainsString('/Subtype /Type0', $bytes);
        self::assertStringContainsString('/Subtype /CIDFontType2', $bytes);
        self::assertStringContainsString('/Encoding /Identity-H', $bytes);
        self::assertStringContainsString('/CIDToGIDMap /Identity', $bytes);
        self::assertStringContainsString('/FontFile2', $bytes);
        self::assertStringContainsString('/Length1', $bytes);
        self::assertStringContainsString('/ToUnicode', $bytes);
    }

    #[Test]
    public function it_draws_text_as_glyph_ids_not_literals(): void
    {
        $font = $this->font();

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'AB', font: $font, size: 12.0);

        $bytes = $pdf->output();
        $expected = $font->encodeToGidHex('AB') . ' Tj';

        self::assertStringContainsString($expected, $bytes);
        self::assertStringNotContainsString('(AB) Tj', $bytes);
    }

    #[Test]
    public function it_only_emits_widths_for_used_glyphs(): void
    {
        $font = $this->font();

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'A', font: $font, size: 12.0);

        $bytes = $pdf->output();

        // The W array carries the single used glyph, not thousands.
        self::assertMatchesRegularExpression('/\/W \[ \d+ \[\d+\] \]/', $bytes);
    }

    #[Test]
    public function the_embedded_font_is_subset_with_a_tag(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'AB', font: $this->font(), size: 12.0);

        $bytes = $pdf->output();

        // PDF requires a 6-uppercase-letter subset tag, e.g. /BaseFont /ABCDEF+DejaVuSans.
        self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+~', $bytes);
    }

    #[Test]
    public function it_can_mix_a_truetype_font_with_a_standard_font(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()
            ->text(20, 30, 'Standard', size: 12.0)
            ->text(20, 45, 'Embedded café', font: $this->font(), size: 12.0);

        $bytes = $pdf->output();

        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
        self::assertStringContainsString('/Subtype /CIDFontType2', $bytes);
    }
}
