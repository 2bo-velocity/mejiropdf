<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Table;

use MejiroPDF\Document;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Table\Column;
use MejiroPDF\Table\Table;
use MejiroPDF\Table\TableBorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Table::class)]
#[CoversClass(Column::class)]
final class TableTest extends TestCase
{
    /**
     * @return list<Column>
     */
    private function columns(): array
    {
        return [
            new Column('#', 15, Alignment::CENTER),
            new Column('Item', 90, Alignment::LEFT),
            new Column('Qty', 25, Alignment::RIGHT),
        ];
    }

    #[Test]
    public function it_rejects_an_empty_column_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Table(new Document(), [], 10.0, 10.0);
    }

    #[Test]
    public function it_advances_the_cursor_as_rows_are_added(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $table = $pdf->table($this->columns(), x: 10, y: 20)->header(['#', 'Item', 'Qty']);

        $before = $table->currentY();
        $table->row(['1', 'Widget', '3']);

        self::assertGreaterThan($before, $table->currentY());
    }

    #[Test]
    public function it_auto_breaks_pages_and_repeats_the_header(): void
    {
        $pdf = new Document(PageSize::a5(), credit: CreditOptions::disabled());
        $table = $pdf->table($this->columns(), x: 10, y: 15)->header(['#', 'Item', 'Qty']);

        for ($i = 1; $i <= 120; $i++) {
            $table->row([(string) $i, "Item {$i}", (string) $i]);
        }

        $bytes = $pdf->output();

        self::assertGreaterThan(1, $pdf->pageCount());
        // The header is redrawn on every page it spans.
        self::assertGreaterThanOrEqual(2, \substr_count($bytes, '(Item) Tj'));
        // A late row still made it into the document.
        self::assertStringContainsString('(Item 120) Tj', $bytes);
        self::assertStringContainsString('%%EOF', $bytes);
    }

    #[Test]
    public function it_fills_alternating_zebra_rows(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $table = $pdf->table($this->columns(), x: 10, y: 20)->zebra('#EEEEEE', '#FFFFFF');
        $table->row(['1', 'a', '1']);
        $table->row(['2', 'b', '2']);

        $out = $pdf->output();

        // First data row is #EEEEEE (0.933), second #FFFFFF (1.000).
        self::assertStringContainsString('0.933 0.933 0.933 rg', $out);
        self::assertStringContainsString('1.000 1.000 1.000 rg', $out);
    }

    #[Test]
    public function horizontal_borders_draw_rules_not_boxes(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $table = $pdf->table([new Column('A', 40)], x: 10, y: 20)->borders(TableBorder::HORIZONTAL);
        $table->row(['x']);

        $out = $pdf->output();

        // A horizontal rule is a stroked line (m … l … S), not a re box.
        self::assertStringContainsString(" l\n", $out);
        self::assertStringContainsString("S\n", $out);
    }

    #[Test]
    public function it_wraps_long_cell_text_onto_multiple_lines(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $table = $pdf->table(
            [new Column('Note', 40, Alignment::LEFT)],
            x: 10,
            y: 20,
        );

        $before = $table->currentY();
        $table->row(['This is a deliberately long note that must wrap across several lines inside a narrow column.']);
        $singleLineHeight = 9.0 / PageSize::MM_TO_PT * 1.3 + 2 * 1.5;

        // A wrapped row is taller than a single-line row would be.
        self::assertGreaterThan($singleLineHeight, $table->currentY() - $before);
    }
}
