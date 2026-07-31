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
 * How a rule is stroked. DOUBLE draws two parallel lines (common on Japanese
 * total rows); DASHED/DOTTED set a PDF dash pattern; NONE draws nothing.
 */
enum LineStyle
{
    case SOLID;
    case DASHED;
    case DOTTED;
    case DOUBLE;
    case NONE;
}
