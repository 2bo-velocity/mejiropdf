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

use MejiroPDF\Text\WritingMode;

/**
 * The area text flows through, in millimetres from the page top-left, stated
 * in terms of the two axes text actually has rather than the page's x and y.
 *
 * - The **inline axis** runs along a line: rightward when writing horizontally,
 *   downward when writing vertically.
 * - The **block axis** runs from line to line: downward when writing
 *   horizontally, leftward (`vertical-rl`) when writing vertically.
 *
 * Naming the axes this way is the whole point. Line breaking, height
 * measurement and pagination are identical in both directions and differ only
 * in which page coordinate each axis lands on; holding that mapping in one
 * value object keeps the difference out of everything else. Without it the
 * flow would have to branch on {@see WritingMode} at every step, which is how
 * vertical text ended up unable to paginate at all.
 *
 * A planner such as {@see \MejiroPDF\Text\Pagination} only ever works with an
 * axis that *increases* from start to limit. Vertical text runs toward smaller
 * x, so the region hands the planner a **signed** coordinate — negated when
 * vertical — and converts back afterwards. Negation is exact in IEEE 754, so
 * horizontal planning is arithmetically identical to planning on raw
 * millimetres and its output does not shift by a rounding bit.
 */
final readonly class FlowRegion
{
    /**
     * @param WritingMode $writingMode  Which page axis each text axis maps onto.
     * @param float       $blockStart   Where lines start stacking: the top edge
     *                                  (horizontal) or the right edge (vertical).
     * @param float       $blockLimit   Where stacking must stop: the bottom edge
     *                                  (horizontal) or the left edge (vertical).
     * @param float       $inlineStart  Where each line begins: the left edge
     *                                  (horizontal) or the top edge (vertical).
     * @param float       $inlineExtent How long a line may be: the line width
     *                                  (horizontal) or the column height (vertical).
     * @param int         $columns      How many columns the inline extent is
     *                                  divided into. Text fills one before
     *                                  moving to the next, and the last one
     *                                  before the page turns.
     * @param float       $columnGap    Space between adjacent columns (mm).
     */
    private function __construct(
        public WritingMode $writingMode,
        public float $blockStart,
        public float $blockLimit,
        public float $inlineStart,
        public float $inlineExtent,
        private float $blockShift = 0.0,
        public int $columns = 1,
        public float $columnGap = 0.0,
    ) {
        if ($columns < 1) {
            throw new \InvalidArgumentException('A flow region needs at least one column.');
        }
        if ($columnGap < 0.0) {
            throw new \InvalidArgumentException('A column gap cannot be negative.');
        }
        if ($inlineExtent <= 0.0) {
            throw new \InvalidArgumentException('A flow region needs a positive inline extent.');
        }
        if ($this->depth() <= 0.0) {
            throw new \InvalidArgumentException(
                'A flow region needs room on the block axis: '
                . ($writingMode->isVertical()
                    ? 'the left limit must lie left of the right edge.'
                    : 'the bottom limit must lie below the top edge.'),
            );
        }
    }

    /**
     * Horizontal text filling a box downward from its top-left corner.
     */
    public static function horizontal(float $x, float $topY, float $width, float $bottomY): self
    {
        return new self(WritingMode::HORIZONTAL_TB, $topY, $bottomY, $x, $width);
    }

    /**
     * Vertical text filling a box leftward from its top-**right** corner, the
     * same origin {@see \MejiroPDF\Document::typeset()} uses for `vertical-rl`.
     */
    public static function vertical(float $rightX, float $topY, float $columnHeight, float $leftX): self
    {
        return new self(WritingMode::VERTICAL_RL, $rightX, $leftX, $topY, $columnHeight);
    }

    /**
     * Build from four scalars given in the reading flow's own order, under
     * whichever mode is in force: (x, topY) is the corner text starts from,
     * $extent is how long a line may be, and $limit is how far the lines may
     * stack. Both named constructors already take their arguments in that
     * order, so this only picks between them — deliberately, because a version
     * that reordered arguments per mode got them the wrong way round.
     */
    public static function for(WritingMode $mode, float $x, float $topY, float $extent, float $limit): self
    {
        return $mode->isVertical()
            ? self::vertical($x, $topY, $extent, $limit)
            : self::horizontal($x, $topY, $extent, $limit);
    }

    /**
     * The same region shifted toward the fore-edge by $gutter millimetres, for
     * a book whose inner margin is wider than its outer one.
     *
     * The block keeps its size and only moves, so pagination is unaffected —
     * which is why this is applied when drawing rather than when planning. On a
     * recto the shift is away from the gutter, on a verso the other way, and
     * which physical side that is follows the binding.
     */
    public function shiftedForSpread(float $gutter, int $physicalPage, bool $rightBound): self
    {
        if ($gutter === 0.0) {
            return $this;
        }

        $recto = $physicalPage % 2 === 1;
        $gutterOnLeft = $rightBound ? !$recto : $recto;
        $dx = $gutterOnLeft ? $gutter : -$gutter;

        // The planning coordinates are deliberately left alone: a plan is made
        // once for the whole flow, and only the drawing of it moves per page.
        // Vertically the shift rides on the block axis, horizontally on the
        // inline one — in both cases it is the page's x that changes.
        return $this->isVertical()
            ? new self($this->writingMode, $this->blockStart, $this->blockLimit, $this->inlineStart, $this->inlineExtent, $this->blockShift + $dx, $this->columns, $this->columnGap)
            : new self($this->writingMode, $this->blockStart, $this->blockLimit, $this->inlineStart + $dx, $this->inlineExtent, $this->blockShift, $this->columns, $this->columnGap);
    }

    /**
     * The same region divided into $count columns separated by $gap millimetres.
     *
     * Two columns are the standard setting for a Japanese academic paper. The
     * division is along the **inline** axis, which is what makes it work in both
     * directions without a second implementation: horizontally the columns
     * stand side by side, vertically they are bands stacked down the page, and
     * in both cases a column is simply a shorter line.
     */
    public function withColumns(int $count, float $gap = 0.0): self
    {
        return new self($this->writingMode, $this->blockStart, $this->blockLimit, $this->inlineStart, $this->inlineExtent, $this->blockShift, $count, $gap);
    }

    /** How long a line may be inside one column (mm). */
    public function columnExtent(): float
    {
        return ($this->inlineExtent - $this->columnGap * ($this->columns - 1)) / $this->columns;
    }

    /**
     * The $index-th column as a region of its own — one column wide, and no
     * longer divided, so everything downstream sees an ordinary single column.
     */
    public function column(int $index): self
    {
        if ($this->columns === 1) {
            return $this;
        }
        if ($index < 0 || $index >= $this->columns) {
            throw new \InvalidArgumentException(\sprintf('No column %d in a region of %d.', $index, $this->columns));
        }
        $extent = $this->columnExtent();

        return new self(
            $this->writingMode,
            $this->blockStart,
            $this->blockLimit,
            $this->inlineStart + $index * ($extent + $this->columnGap),
            $extent,
            $this->blockShift,
        );
    }

    /**
     * The same region with its block axis stopping $depth millimetres short —
     * the room a footnote band takes out of the body.
     */
    public function withBlockLimit(float $depth): self
    {
        $limit = $this->isVertical() ? $this->blockLimit + $depth : $this->blockLimit - $depth;

        return new self($this->writingMode, $this->blockStart, $limit, $this->inlineStart, $this->inlineExtent, $this->blockShift, $this->columns, $this->columnGap);
    }

    public function isVertical(): bool
    {
        return $this->writingMode->isVertical();
    }

    /**
     * How much room the block axis offers (millimetres), always positive.
     */
    public function depth(): float
    {
        return $this->isVertical()
            ? $this->blockStart - $this->blockLimit
            : $this->blockLimit - $this->blockStart;
    }

    /** Where a planner should start stacking, on its always-increasing axis. */
    public function planStart(): float
    {
        return $this->signed($this->blockStart);
    }

    /** Where a planner must stop, on the same axis. Always above {@see planStart()}. */
    public function planLimit(): float
    {
        return $this->signed($this->blockLimit);
    }

    /**
     * The page coordinate a planned position lands on — the inverse of the
     * signing done for the planner, and the one place that knows vertical text
     * advances toward smaller x.
     */
    public function blockAt(float $planned): float
    {
        return $this->signed($planned) + $this->blockShift;
    }

    /**
     * Where to start drawing a run of lines planned at $planned: the (x, y) the
     * typesetter treats as the run's origin — top-left when horizontal,
     * top-right when vertical.
     *
     * @return array{0: float, 1: float} x, y in millimetres
     */
    public function originAt(float $planned): array
    {
        $block = $this->blockAt($planned);

        return $this->isVertical()
            ? [$block, $this->inlineStart]
            : [$this->inlineStart, $block];
    }

    /**
     * The rectangle a run of lines occupies, for canvas checks and debug
     * frames. A vertical run is anchored at its right edge, so its box starts
     * $extent to the left of where drawing began.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} x, y, width, height in millimetres
     */
    public function boxAt(float $planned, float $extent): array
    {
        $block = $this->blockAt($planned);

        return $this->isVertical()
            ? [$block - $extent, $this->inlineStart, $extent, $this->inlineExtent]
            : [$this->inlineStart, $block, $this->inlineExtent, $extent];
    }

    /**
     * Negating for vertical is what turns a leftward axis into an increasing
     * one, and it is its own inverse — so the same call converts both ways.
     * Being exact in IEEE 754, it leaves horizontal arithmetic untouched.
     */
    private function signed(float $value): float
    {
        return $this->isVertical() ? -$value : $value;
    }
}
