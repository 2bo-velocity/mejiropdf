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
 * The four edges of a box (or table cell). A null side draws no rule.
 */
final readonly class Borders
{
    public function __construct(
        public ?BorderStyle $top = null,
        public ?BorderStyle $right = null,
        public ?BorderStyle $bottom = null,
        public ?BorderStyle $left = null,
    ) {
    }

    /**
     * The same rule on all four sides.
     */
    public static function all(BorderStyle $style = new BorderStyle()): self
    {
        return new self($style, $style, $style, $style);
    }
}
