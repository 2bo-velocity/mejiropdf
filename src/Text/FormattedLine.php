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
 * One laid-out line produced by the {@see LineBreaker}: the units in order plus
 * their total advance in points. With hanging punctuation ({@see
 * TypesetterOptions::$enableBurasage}) {@see $width} can exceed the requested
 * box width by the hung glyph. The typesetter turns this into draw operators.
 */
final readonly class FormattedLine
{
    /**
     * @param list<LineUnit> $units
     * @param bool           $isLastInParagraph the last line of its paragraph
     *                       (or a hard `\n`), which JUSTIFY must not stretch
     * @param float          $indent leading offset (points) reserved on this
     *                       line, e.g. beside a drop cap
     */
    public function __construct(
        public array $units,
        public float $width,
        public bool $isLastInParagraph = false,
        public float $indent = 0.0,
        public bool $endsWithHyphen = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->units === [];
    }

    /**
     * The largest effective font size on the line (points), for line-height and
     * baseline calculations. Zero for an empty line.
     */
    public function maxFontSize(): float
    {
        $max = 0.0;
        foreach ($this->units as $unit) {
            $max = \max($max, $unit->fontSize);
        }

        return $max;
    }

    public function hasRuby(): bool
    {
        foreach ($this->units as $unit) {
            if ($unit->rubyText !== null) {
                return true;
            }
        }

        return false;
    }
}
