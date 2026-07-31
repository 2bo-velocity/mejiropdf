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
 * Text flow direction. Horizontal advances left→right with lines stacking
 * downward; vertical (right-to-left, for Japanese novels) advances top→bottom
 * with lines stacking leftward. The typesetter abstracts character- and
 * line-advance as vectors so ruby/emphasis/kinsoku logic is shared across both.
 */
enum WritingMode: string
{
    /** Left→right, lines top→bottom (default for reports and Latin text). */
    case HORIZONTAL_TB = 'horizontal-tb';

    /** Top→bottom, lines right→left (Japanese vertical typesetting). */
    case VERTICAL_RL = 'vertical-rl';

    public function isVertical(): bool
    {
        return $this === self::VERTICAL_RL;
    }
}
