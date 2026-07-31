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
 * Horizontal text alignment, shared by page numbers, table cells and credits.
 */
enum Alignment: string
{
    case LEFT   = 'left';
    case CENTER = 'center';
    case RIGHT  = 'right';

    /**
     * The opposite side (used to auto-place the credit away from page numbers).
     * CENTER maps to RIGHT by convention.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::LEFT => self::RIGHT,
            self::RIGHT => self::LEFT,
            self::CENTER => self::RIGHT,
        };
    }
}
