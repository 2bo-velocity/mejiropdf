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
 * Which point of a {@see \MejiroPDF\Document::textObject()} box is pinned to the
 * given (x, y) — and, when rotated, the pivot the box turns about.
 */
enum Anchor
{
    case TOP_LEFT;
    case TOP_RIGHT;
    case BOTTOM_LEFT;
    case BOTTOM_RIGHT;
    case CENTER;
}
