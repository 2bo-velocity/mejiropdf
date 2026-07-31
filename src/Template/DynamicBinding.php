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

namespace MejiroPDF\Template;

use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\VerticalAlignment;

/**
 * A variable slot in a compiled template. At render time the value is looked up
 * by {@see $key} in the supplied data. Coordinates are in millimetres from the
 * page top-left; size is in points.
 */
final readonly class DynamicBinding
{
    /**
     * @param float               $width         Box width (mm). **Zero selects the legacy
     *                                           point-anchored form**, where ($x, $y) is the
     *                                           text baseline and the run is never wrapped.
     *                                           Any positive width makes ($x, $y) the box
     *                                           top-left, as MejiroPDF Studio authors it.
     * @param float               $height        Box height (mm), used by $verticalAlign.
     * @param float               $rotation      Degrees counter-clockwise about the box centre.
     * @param float               $lineHeight    Line advance as a multiple of the font size.
     * @param bool                $underline     Underline the whole box.
     * @param bool                $multiline     False keeps the content on one line.
     * @param int                 $maxLength     Longest value this slot accepts, in
     *                                           characters; 0 means no ceiling. The
     *                                           designer declares what the box was
     *                                           drawn to hold, and the renderer
     *                                           rejects oversized data before any
     *                                           bytes are written — a silently
     *                                           clipped invoice line is worse than
     *                                           a named failure.
     * @param int                 $maxLines      Most lines this slot accepts once the
     *                                           text has been broken to the box width;
     *                                           0 means no ceiling. Only meaningful for
     *                                           a multiline box — a single-line part
     *                                           never wraps, so it is always one line.
     */
    public function __construct(
        public string $key,
        public float $x,
        public float $y,
        public string $font = 'Helvetica',
        public float $fontSize = 9.0,
        public Alignment $align = Alignment::LEFT,
        public ?string $color = null,
        public float $width = 0.0,
        public float $height = 0.0,
        public float $rotation = 0.0,
        public VerticalAlignment $verticalAlign = VerticalAlignment::TOP,
        public float $lineHeight = 1.6,
        public bool $underline = false,
        public bool $multiline = false,
        /** Drawn when the data carries no value for {@see $key}. */
        public string $fallback = '',
        public int $maxLength = 0,
        public int $maxLines = 0,
    ) {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('maxLength cannot be negative (0 means no limit).');
        }
        if ($maxLines < 0) {
            throw new \InvalidArgumentException('maxLines cannot be negative (0 means no limit).');
        }
    }

    /**
     * Whether $value fits this slot's declared ceiling.
     *
     * Counted in characters rather than bytes: the designer sized the box for
     * "60 文字", and a UTF-8 byte count would reject a third of that in Japanese.
     */
    public function accepts(string $value): bool
    {
        return $this->maxLength === 0 || \mb_strlen($value, 'UTF-8') <= $this->maxLength;
    }

    /**
     * Whether a line count fits this slot's declared ceiling.
     *
     * Separate from {@see accepts()} because the two limits answer different
     * questions: a value can be short and still wrap past the box when the box
     * is narrow, and a long value can fit when it is wide.
     */
    public function acceptsLines(int $lines): bool
    {
        return $this->maxLines === 0 || $lines <= $this->maxLines;
    }

    /** True when this slot wraps — the only case where a line count can vary. */
    public function wraps(): bool
    {
        return $this->multiline && $this->isBoxed();
    }

    /** True when this field carries a designer's box rather than a bare point. */
    public function isBoxed(): bool
    {
        return $this->width > 0.0;
    }
}
