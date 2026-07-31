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
 * Direction-relative line alignment for the typesetter. START/END follow the
 * writing mode (horizontal: left/right; vertical: top/bottom), so the same
 * value reads correctly in both — this is why it is distinct from the concrete
 * {@see \MejiroPDF\Page\Alignment} (LEFT/CENTER/RIGHT) used by page furniture.
 * CENTER centres within the line's cross size; JUSTIFY spreads to both edges.
 */
enum TextAlign: string
{
    case START = 'start';
    case CENTER = 'center';
    case END = 'end';
    case JUSTIFY = 'justify';
}
