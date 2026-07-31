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

namespace MejiroPDF\Block;

use MejiroPDF\Document;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\ElementPainter;

/**
 * Stacks a template's blocks down the page.
 *
 * ## The whole algorithm
 * Keep a cursor. For each block: decide whether it appears, work out how tall
 * it is, start a new page if it would not fit, draw it at the cursor, and move
 * the cursor down. That is all of it — no measuring pass, no layout tree, no
 * second traversal.
 *
 * Two consequences fall out of that shape rather than being coded for:
 *
 * - **A hidden block collapses.** Skipping it never advances the cursor, so
 *   everything below moves up on its own. There is no "reflow" step because
 *   nothing was ever laid out in advance to reflow.
 * - **Memory stays flat.** Only the cursor and the current row survive between
 *   iterations. A repeater with a hundred thousand rows costs what one row
 *   costs, which is the property this whole engine is built around.
 *
 * An `ABSOLUTE` block is drawn at its own coordinates and leaves the cursor
 * alone, so a seal box or a corner logo does not push the sequence down.
 */
final class BlockRenderer
{
    /** Guards against a page-break loop when a block cannot fit any page. */
    private const float EPSILON = 1.0e-6;

    private float $cursor = 0.0;

    /**
     * Per-block decisions from the calling code, for this render pass.
     *
     * @var array<string, BlockOverride>
     */
    private array $overrides = [];

    public function __construct(
        private readonly ElementPainter $painter = new ElementPainter(),
    ) {
    }

    /**
     * Draw every block of $template, paginating as needed.
     *
     * @param array<string, mixed>        $data
     * @param array<string, BlockOverride> $overrides Per-block decisions from the calling
     *                                                code, keyed by block id.
     */
    public function render(
        Document $document,
        CompiledTemplate $template,
        array $data = [],
        array $overrides = [],
    ): void {
        $this->overrides = $overrides;
        $top = $template->marginTop;
        $bottom = $template->pageHeightMm() - $template->marginBottom;
        $this->cursor = $top;

        foreach ($template->blocks as $block) {
            if (!$this->appears($block, $data)) {
                continue; // the cursor does not move, so the rest moves up
            }

            if (!$block->positionMode->isFlow()) {
                // Absolute coordinates are the page's own: a seal box belongs
                // to the sheet, not to the sequence, so it ignores the margins
                // the flow is laid out inside.
                $this->drawAt($document, $template, $block, $data, $block->x, $block->y);
                continue;
            }

            match ($block->kind) {
                BlockKind::REPEATER => $this->flowRepeater($document, $template, $block, $data, $top, $bottom),
                default => $this->flowSingle($document, $template, $block, $data, $top, $bottom),
            };
        }
    }

    /** The Y (mm) just below the last block drawn. */
    public function currentY(): float
    {
        return $this->cursor;
    }

    /**
     * Whether a block draws, letting the calling code have the last word.
     *
     * The template's condition is the designer's rule, evaluated per record;
     * an override is the program's, and it knows things the expression cannot
     * reach. Where one is set it wins outright — a `hide()` is not a hint.
     *
     * @param array<string, mixed> $data
     */
    private function appears(Block $block, array $data): bool
    {
        $forced = $this->overrides[$block->id]?->visible ?? null;

        return $forced ?? $block->appearsIn($data);
    }

    /**
     * The rows a repeater draws: the ones handed over by {@see BlockHandle::bindData()},
     * or the ones its data source names.
     *
     * @param  array<string, mixed> $data
     * @return list<mixed>
     */
    private function rowsFor(Block $block, array $data): array
    {
        return $this->overrides[$block->id]?->rows ?? DataPath::rows($data, $block->dataSource);
    }

