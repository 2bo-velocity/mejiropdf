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

namespace MejiroPDF\Text;

/**
 * Assigns each line a page and a top position within a content area, honouring
 * widow/orphan control ({@see TypesetterOptions::$minLinesAtPageBreak}):
 *
 * - **Orphan**: a paragraph that would leave fewer than `minLines` at the bottom
 *   of the current page is moved whole to the next page.
 * - **Widow**: when a paragraph splits across a page break, at least `minLines`
 *   are kept together on the following page (by pulling lines back) whenever the
 *   paragraph is long enough to satisfy both sides.
 *
 * Pure and unit-agnostic (points or millimetres): the caller supplies line
 * heights and the content top/bottom. It is also direction-agnostic — it only
 * requires a coordinate that **grows** from the content's start toward its end,
 * which is what {@see \MejiroPDF\Layout\FlowRegion} signs the block axis to
 * provide.
 *
 * **Paragraph at a time.** Both widow and orphan control are decided within a
 * single paragraph, so the only state crossing a paragraph boundary is the page
 * number and the cursor. An instance carries exactly that, letting a caller
 * plan a book without ever holding all of its lines:
 *
 * ```php
 * $paginator = new Pagination($top, $bottom, $minLines);
 * foreach ($paragraphs as $paragraph) {
 *     $places = $paginator->place($heightsOf($paragraph));  // …draw, then discard
 * }
 * ```
 *
 * {@see plan()} keeps the whole-document spelling for callers that already hold
 * every line, and is written in terms of the same instance.
 */
final class Pagination
{
    /** Index of the page being filled, counted from the flow's first page. */
    private int $page = 0;

    /** Where the next line goes, on the caller's growing axis. */
    private float $cursor;

    /**
     * @param float|null $firstStart Where to begin on the first page, when
     *                               something already occupies its start — a
     *                               chapter heading, say. Later pages begin at
     *                               $top as usual.
     */
    public function __construct(
        private readonly float $top,
        private float $bottom,
        private readonly int $minLines,
        ?float $firstStart = null,
    ) {
        $this->cursor = $firstStart ?? $top;
    }

    /**
     * Place one paragraph's lines, carrying the cursor into the next call.
     *
     * @param  list<float> $heights height of each line in this paragraph
     * @return list<array{page: int, y: float}> per-line page index and top
     */
    public function place(array $heights): array
    {
        $end = \count($heights) - 1;
        if ($end < 0) {
            return [];
        }

        $placements = \array_fill(0, $end + 1, ['page' => $this->page, 'y' => $this->cursor]);
        self::placeParagraph($heights, 0, $end, $placements, $this->page, $this->cursor, $this->top, $this->bottom, $this->minLines);

        return $placements;
    }

    /** The page the next line would land on, before any further placing. */
    public function page(): int
    {
        return $this->page;
    }

    /** Where a page begins on the growing axis. */
    public function top(): float
    {
        return $this->top;
    }

    /**
     * Put the cursor back at the start of a page, so the same paragraphs can
     * be placed again against a different foot.
     *
     * Laying a page out more than once is how a footnote's room is found: the
     * notes are only known once the page is full, and keeping them with their
     * marks means filling it again against what they need.
     */
    public function startPage(int $page, float $cursor): void
    {
        $this->page = $page;
        $this->cursor = $cursor;
    }

    /** Move the foot of the page, for the band a footnote needs. */
    public function setBottom(float $bottom): void
    {
        $this->bottom = $bottom;
    }

    /**
     * @param  list<float> $heights       height of each line
     * @param  list<bool>  $paragraphEnd  whether each line ends its paragraph
     * @return list<array{page: int, y: float}> per-line page index and top
     */
    public static function plan(array $heights, array $paragraphEnd, float $top, float $bottom, int $minLines): array
    {
        $count = \count($heights);
        $paginator = new self($top, $bottom, $minLines);

        $placements = [];
        $index = 0;
        while ($index < $count) {
            $end = $index;
            while ($end < $count - 1 && !$paragraphEnd[$end]) {
                $end++;
            }
            foreach ($paginator->place(\array_slice($heights, $index, $end - $index + 1)) as $placement) {
                $placements[] = $placement;
            }
            $index = $end + 1;
        }

        return $placements;
    }

    /**
     * @param list<float>                       $heights
     * @param list<array{page: int, y: float}>  $placements
     */
    private static function placeParagraph(
        array $heights,
        int $start,
        int $end,
        array &$placements,
        int &$page,
        float &$y,
        float $top,
        float $bottom,
        int $minLines,
    ): void {
        $total = $end - $start + 1;

        // Orphan: too few of a splitting paragraph fit here → start it fresh.
        $fitHere = self::countFit($heights, $start, $end, $y, $bottom);
        if ($fitHere < $total && $fitHere < $minLines && $y > $top + 1.0e-6) {
            $page++;
            $y = $top;
        }

        $index = $start;
        while ($index <= $end) {
            $fit = self::countFit($heights, $index, $end, $y, $bottom);
            $remaining = $end - $index + 1;
            $place = \max(1, $fit); // force at least one line even if it overflows

            if ($place < $remaining) {
                $rest = $remaining - $place;
                if ($rest < $minLines) {
                    // Widow: keep minLines for the next page when the paragraph
                    // is long enough to also leave minLines here.
                    $reduced = $remaining - $minLines;
                    if ($reduced >= $minLines) {
                        $place = \min($place, $reduced);
                    }
                }
            }

            for ($k = 0; $k < $place; $k++) {
                $placements[$index + $k] = ['page' => $page, 'y' => $y];
                $y += $heights[$index + $k];
            }
            $index += $place;

            if ($index <= $end) {
                $page++;
                $y = $top;
            }
        }
    }

    /**
     * How many of lines [from, to] fit below $y before $bottom.
     *
     * @param list<float> $heights
     */
    private static function countFit(array $heights, int $from, int $to, float $y, float $bottom): int
    {
        $count = 0;
        $cursor = $y;
        for ($k = $from; $k <= $to; $k++) {
            if ($cursor + $heights[$k] > $bottom) {
                break;
            }
            $cursor += $heights[$k];
            $count++;
        }

        return $count;
    }
}
