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
 * heights and the content top/bottom.
 */
final class Pagination
{
    /**
     * @param  list<float> $heights       height of each line
     * @param  list<bool>  $paragraphEnd  whether each line ends its paragraph
     * @return list<array{page: int, y: float}> per-line page index and top
     */
    public static function plan(array $heights, array $paragraphEnd, float $top, float $bottom, int $minLines): array
    {
        $count = \count($heights);
        $placements = \array_fill(0, $count, ['page' => 0, 'y' => $top]);

        $page = 0;
        $y = $top;
        $index = 0;
        while ($index < $count) {
            $end = $index;
            while ($end < $count - 1 && !$paragraphEnd[$end]) {
                $end++;
            }
            self::placeParagraph($heights, $index, $end, $placements, $page, $y, $top, $bottom, $minLines);
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
