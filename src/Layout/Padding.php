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
 * Inner spacing (millimetres) between a box's border and its text, so text
 * never touches the rule as the box grows.
 */
final readonly class Padding
{
    public function __construct(
        public float $top = 3.0,
        public float $right = 3.0,
        public float $bottom = 3.0,
        public float $left = 3.0,
    ) {
    }

    public static function all(float $millimeters): self
    {
        return new self($millimeters, $millimeters, $millimeters, $millimeters);
    }
}
