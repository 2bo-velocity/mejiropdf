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
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Vertical setting through the predefined `UniJIS-UTF16-V` CMap: the font
 * supplies the vertical forms, and a column is written as one text object.
 */
#[CoversClass(Document::class)]
final class DocumentVerticalCmapTest extends TestCase
{
    /**
     * Text objects in the body, less the one the invisible licence marker
     * draws on each page. Counted by the drawing operator rather than the
     * marker text, which also appears in `/Keywords`.
     */
    private static function bodyTextObjects(string $pdf): int
    {
        return \substr_count($pdf, ' Tj') - \substr_count($pdf, 'Community Edition]) Tj');
    }

    private static function verticalPdf(string $text, float $size = 12.0): string
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            $text,
            190,
            20,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: $size,
            options: TypesetterOptions::novel(),
        );

        return $pdf->output();
    }

    #[Test]
    public function it_emits_a_vertical_type0_sharing_the_horizontal_descendant(): void
    {
        $out = self::verticalPdf('吾輩は猫である');

        self::assertStringContainsString('/Encoding /UniJIS-UTF16-V', $out);
        self::assertStringContainsString('/BaseFont /HeiseiMin-W3', $out);

        // Exactly one descendant CIDFont, referenced by every Type0 parent —
        // the parents differ only in which CMap reads it.
        self::assertSame(1, \substr_count($out, '/Subtype /CIDFontType0'));

        \preg_match_all('~/DescendantFonts \[ (\d+) 0 R \]~', $out, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertCount(1, \array_unique($matches[1]));
    }

    #[Test]
    public function a_column_of_plain_characters_is_one_text_object(): void
    {
        // Twelve characters that the CMap can set unaided.
        $out = self::verticalPdf('吾輩は猫である名前はまだ無');

        self::assertSame(1, self::bodyTextObjects($out), 'One Tj should carry the whole column.');
    }

    #[Test]
    public function the_run_breaks_where_the_cmap_cannot_carry_it(): void
    {
        // Furigana interrupts the run: parent group, then the rest.
        $withRuby = self::verticalPdf('吾輩は|猫《ねこ》である');
        self::assertGreaterThan(1, self::bodyTextObjects($withRuby));

        // Tate-chu-yoko is upright and set across the column, so it keeps the
        // horizontal face and its own text object.
        $withTcy = self::verticalPdf('平成24年');
        self::assertStringContainsString('<00320034> Tj', $withTcy);
        self::assertMatchesRegularExpression("~1 0 0 1 [\\d.]+ [\\d.]+ Tm\n<00320034> Tj~", $withTcy);
    }

    #[Test]
    public function horizontal_setting_never_asks_for_the_vertical_cmap(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset('吾輩は猫である', 20, 20, 120, font: CidFontJapanese::HEISEI_MIN_W3, size: 12.0);
        $out = $pdf->output();

        self::assertStringNotContainsString('UniJIS-UTF16-V', $out);
        self::assertStringContainsString('/Encoding /UniJIS-UTF16-H', $out);
    }

    #[Test]
    public function a_face_without_a_vertical_cmap_keeps_stacking_glyphs(): void
    {
        // Standard 14 has no vertical forms to offer, so the engine positions
        // each glyph itself rather than inventing a CMap for it.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            'abcdef',
            190,
            20,
            120,
            font: StandardFont::HELVETICA,
            size: 12.0,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
        $out = $pdf->output();

        self::assertStringNotContainsString('UniJIS-UTF16-V', $out);
        self::assertSame(6, self::bodyTextObjects($out), 'One text object per glyph on the fallback path.');
    }

    #[Test]
    public function a_whole_book_stays_one_text_object_per_column(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::vertical(rightX: 190, topY: 25, columnHeight: 240, leftX: 20),
            \implode("\n\n", \array_fill(0, 20, \str_repeat('吾輩は猫である名前はまだ無い', 4))),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 10.5,
            options: TypesetterOptions::novel(),
        );
        $out = $pdf->output();

        // Roughly one per column plus the page numbers — nowhere near the
        // thousands of characters drawn.
        $columns = self::bodyTextObjects($out);
        self::assertGreaterThan(1, $pdf->pageCount());
        self::assertLessThan(200, $columns, "Expected about one text object per column, got {$columns}.");
    }
}
