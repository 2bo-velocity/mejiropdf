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
 * What a character has to have done to it to read correctly in a vertical
 * column, when the face cannot supply the vertical form itself.
 *
 * A built-in Japanese CIDFont can — `UniJIS-UTF16-V` maps straight to
 * Adobe-Japan1's vertical CIDs ({@see \MejiroPDF\Font\VerticalCidFont}) — and
 * that route is always preferred, because the shapes then come from the
 * typeface rather than from a transform applied to the horizontal ones. An
 * embedded TrueType read through `Identity-H` has no such mapping: a CID is a
 * glyph id and nothing relates it to a vertical variant. For those faces this
 * is the fallback.
 *
 * **Which character belongs where is not decided here.** It comes from
 * Unicode's `Vertical_Orientation` property (UAX #50) by way of the generated
 * {@see VerticalOrientation} table, so the answer is the standard's rather
 * than one project's reading of three sample files. How *far* the moving ones
 * move is a separate question, answered by {@see VerticalFormStyle}.
 *
 * **It remains an approximation.** Measuring two Adobe-Japan1 faces shows
 * `（` `）` and `ー` do fall out of a plain rotation, but `「` and `」` do not:
 * their vertical shapes are drawn, not turned. Rotation gets close and never
 * arrives.
 */
enum VerticalForm
{
    /** Drawn as-is, centred in its cell: kanji, kana, full-width Latin, `・`. */
    case UPRIGHT;

    /** Turned a quarter-turn clockwise: brackets, dashes, the prolonged sound mark. */
    case ROTATE_90;

    /** Same shape, moved to the cell's upper right: the full stop and comma. */
    case SHIFT_UPPER_RIGHT;

    /** Same shape and size, nudged up and right: the small kana. */
    case SHIFT_SMALL_KANA;

    public static function of(string $character): self
    {
        return match (VerticalOrientation::of(\mb_ord($character, 'UTF-8'))) {
            VerticalOrientation::ROTATE => self::ROTATE_90,
            VerticalOrientation::SHIFT => self::SHIFT_UPPER_RIGHT,
            VerticalOrientation::SMALL_KANA => self::SHIFT_SMALL_KANA,
            // UPRIGHT_LETTER and "not listed" both mean nothing to do here:
            // the first stands up as it is, the second is a run's business.
            default => self::UPRIGHT,
        };
    }

    /**
     * The displacement (in ems) this form applies to an upright placement.
     *
     * @return array{0: float, 1: float} rightward, upward
     */
    public function offset(VerticalFormStyle $style = new VerticalFormStyle()): array
    {
        return match ($this) {
            self::SHIFT_UPPER_RIGHT => [$style->upperRightDx, $style->upperRightDy],
            self::SHIFT_SMALL_KANA => [$style->smallKanaDx, $style->smallKanaDy],
            default => [0.0, 0.0],
        };
    }
}
