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
 * How far the characters that move in a vertical column actually move, and
 * where the baseline sits inside a cell — the numbers behind
 * {@see VerticalForm}, in ems.
 *
 * These are approximations of what a face would have drawn itself, and every
 * face draws it slightly differently. The defaults are the mean of four faces
 * — IPAex Mincho, IPAex Gothic, Noto Serif CJK JP, Noto Sans CJK JP — measured
 * by following `vert` under the Japanese language system and comparing the
 * horizontal glyph's bounding box against the vertical one's:
 *
 * | | 。、，． | small kana |
 * |---|---|---|
 * | rightward | 0.521 … 0.722, mean **0.64** | 0.079 … 0.166, mean **0.12** |
 * | upward    | 0.576 … 0.688, mean **0.65** | 0.067 … 0.163, mean **0.11** |
 *
 * **The small kana are bimodal, not merely noisy**: the two IPAex faces sit
 * near 0.09 and the two Noto faces near 0.15, so the mean is a number neither
 * pair draws. A fixed number cannot do better than land between them, which is
 * why these are a setting rather than a constant — a document set in one known
 * face is better served by that face's own measurements.
 *
 * None of this is consulted when the face can supply vertical forms itself
 * ({@see \MejiroPDF\Font\VerticalCidFont}); there the typeface is exact and
 * these numbers are not used at all.
 */
final readonly class VerticalFormStyle
{
    /**
     * @param float $upperRightDx Full stop and its family, rightward. Every
     *                            revision of this number has moved it further:
     *                            0.45, then 0.60 from two faces, now 0.64 from
     *                            four. Reading the boxes rather than the page
     *                            is what stopped it being short.
     * @param float $upperRightDy …and upward.
     * @param float $smallKanaDx  Small kana, rightward. They are **never
     *                            scaled**: the glyphs are already drawn small,
     *                            and shrinking them again only thins them.
     * @param float $smallKanaDy  …and upward.
     * @param float $ascent       Where the baseline sits below the cell's top,
     *                            used to centre a turned glyph. Read from the
     *                            face when one day the engine reads `vmtx`;
     *                            until then, the usual CJK value.
     */
    public function __construct(
        public float $upperRightDx = 0.64,
        public float $upperRightDy = 0.65,
        public float $smallKanaDx = 0.12,
        public float $smallKanaDy = 0.11,
        public float $ascent = 0.85,
    ) {
    }
}