    /**
     * A `SINGLE` or `COLUMNS` block placed at the cursor.
     *
     * @param array<string, mixed> $data
     */
    private function flowSingle(
        Document $document,
        CompiledTemplate $template,
        Block $block,
        array $data,
        float $top,
        float $bottom,
    ): void {
        $height = $this->heightOf($template, $block, $data);
        $this->breakIfNeeded($document, $height, $top, $bottom);
        $this->drawAt($document, $template, $block, $data, $this->originX($template, $block), $this->cursor);
        $this->cursor += $height + $block->marginBottom;
    }

    /**
     * A `REPEATER`: heading, then one element set per row, then a footer.
     *
     * @param array<string, mixed> $data
     */
    private function flowRepeater(
        Document $document,
        CompiledTemplate $template,
        Block $block,
        array $data,
        float $top,
        float $bottom,
    ): void {
        $rows = $this->rowsFor($block, $data);
        $originX = $this->originX($template, $block);
        $itemHeight = $block->item?->measuredHeight() ?? 0.0;
        $footerHeight = $block->footer?->measuredHeight() ?? 0.0;

        $this->drawHeader($document, $block, $data, $originX, $top, $bottom, first: true);

        $lastIndex = \count($rows) - 1;
        foreach ($rows as $index => $row) {
            // Look-Ahead: the last row is measured together with the footer, so
            // a subtotal never lands alone at the top of the next page. When
            // they do not fit together, both move — leaving "heading, last row,
            // subtotal" reading as one group on the new page.
            $needed = $index === $lastIndex ? $itemHeight + $footerHeight : $itemHeight;

            if ($this->breakIfNeeded($document, $needed, $top, $bottom)) {
                $this->drawHeader($document, $block, $data, $originX, $top, $bottom, first: false);
            }

            if ($block->item !== null) {
                $this->painter->paint(
                    $document,
                    $block->item,
                    $this->rowScope($data, $row),
                    $originX,
                    $this->cursor,
                    opsAreLocal: true,
                );
            }
            $this->cursor += $itemHeight;
        }

        if ($block->footer !== null) {
            // Only reachable when there were no rows to look ahead from; with
            // rows, the loop above already made room for it.
            if ($rows === []) {
                $this->breakIfNeeded($document, $footerHeight, $top, $bottom);
            }
            $this->painter->paint($document, $block->footer, $data, $originX, $this->cursor, opsAreLocal: true);
            $this->cursor += $footerHeight;
        }

        $this->cursor += $block->marginBottom;
    }

    /**
     * Draw the repeater's heading, if it has one.
     *
     * Continuation pages use {@see Block::$headerPage2} when the template
     * supplies one — the first page can afford a decorated heading, the ones
     * after it usually want a compact restatement.
     *
     * @param array<string, mixed> $data
     */
    private function drawHeader(
        Document $document,
        Block $block,
        array $data,
        float $originX,
        float $top,
        float $bottom,
        bool $first,
    ): void {
        if (!$first && !$block->repeatHeaderOnPageBreak) {
            return;
        }

        $header = $first ? $block->header : ($block->headerPage2 ?? $block->header);
        if ($header === null) {
            return;
        }

        $height = $header->measuredHeight();
        if ($first) {
            $this->breakIfNeeded($document, $height, $top, $bottom);
        }
        $this->painter->paint($document, $header, $data, $originX, $this->cursor, opsAreLocal: true);
        $this->cursor += $height;
    }

