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

namespace MejiroPDF\Layout;

/**
 * A single rule's appearance: width in millimetres, "#RRGGBB" colour, and
 * {@see LineStyle}. Shared by {@see Borders} for boxes and, later, table cells.
 */
final readonly class BorderStyle
{
    public function __construct(
        public float $width = 0.3,
        public string $color = '#000000',
        public LineStyle $style = LineStyle::SOLID,
    ) {
        if ($width < 0.0) {
            throw new \InvalidArgumentException('Border width must not be negative.');
        }
    }
}
