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
 * Where a note written as `((…))` is set.
 *
 * Which of the two a book uses is a decision about the book, not about each
 * note — a textbook puts every note in the margin, a paper puts every note at
 * the foot — so it belongs to the style rather than to the markup. The author
 * writes the same thing either way.
 */
enum NotePlacement
{
    /** A band across the foot of the page, under a rule. */
    case PAGE_FOOT;

    /**
     * The fore-edge margin, beside the line that refers to it — the 傍注 of a
     * Japanese textbook. Costs the body nothing: the margin is outside the
     * flow region, so no room has to be kept and no page is laid out twice.
     */
    case SIDE_MARGIN;
}