    /**
     * Start a new page when $height would cross the bottom margin.
     *
     * Returns whether a page was started, which is what tells a repeater to
     * restate its heading. A block taller than a whole page is drawn anyway
     * rather than looping forever: overflowing is a layout mistake the designer
     * can see, an infinite loop is a hang they cannot.
     */
    private function breakIfNeeded(Document $document, float $height, float $top, float $bottom): bool
    {
        if ($this->cursor + $height <= $bottom + self::EPSILON) {
            return false;
        }
        if ($this->cursor <= $top + self::EPSILON) {
            return false; // already at the top: nothing to gain from a break
        }

        $document->addPage();
        $this->cursor = $top;

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function drawAt(
        Document $document,
        CompiledTemplate $template,
        Block $block,
        array $data,
        float $x,
        float $y,
    ): void {
        if ($block->kind === BlockKind::COLUMNS) {
            $this->drawColumns($document, $template, $block, $data, $x, $y);

            return;
        }

        $this->painter->paint($document, $block->elements, $data, $x, $y, opsAreLocal: true);
    }

    /**
     * Where a flow block's left edge sits: the page's left margin, plus any
     * offset the block asks for.
     *
     * The two axes are treated the same way on purpose. The cursor starts at
     * the top margin, so a block with no offset should start at the left one —
     * a designer who sets both margins to 15mm expects a plain block to land at
     * (15, 15), not at (0, 15).
     */
    private function originX(CompiledTemplate $template, Block $block): float
    {
        return $template->marginLeft + $block->x;
    }

    /**
     * Lay the columns out left to right.
     *
     * A hidden column's width is handed to its `autoExpand` siblings, so a
     * two-up header becomes one full-width panel when the right-hand side drops
     * out. Columns that opt out of expanding keep their own width and their own
     * left edge — a seal box is positioned against the paper, not against
     * whatever happens to precede it.
     *
     * @param array<string, mixed> $data
     */
    private function drawColumns(
        Document $document,
        CompiledTemplate $template,
        Block $block,
        array $data,
        float $originX,
        float $y,
    ): void {
        $containerWidth = $this->widthOf($template, $block);

        $visible = [];
        $reclaimed = 0.0;
        $expanding = 0;
        foreach ($block->columns as $column) {
            $width = $column->widthMm($containerWidth);
            if (!Condition::isTrue($column->condition, $data)) {
                $reclaimed += $width;
                continue;
            }
            $visible[] = [$column, $width];
            if ($column->autoExpand) {
                $expanding++;
            }
        }

        $share = $expanding > 0 ? $reclaimed / $expanding : 0.0;
        $x = $originX;
        foreach ($visible as [$column, $width]) {
            if ($column->autoExpand) {
                $width += $share;
            }
            $this->painter->paint($document, $column->elements, $data, $x, $y, opsAreLocal: true);
            $x += $width;
        }
    }

    /**
     * How tall a block is (mm), before its bottom margin.
     *
     * @param array<string, mixed> $data
     */
    private function heightOf(CompiledTemplate $template, Block $block, array $data): float
    {
        if ($block->kind !== BlockKind::COLUMNS) {
            return $block->elements->measuredHeight();
        }

        $containerWidth = $this->widthOf($template, $block);
        $tallest = 0.0;
        $first = null;
        foreach ($block->columns as $column) {
            if (!Condition::isTrue($column->condition, $data)) {
                continue;
            }
            $height = $column->elements->measuredHeight();
            $first ??= $height;
            $tallest = \max($tallest, $height);
        }

        // Without equalHeight the container claims only its first column, so a
        // long address on the left is free to run past a short panel on the
        // right — and whatever follows will overlap it. That is the designer's
        // call to make; the default is the safe one.
        unset($containerWidth);

        return $block->equalHeight ? $tallest : ($first ?? 0.0);
    }

    private function widthOf(CompiledTemplate $template, Block $block): float
    {
        return $block->width > 0.0
            ? $block->width
            : $template->pageWidthMm() - $template->marginLeft - $template->marginRight;
    }

    /**
     * The data a repeater row sees: the record, plus the row itself under both
     * `item` and its own keys.
     *
     * Exposing the row twice is what lets a template write either
     * `{{ item.name }}` or plain `{{ name }}` — the first reads naturally when
     * a row sits beside document-level fields, the second when the row is all
     * the block cares about.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function rowScope(array $data, mixed $row): array
    {
        $scope = $data;
        $scope['item'] = $row;

        if (\is_array($row)) {
            foreach ($row as $key => $value) {
                if (\is_string($key)) {
                    $scope[$key] = $value;
                }
            }
        }

        return $scope;
    }
}
