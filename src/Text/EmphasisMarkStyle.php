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
 * How emphasis dots (傍点) are shaped, sized, and spaced from the text.
 *
 * The mark is named by the character it resembles but is drawn as vector
 * geometry, not set as a glyph. That keeps it independent of whatever font the
 * text uses: no CJK subset needs to carry U+25CF, and a mark can never come out
 * as tofu. It also means the size below is the mark's diameter rather than a
 * font size.
 *
 * Marks sit above the parent when setting horizontally and to its right when
 * setting vertically, mirroring ruby.
 */
final readonly class EmphasisMarkStyle
{
    /** Mark diameter as a fraction of the parent size when none is given. */
    public const float DEFAULT_SCALE = 0.35;

    /** 黒丸 — a filled disc, the usual Japanese emphasis mark. */
    public const string FILLED_CIRCLE = '•';
    /** 白丸 — an open circle. */
    public const string OPEN_CIRCLE = '◦';
    /** 蛇の目 — a filled disc inside a ring. */
    public const string BULLSEYE = '◉';
    /** 三角 — a filled triangle. */
    public const string TRIANGLE = '▲';

    /**
     * @param string     $mark     One of the mark constants; anything else falls
     *                             back to {@see FILLED_CIRCLE}.
     * @param float|null $fontSize Mark diameter in points; null derives it from the
     *                             parent run at {@see DEFAULT_SCALE}.
     * @param float      $offset   Gap in points between the parent and the mark.
     */
    public function __construct(
        public string $mark = self::FILLED_CIRCLE,
        public ?float $fontSize = null,
        public float $offset = 1.5,
    ) {
        if ($fontSize !== null && $fontSize <= 0.0) {
            throw new \InvalidArgumentException('Emphasis mark fontSize must be positive.');
        }
        if ($offset < 0.0) {
            throw new \InvalidArgumentException('Emphasis mark offset cannot be negative.');
        }
    }

    /**
     * The mark's diameter for a parent run of the given size.
     */
    public function sizeFor(float $parentSize): float
    {
        return $this->fontSize ?? $parentSize * self::DEFAULT_SCALE;
    }

    /**
     * Whether this mark is one the renderer knows how to draw. Unknown marks
     * are drawn as a filled disc rather than rejected, so a typo degrades to
     * the common case instead of failing a whole document.
     */
    public function isKnownMark(): bool
    {
        return \in_array(
            $this->mark,
            [self::FILLED_CIRCLE, self::OPEN_CIRCLE, self::BULLSEYE, self::TRIANGLE],
            true,
        );
    }
}
