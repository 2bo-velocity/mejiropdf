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
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\Borders;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\Cell;
use MejiroPDF\Layout\GridColumn;
use MejiroPDF\Layout\GridTable;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TextAlign;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(GridTable::class)]
#[CoversClass(GridColumn::class)]
#[CoversClass(Cell::class)]
final class DocumentGridTest extends TestCase
{
    private function document(): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        return $pdf;
    }

    #[Test]
    public function it_draws_cells_and_advances_below_the_rows(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60, 30], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->header(['Code', 'Name', 'Qty'])
            ->row(['A1', 'Widget', '3']);

        $out = $pdf->output();

        self::assertStringContainsString('(C) Tj', $out);   // "Code"
        self::assertStringContainsString('(W) Tj', $out);   // "Widget"
        self::assertGreaterThan(20.0, $grid->currentY());   // advanced past the two rows
    }

    #[Test]
    public function a_cell_can_override_borders_with_a_double_bottom_rule(): void
    {
        $pdf = $this->document();
        $solid = new BorderStyle();
        $pdf->grid([40], 20, 20, font: StandardFont::HELVETICA, size: 9.0)->row([
            new Cell('Total', borders: new Borders(
                top: $solid,
                right: $solid,
                bottom: new BorderStyle(0.4, '#000000', LineStyle::DOUBLE),
                left: $solid,
            )),
        ]);

        // top + right + left (1 each) + double bottom (2) = 5 stroked lines.
        self::assertSame(5, \substr_count($pdf->output(), "l\nS"));
    }

    #[Test]
    public function zebra_fills_alternate_data_rows(): void
    {
        $pdf = $this->document();
        $pdf->grid([30, 30], 20, 20, font: StandardFont::HELVETICA, size: 9.0)
            ->zebra('#FFFFFF', '#EEEEEE')
            ->row(['a', 'b'])   // row 0 → white
            ->row(['c', 'd']);  // row 1 → #EEEEEE

        self::assertStringContainsString('0.933 0.933 0.933 rg', $pdf->output()); // #EEEEEE
    }

    #[Test]
    public function rows_grow_to_fit_wrapping_content(): void
    {
        $short = $this->document()->grid([30], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $short->row(['One']);

        $tall = $this->document()->grid([30], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $tall->row([\str_repeat('word ', 40)]);

        self::assertGreaterThan($short->currentY() - 20.0, $tall->currentY() - 20.0);
    }

    #[Test]
    public function a_colspan_cell_spans_multiple_columns(): void
    {
        $pdf = $this->document();
        // Three 30 mm columns; a single header cell spans all three (90 mm),
        // then a normal 3-cell data row follows.
        $pdf->grid([30, 30, 30], 20, 20, font: StandardFont::HELVETICA, size: 9.0)
            ->row([new Cell('Title', colspan: 3)])
            ->row(['a', 'b', 'c']);

        $out = $pdf->output();

        // The spanning cell's background/box would be 90 mm wide; the three data
        // cells are 30 mm each. We assert the spanned cell text is centred over
        // the full width by checking all four texts render.
        self::assertStringContainsString('(T) Tj', $out); // "Title"
        self::assertStringContainsString('(a) Tj', $out);
        self::assertStringContainsString('(c) Tj', $out);
    }

    #[Test]
    public function it_paginates_and_repeats_the_header(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([100], 20, 20, font: StandardFont::HELVETICA, size: 9.0)
            ->pageBreak(bottomY: 45.0)      // ~4 rows before the break
            ->header(['Heading']);

        for ($i = 0; $i < 20; $i++) {
            $grid->row(["Row {$i}"]);
        }

        self::assertGreaterThan(1, $pdf->pageCount());
        // Exactly once per page: a continuation without its heading is unreadable,
        // and a second copy on one page is a duplicated row.
        self::assertSame($pdf->pageCount(), \substr_count($pdf->output(), '(H) Tj'));
    }

    #[Test]
    public function a_right_aligned_column_is_accepted(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid(
            [new GridColumn(30), new GridColumn(30, TextAlign::END)],
            20,
            20,
            font: StandardFont::HELVETICA,
            size: 9.0,
        );
        $grid->row(['Item', '1,200']);

        self::assertStringContainsString('(1) Tj', $pdf->output());
    }

    /** Millimetres → points, as the engine converts them. */
    private const float MM = 2.834645669;

    /**
     * The [x, y, width, height] of every rectangle filled in pure red — the
     * colour the rowspan tests mark their spanning cell with.
     *
     * @return list<array{float, float, float, float}>
     */
    private function redBoxes(string $pdf): array
    {
        \preg_match_all(
            '~1\.000 0\.000 0\.000 rg\n([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) re~',
            $pdf,
            $matches,
            \PREG_SET_ORDER,
        );

        return \array_map(
            static fn (array $m): array => [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4]],
            $matches,
        );
    }

    #[Test]
    public function a_rowspan_cell_is_drawn_as_tall_as_the_rows_it_covers(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->row([new Cell('Fruit', backgroundColor: '#FF0000', rowspan: 3), 'apple'])
            ->row(['banana'])
            ->row(['cherry']);

        $boxes = $this->redBoxes($pdf->output());

        self::assertCount(1, $boxes, 'The spanning cell is drawn once, not once per row.');
        [, , $width, $height] = $boxes[0];

        self::assertEqualsWithDelta(30.0 * self::MM, $width, 0.01, 'It keeps its own column width.');
        self::assertEqualsWithDelta(
            ($grid->currentY() - 20.0) * self::MM,
            $height,
            0.05,
            'It is exactly as tall as the three rows beside it.',
        );
    }

    #[Test]
    public function rows_under_a_rowspan_step_over_the_column_it_holds(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->row([new Cell('Fruit', rowspan: 2), 'apple'])
            ->row(['banana']); // one cell, and it belongs in the second column

        $out = $pdf->output();

        // Column 1 starts 30 mm in, plus 2 mm of left padding: 52 mm from the
        // table origin at x = 20 mm. Both rows begin their text there.
        self::assertSame(
            2,
            \substr_count($out, \sprintf('1 0 0.0000 1 %.2F ', 52.0 * self::MM)),
            'Both "apple" and "banana" sit in the second column.',
        );
        // The spanning cell keeps column 0 to itself (20 mm + 2 mm padding).
        self::assertSame(
            1,
            \substr_count($out, \sprintf('1 0 0.0000 1 %.2F ', 22.0 * self::MM)),
        );
    }

    #[Test]
    public function a_rowspan_taller_than_its_rows_spreads_the_extra_height_evenly(): void
    {
        $flat = $this->document()->grid([40, 40], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $flat->row(['x', 'a'])->row(['y', 'b']);
        $flatHeight = $flat->currentY() - 20.0;

        $spanned = $this->document()->grid([40, 40], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $spanned->row([new Cell(\str_repeat('word ', 40), rowspan: 2), 'a'])->row(['b']);

        self::assertGreaterThan(
            $flatHeight,
            $spanned->currentY() - 20.0,
            'A long spanning cell pushes the rows it covers taller.',
        );
    }

    #[Test]
    public function a_rowspan_is_closed_at_a_page_break_and_reopened_on_the_next_page(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 70], 20, 20, font: StandardFont::HELVETICA, size: 9.0)
            ->pageBreak(bottomY: 45.0);

        $grid->row([new Cell('Group', backgroundColor: '#FF0000', rowspan: 12), 'first']);
        for ($i = 1; $i < 12; $i++) {
            $grid->row(["row {$i}"]);
        }

        $out = $pdf->output();
        $boxes = $this->redBoxes($out);

        self::assertGreaterThan(1, $pdf->pageCount());
        self::assertCount($pdf->pageCount(), $boxes, 'Each page carries its own segment of the span.');
        // The text is set once, on the page the span started.
        self::assertSame(1, \substr_count($out, '(G) Tj'));
    }

    #[Test]
    public function a_header_cell_ignores_rowspan(): void
    {
        // The header is redrawn on every page, so it cannot reach into the rows
        // below it; the data row must still find its own first column free.
        $pdf = $this->document();
        $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0)
            ->header([new Cell('Code', rowspan: 5), 'Name'])
            ->row(['A1', 'Widget']);

        $out = $pdf->output();

        self::assertStringContainsString('(A) Tj', $out);
        self::assertStringContainsString('(W) Tj', $out);
    }

    #[Test]
    public function a_rowspan_still_waiting_for_rows_is_not_drawn(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->row([new Cell('Fruit', backgroundColor: '#FF0000', rowspan: 4), 'apple']);

        self::assertCount(0, $this->redBoxes($pdf->output()));
    }

    #[Test]
    public function closing_draws_a_rowspan_whose_rows_never_arrived(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->row([new Cell('Fruit', backgroundColor: '#FF0000', rowspan: 4), 'apple'])
            ->close();

        $boxes = $this->redBoxes($pdf->output());

        self::assertCount(1, $boxes, 'close() draws what is left over.');
        // Sized to the one row it did get, not to the four it asked for.
        self::assertEqualsWithDelta(($grid->currentY() - 20.0) * self::MM, $boxes[0][3], 0.05);
    }

    #[Test]
    public function closing_a_table_with_no_open_span_is_a_no_op(): void
    {
        $pdf = $this->document();
        $grid = $pdf->grid([30, 60], 20, 20, font: StandardFont::HELVETICA, size: 9.0);
        $grid->row(['A1', 'Widget']);

        $before = $grid->currentY();

        self::assertSame($grid, $grid->close()->close());
        self::assertSame($before, $grid->currentY());
    }
}
