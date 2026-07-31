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

namespace MejiroPDF\Page;

/**
 * Where laid-out content sits inside a fixed-height box.
 *
 * Only meaningful when the box is taller than its content — a compiled template
 * knows the designer's box, but not how tall the text will turn out until the
 * engine has measured it.
 */
enum VerticalAlignment: string
{
    case TOP    = 'top';
    case MIDDLE = 'middle';
    case BOTTOM = 'bottom';

    /**
     * The offset (in the same unit as the arguments) from the box top at which
     * content of $contentHeight should start inside a $boxHeight box.
     */
    public function offsetIn(float $boxHeight, float $contentHeight): float
    {
        $slack = $boxHeight - $contentHeight;
        if ($slack <= 0.0) {
            return 0.0;
        }

        return match ($this) {
            self::TOP => 0.0,
            self::MIDDLE => $slack / 2.0,
            self::BOTTOM => $slack,
        };
    }
}
