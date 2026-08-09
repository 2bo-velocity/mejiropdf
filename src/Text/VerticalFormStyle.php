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
 * face draws it slightly differently: measuring Noto Serif CJK JP against Noto
 * Sans CJK JP put the small kana 0.03 em apart. The defaults are the middle of
 * that, which is the best a fixed number can do — so they are a setting rather
 * than a constant, for anyone setting a face that disagrees.
 *
 * None of this is consulted when the face can supply vertical forms itself
 * ({@see \MejiroPDF\Font\VerticalCidFont}); there the typeface is exact and
 * these numbers are not used at all.
 */
final readonly class VerticalFormStyle
{
    /**
     * @param float $upperRightDx Full stop and its family, rightward. Measured
     *                            at 0.60 em on both axes across two faces —
     *                            the engine's earlier 0.45 was visibly short.
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
        public float $upperRightDx = 0.60,
        public float $upperRightDy = 0.60,
        public float $smallKanaDx = 0.15,
        public float $smallKanaDy = 0.12,
        public float $ascent = 0.85,
    ) {
    }
}
