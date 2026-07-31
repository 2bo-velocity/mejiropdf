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

namespace MejiroPDF\Table;

use MejiroPDF\Page\Alignment;

/**
 * A table column definition. Width and padding are in millimetres.
 */
final readonly class Column
{
    public function __construct(
        public string $title,
        public float $width,
        public Alignment $align = Alignment::LEFT,
        public float $padding = 1.5,
    ) {
        if ($width <= 0.0) {
            throw new \InvalidArgumentException('Column width must be positive.');
        }
    }
}
