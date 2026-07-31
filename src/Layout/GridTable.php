<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. This program is distributed WITHOUT ANY WARRANTY; see the GNU
 * Affero General Public License <https://www.gnu.org/licenses/> for details.
 */

namespace MejiroPDF\Layout;

use MejiroPDF\Document;
use MejiroPDF\Font\FontFace;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TypesetterOptions;

/**
 * A content-sized grid table that streams row by row (O(1) memory): each
 * {@see header()}/{@see row()} sizes itself to its tallest cell, fills the
 * background (per-cell, then zebra/header), typesets each cell with the full
 * engine (ruby, right-aligned amounts, …), and strokes the borders — so a
 * DOUBLE total rule or a coloured header is one call. Cells accept a string or
 * a {@see Cell} to override alignment, background, borders, **colspan** or
 * **rowspan**.
 *
 * Rows are drawn top-down from (x, y); {@see currentY()} tracks the bottom.
 * With {@see pageBreak()} the table paginates itself, repeating the header row
 * at the top of each continuation page.
 *
 * **Rowspan and streaming.** A cell that covers several rows cannot be drawn
 * when it is declared, because its height is not known until the last row it
 * covers has been sized. It is therefore held back — the only state the table
 * keeps beyond one row — and drawn once that row lands. Only the open spans are
 * buffered, never the rows themselves, so memory still does not grow with the
 * table. Call {@see close()} when the last row is in, so a span whose rows ran
 * out is still drawn.
 *
 * @phpstan-type Placement array{cell: Cell, x: float, width: float, align: TextAlign, columns: array<int, true>}
 * @phpstan-type OpenSpan array{cell: Cell, x: float, width: float, align: TextAlign, columns: array<int, true>, y: float, outer: float, filled: float, remaining: int, withText: bool}
 */
final class GridTable
{
    /** @var list<GridColumn> */
    private array $columns;

    private float $y;
    private Borders $borders;
    private Padding $padding;
    private ?string $headerBackground = null;
    private ?string $zebraEven = null;
    private ?string $zebraOdd = null;
    private TypesetterOptions $options;
    private int $dataRows = 0;

    /** Auto page-break threshold (mm) and the Y a fresh page resumes at. */
    private ?float $pageBottom = null;
    private float $pageTop;

    /**
     * The header cells, kept so they can be repeated after a page break.
     *
     * @var list<string|Cell>|null
     */
    private ?array $headerCells = null;

    /**
     * Cells still waiting for the rows they span.
     *
     * @var list<OpenSpan>
     */
    private array $openSpans = [];

    /**
     * @param list<float|GridColumn> $columns column widths (mm) or definitions
     */
    public function __construct(
        private readonly Document $doc,
        array $columns,
        private readonly float $x,
        float $y,
        private readonly FontFace $font,
        private readonly float $size,
    ) {
        $this->columns = \array_map(
            static fn (float|GridColumn $c): GridColumn => $c instanceof GridColumn ? $c : new GridColumn($c),
            \array_values($columns),
        );
        if ($this->columns === []) {
            throw new \InvalidArgumentException('A grid needs at least one column.');
        }
        $this->y = $y;
        $this->pageTop = $y;
        $this->borders = Borders::all(new BorderStyle(0.3));
        $this->padding = new Padding(1.5, 2.0, 1.5, 2.0);
        $this->options = new TypesetterOptions();
    }

    /**
     * Break to a new page when a row would cross $bottomY (mm), repeating the
     * header row at the top of each continuation page. $topY is the Y a fresh
     * page resumes at (defaults to the table's start Y).
     */
    public function pageBreak(float $bottomY, ?float $topY = null): self
    {
        $this->pageBottom = $bottomY;
        $this->pageTop = $topY ?? $this->pageTop;

        return $this;
    }

    public function cellBorders(Borders $borders): self
    {
        $this->borders = $borders;

        return $this;
    }

    public function padding(Padding $padding): self
    {
        $this->padding = $padding;

        return $this;
    }

    public function headerFill(?string $color): self
    {
        $this->headerBackground = $color;

        return $this;
    }

    public function zebra(?string $even = '#FFFFFF', ?string $odd = '#F5F7FA'): self
    {
        $this->zebraEven = $even;
        $this->zebraOdd = $odd;

        return $this;
    }

