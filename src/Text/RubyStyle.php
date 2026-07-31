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

use MejiroPDF\Font\FontFace;

/**
 * How furigana is sized, fonted, and spaced away from the text it annotates.
 *
 * The defaults follow JIS X 4051: half the parent's size, the parent's own
 * font, and a small gap. Override only what a design actually needs — passing
 * null for size or font keeps the derived value, so a caller who cares about
 * the gap alone does not have to restate the rest.
 *
 * Enlarging the size or the gap makes the line taller (or wider, when setting
 * vertically); the typesetter accounts for that so adjacent lines never collide.
 */
final readonly class RubyStyle
{
    /** Ruby size as a fraction of the parent when none is given (JIS X 4051). */
    public const float DEFAULT_SCALE = 0.5;

    /**
     * @param float|null    $fontSize Ruby size in points; null derives it from the
     *                                parent run at {@see DEFAULT_SCALE}.
     * @param FontFace|null $font     Font for the ruby; null inherits the parent's.
     *                                A run this face has no glyphs for falls back
     *                                to the parent's, so a Latin display face set
     *                                here never turns furigana into 豆腐.
     * @param float         $offset   Gap in points between the parent and the ruby:
     *                                parent top to ruby baseline when setting
     *                                horizontally, parent right edge to ruby centre
     *                                when setting vertically.
     */
    public function __construct(
        public ?float $fontSize = null,
        public ?FontFace $font = null,
        public float $offset = 1.5,
        public RubyAlignment $alignment = RubyAlignment::JIS_EQUAL_SPACE,
    ) {
        if ($fontSize !== null && $fontSize <= 0.0) {
            throw new \InvalidArgumentException('Ruby fontSize must be positive.');
        }
        if ($offset < 0.0) {
            throw new \InvalidArgumentException('Ruby offset cannot be negative.');
        }
    }

    /**
     * The ruby size for a parent run of the given size.
     */
    public function sizeFor(float $parentSize): float
    {
        return $this->fontSize ?? $parentSize * self::DEFAULT_SCALE;
    }

    /**
     * How much taller (horizontal) or wider (vertical) a line carrying ruby
     * becomes: the ruby itself plus the gap that holds it off the parent.
     */
    public function bandFor(float $parentSize): float
    {
        return $this->sizeFor($parentSize) + $this->offset;
    }
}
