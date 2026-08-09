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
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\FootnoteStyle;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two columns are the standard setting for a Japanese academic paper.
 *
 * A column break is a page break one step shallower, so the planner is not
 * told about columns at all: it goes on filling one block after another, and
 * the document decides that block n lands on page n div columns, column n mod
 * columns.
 */
#[CoversClass(Document::class)]
#[CoversClass(FlowRegion::class)]
final class DocumentColumnsTest extends TestCase
{
    private const string PARAGRAPH = '　本文が続く。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。';

    /** @return list<array{0: float, 1: float}> x, y of every glyph, in millimetres */
    private static function glyphs(string $pdf, float $pageHeight = 297.0): array
    {
        \preg_match_all("~1 0 0\.0000 1 ([\d.]+) ([\d.]+) Tm~", $pdf, $hits, \PREG_SET_ORDER);

        return \array_map(static fn (array $hit): array => [
            (float) $hit[1] / PageSize::MM_TO_PT,
            $pageHeight - (float) $hit[2] / PageSize::MM_TO_PT,
        ], $hits);
    }

    private static function flowed(FlowRegion $region, int $paragraphs, ?Document &$document = null): string
    {
        $document = new Document(credit: CreditOptions::disabled());
        $document->addPage();
        $document->flowInto(
            $region,
            \implode("\n\n", \array_fill(0, $paragraphs, self::PARAGRAPH)),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 9.0,
        );

        return $document->output();
    }

    private static function region(int $columns): FlowRegion
    {
        return FlowRegion::horizontal(x: 20, topY: 20, width: 170, bottomY: 120)
            ->withColumns($columns, 10.0);
    }

    #[Test]
    public function the_inline_extent_is_shared_out_between_the_columns(): void
    {
        // 170 mm less one 10 mm gap, halved.
        self::assertSame(80.0, self::region(2)->columnExtent());
        self::assertSame(170.0, self::region(1)->columnExtent());

        // A column is an ordinary single-column region: that is what lets the
        // rest of the engine stay ignorant of columns.
        $second = self::region(2)->column(1);
        self::assertSame(1, $second->columns);
        self::assertSame(110.0, $second->inlineStart);   // 20 + 80 + 10
        self::assertSame(80.0, $second->inlineExtent);
        self::assertSame(20.0, $second->blockStart);     // the same depth as the first
        self::assertSame(120.0, $second->blockLimit);
    }

    #[Test]
    public function columns_shorten_the_line_without_costing_paper(): void
    {
        // Columns divide the line, not the page's capacity: two columns of 80 mm
        // hold about what one of 170 mm does, which is the point of them — a
        // shorter line is easier to read, and the paper is unchanged.
        $one = null;
        $two = null;
        $single = self::glyphs(self::flowed(self::region(1), 30, $one));
        $double = self::glyphs(self::flowed(self::region(2), 30, $two));

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertLessThanOrEqual(1, \abs($one->pageCount() - $two->pageCount()));

        // A line in the two-column setting is half as long.
        $span = static fn (array $glyphs, float $from, float $to): float => \max(\array_column(
            \array_filter($glyphs, static fn (array $g): bool => $g[0] >= $from && $g[0] < $to),
            0,
        )) - $from;
        self::assertGreaterThan(160.0, $span($single, 20.0, 200.0));
        self::assertLessThan(81.0, $span($double, 20.0, 105.0));
        self::assertLessThan(81.0, $span($double, 110.0, 200.0));
    }

    #[Test]
    public function a_column_is_filled_to_the_foot_before_the_next_one_starts(): void
    {
        $glyphs = self::glyphs(self::flowed(self::region(2), 12));

        $left = \array_values(\array_filter($glyphs, static fn (array $g): bool => $g[0] < 105.0));
        $right = \array_values(\array_filter($glyphs, static fn (array $g): bool => $g[0] >= 105.0));
        self::assertNotSame([], $left);
        self::assertNotSame([], $right);

        // Both columns run the full depth of the region, and neither strays
        // into the other's width.
        foreach ([$left, $right] as $column) {
            self::assertGreaterThan(100.0, \max(\array_column($column, 1)));
        }
        self::assertLessThanOrEqual(100.5, \max(\array_column($left, 0)));
        self::assertGreaterThanOrEqual(109.5, \min(\array_column($right, 0)));

        // The first column is set before the second: the right column's first
        // glyph comes after the left column's last one in the content stream.
        $sides = \array_map(static fn (array $g): bool => $g[0] >= 105.0, $glyphs);
        $firstRight = \array_search(true, $sides, true);
        $lastLeftOfPageOne = \array_search(true, \array_slice($sides, 0, (int) $firstRight + 1), true);
        self::assertSame($firstRight, $lastLeftOfPageOne);
    }

    #[Test]
    public function vertical_columns_are_bands_stacked_down_the_page(): void
    {
        // The division is along the inline axis, which vertically runs down the
        // page — so the same code gives side-by-side columns horizontally and
        // stacked bands vertically, with no second implementation.
        $region = FlowRegion::vertical(rightX: 190, topY: 20, columnHeight: 250, leftX: 20)
            ->withColumns(2, 10.0);

        self::assertSame(120.0, $region->columnExtent());
        self::assertSame(150.0, $region->column(1)->inlineStart);  // 20 + 120 + 10
        self::assertSame(190.0, $region->column(1)->blockStart);   // the full width, as before
    }

    #[Test]
    public function a_single_column_region_is_laid_out_exactly_as_before(): void
    {
        $plain = FlowRegion::horizontal(x: 20, topY: 20, width: 170, bottomY: 120);

        self::assertSame(
            self::glyphs(self::flowed($plain, 8)),
            self::glyphs(self::flowed($plain->withColumns(1), 8)),
        );
    }

    #[Test]
    public function footnotes_beside_columns_are_refused_rather_than_drawn_wrongly(): void
    {
        // The band belongs to the page, not to a column, so its depth is only
        // settled once every column is full — and the columns already drawn
        // would have to be laid out again against it. Until that is built,
        // saying so beats stacking one band on top of another.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $pdf->flowInto(
            self::region(2),
            '本((注記))と',
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 9.0,
            footnotes: new FootnoteStyle(),
        );
    }

    #[Test]
    public function a_region_needs_at_least_one_column_and_a_gap_that_is_not_negative(): void
    {
        $region = FlowRegion::horizontal(x: 20, topY: 20, width: 170, bottomY: 120);

        try {
            $region->withColumns(0);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException) {
        }

        try {
            $region->withColumns(2, -1.0);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException) {
        }

        // And a gap so wide it leaves no room for the text.
        $this->expectException(\InvalidArgumentException::class);
        $region->withColumns(2, 200.0)->column(0);
    }

    #[Test]
    public function the_writing_mode_survives_the_division(): void
    {
        $region = FlowRegion::vertical(rightX: 190, topY: 20, columnHeight: 250, leftX: 20)->withColumns(3, 5.0);

        self::assertSame(WritingMode::VERTICAL_RL, $region->column(2)->writingMode);
        self::assertTrue($region->column(2)->isVertical());
        self::assertSame((new TypesetterOptions())->writingMode, WritingMode::HORIZONTAL_TB);
    }
}