    public function options(TypesetterOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param list<string|Cell> $cells
     */
    public function header(array $cells): self
    {
        $this->headerCells = $cells; // kept for repeat after page breaks
        $this->renderRow($cells, $this->headerBackground, isHeader: true);

        return $this;
    }

    /**
     * @param list<string|Cell> $cells
     */
    public function row(array $cells): self
    {
        $fill = $this->zebraEven === null && $this->zebraOdd === null
            ? null
            : ($this->dataRows % 2 === 0 ? $this->zebraEven : $this->zebraOdd);
        $this->renderRow($cells, $fill, isHeader: false);
        $this->dataRows++;

        return $this;
    }

    /**
     * Finish the table: draw any rowspan still waiting for rows that never came.
     *
     * Calling it is only necessary when a declared rowspan outlives the rows
     * actually added — but it is harmless otherwise, so a caller that always
     * closes never has to reason about which case it is in.
     */
    public function close(): self
    {
        foreach ($this->openSpans as $span) {
            $this->drawSpan($span);
        }
        $this->openSpans = [];

        return $this;
    }

    /**
     * The Y (mm) just below the last drawn row — where following content flows.
     */
    public function currentY(): float
    {
        return $this->y;
    }

    /**
     * @param list<string|Cell> $cells
     */
    private function renderRow(array $cells, ?string $rowFill, bool $isHeader): void
    {
        $placements = $this->layoutRow($cells, $this->reservedColumns());

        // A header is redrawn on every page, so it can only ever be one row
        // tall; anywhere else a multi-row cell is held back until its last row.
        $inRow = [];
        $spanning = [];
        foreach ($placements as $placement) {
            if (!$isHeader && $placement['cell']->rowspan > 1) {
                $spanning[] = $placement;
            } else {
                $inRow[] = $placement;
            }
        }

        // Size the row to its tallest cell.
        $contentHeight = 0.0;
        foreach ($inRow as $placement) {
            $contentHeight = \max($contentHeight, $this->contentHeight($placement));
        }
        $rowHeight = $contentHeight + $this->padding->top + $this->padding->bottom;

        // A multi-row cell claims an even share of what it still needs from
        // each row it has left, so the block grows steadily instead of leaving
        // the last row to absorb the whole thing.
        foreach ($this->openSpans as $span) {
            $rowHeight = \max($rowHeight, self::share($span['outer'], $span['filled'], $span['remaining']));
        }
        $outers = [];
        foreach ($spanning as $index => $placement) {
            $outers[$index] = $this->contentHeight($placement) + $this->padding->top + $this->padding->bottom;
            $rowHeight = \max($rowHeight, self::share($outers[$index], 0.0, $placement['cell']->rowspan));
        }

        // Auto page-break: a data row that would cross the bottom starts a fresh
        // page and re-draws the header there (never loop on an over-tall row).
        // Decided before this row's spans are opened, so they begin on the page
        // that will actually carry them.
        if (
            !$isHeader
            && $this->pageBottom !== null
            && $this->y + $rowHeight > $this->pageBottom
            && $this->y > $this->pageTop + 1.0e-6
        ) {
            $this->breakPage();
        }

        foreach ($spanning as $index => $placement) {
            $this->openSpans[] = $placement + [
                'y' => $this->y,
                'outer' => $outers[$index],
                'filled' => 0.0,
                'remaining' => $placement['cell']->rowspan,
                'withText' => true,
            ];
        }

        // Background → text → borders, per cell.
        foreach ($inRow as $placement) {
            $cell = $placement['cell'];
            $fill = $cell->backgroundColor ?? $rowFill;
            if ($fill !== null) {
                $this->doc->rect($placement['x'], $this->y, $placement['width'], $rowHeight, fill: $fill);
            }
            $this->typesetCell($cell, $placement['x'], $this->y, $placement['width'], $placement['align']);
            $this->strokeBorders($cell->borders ?? $this->borders, $placement['x'], $placement['width'], $this->y, $rowHeight);
        }

        $this->y += $rowHeight;

        // Credit the row to every open span, and draw the ones that are done.
        $still = [];
        foreach ($this->openSpans as $span) {
            $span['filled'] += $rowHeight;
            $span['remaining']--;
            if ($span['remaining'] > 0) {
                $still[] = $span;
                continue;
            }
            $this->drawSpan($span);
        }
        $this->openSpans = $still;
    }

    /**
     * Start a fresh page, repeating the header.
     *
     * A rowspan cannot cross sheets, so each open span is closed at the page
     * bottom and re-opened on the next page for the rows it has left. The text
     * is set once, on the page the span started — a continuation carries the
     * background and the rules that keep the grid intact.
     */
    private function breakPage(): void
    {
        $carried = [];
        foreach ($this->openSpans as $span) {
            $this->drawSpan($span);
            $carried[] = ['outer' => 0.0, 'filled' => 0.0, 'withText' => false] + $span;
        }
        $this->openSpans = [];

        $this->doc->addPage();
        $this->y = $this->pageTop;
        if ($this->headerCells !== null) {
            $this->renderRow($this->headerCells, $this->headerBackground, isHeader: true);
        }

        foreach ($carried as $span) {
            $span['y'] = $this->y;
            $this->openSpans[] = $span;
        }
    }

    /**
     * Draw a span at the height the rows it covered actually came to.
     *
     * @param OpenSpan $span
     */
    private function drawSpan(array $span): void
    {
        if ($span['filled'] <= 0.0) {
            return; // nothing was ever placed beside it
        }

        $cell = $span['cell'];
        if ($cell->backgroundColor !== null) {
            $this->doc->rect($span['x'], $span['y'], $span['width'], $span['filled'], fill: $cell->backgroundColor);
        }
        if ($span['withText']) {
            $this->typesetCell($cell, $span['x'], $span['y'], $span['width'], $span['align']);
        }
        $this->strokeBorders($cell->borders ?? $this->borders, $span['x'], $span['width'], $span['y'], $span['filled']);
    }

    /**
     * What a block of $outer millimetres still owes per remaining row.
     */
    private static function share(float $outer, float $filled, int $remaining): float
    {
        return \max(0.0, ($outer - $filled) / \max(1, $remaining));
    }

    /**
     * The columns currently held by a rowspan, which this row must step over.
     *
     * @return array<int, true>
     */
    private function reservedColumns(): array
    {
        $reserved = [];
        foreach ($this->openSpans as $span) {
            $reserved += $span['columns'];
        }

        return $reserved;
    }

    /**
     * @param Placement $placement
     */
    private function contentHeight(array $placement): float
    {
        return $this->doc->measureTextHeight(
            $placement['cell']->text,
            $placement['width'] - $this->padding->left - $this->padding->right,
            $this->font,
            $this->size,
            $this->options->withAlign($placement['align']),
        );
    }

    private function typesetCell(Cell $cell, float $x, float $y, float $width, TextAlign $align): void
    {
        $this->doc->typeset(
            $cell->text,
            $x + $this->padding->left,
            $y + $this->padding->top,
            $width - $this->padding->left - $this->padding->right,
            $this->font,
            $this->size,
            $this->options->withAlign($align),
        );
    }

    /**
     * Assign cells to columns left-to-right, stepping over the columns an open
     * rowspan holds and honouring colspan; short rows are padded with empty
     * cells. Returns the placed cells with x/width (mm), resolved alignment and
     * the columns they occupy.
     *
     * @param  list<string|Cell> $cells
     * @param  array<int, true>  $reserved
     * @return list<Placement>
     */
    private function layoutRow(array $cells, array $reserved): array
    {
        $columnCount = \count($this->columns);
        $queue = $cells;
        $items = [];
        $colIndex = 0;
        $colX = $this->x;

        while ($colIndex < $columnCount) {
            if (isset($reserved[$colIndex])) {
                $colX += $this->columns[$colIndex]->width;
                $colIndex++;
                continue;
            }

            // A row shorter than the grid is padded out, so its rules still
            // close; anything past the last column is dropped.
            $raw = \array_shift($queue);
            $cell = match (true) {
                $raw instanceof Cell => $raw,
                \is_string($raw) => new Cell($raw),
                default => new Cell(''),
            };

            // A span stops at the first column somebody else already holds,
            // rather than reaching over it.
            $span = \min($cell->colspan, $columnCount - $colIndex);
            for ($k = 1; $k < $span; $k++) {
                if (isset($reserved[$colIndex + $k])) {
                    $span = $k;
                    break;
                }
            }

            $width = 0.0;
            $columns = [];
            for ($k = 0; $k < $span; $k++) {
                $width += $this->columns[$colIndex + $k]->width;
                $columns[$colIndex + $k] = true;
            }

            $items[] = [
                'cell' => $cell,
                'x' => $colX,
                'width' => $width,
                'align' => $cell->align ?? $this->columns[$colIndex]->align,
                'columns' => $columns,
            ];
            $colX += $width;
            $colIndex += $span;
        }

        return $items;
    }

    private function strokeBorders(Borders $borders, float $colX, float $width, float $top, float $height): void
    {
        $bottom = $top + $height;
        $right = $colX + $width;

        if ($borders->top !== null) {
            $this->doc->styledLine($colX, $top, $right, $top, $borders->top);
        }
        if ($borders->bottom !== null) {
            $this->doc->styledLine($colX, $bottom, $right, $bottom, $borders->bottom);
        }
        if ($borders->left !== null) {
            $this->doc->styledLine($colX, $top, $colX, $bottom, $borders->left);
        }
        if ($borders->right !== null) {
            $this->doc->styledLine($right, $top, $right, $bottom, $borders->right);
        }
    }
}
