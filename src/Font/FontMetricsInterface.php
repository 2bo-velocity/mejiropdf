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

namespace MejiroPDF\Font;

/**
 * Glyph-advance metrics for a font, used for wrapping and alignment.
 *
 * Widths are expressed in the PDF glyph space of 1000 units per em:
 *   width(pt) = (sum of advances / 1000) * fontSize
 */
interface FontMetricsInterface
{
    /**
     * Advance width of a single character in 1000-unit em space.
     */
    public function charWidth(string $char): int;

    /**
     * Rendered width of a string in points at the given font size.
     */
    public function textWidth(string $text, float $fontSize): float;
}
