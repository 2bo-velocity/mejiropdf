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
 * How furigana is distributed over the characters it annotates.
 *
 * Ruby is nearly always narrower or wider than its parent run, so a rule is
 * needed for the leftover space. JIS X 4051 spreads the ruby evenly across the
 * parent (均等割付), which is what Japanese typesetting expects; the other two
 * modes exist for headings and captions where a solid run reads better.
 */
enum RubyAlignment: string
{
    /**
     * 均等割付 — spread the ruby characters evenly across the parent's width.
     * The default, and what JIS X 4051 prescribes for both mono-ruby and
     * group-ruby.
     */
    case JIS_EQUAL_SPACE = 'jis-equal-space';

    /** Draw the ruby as one solid run, centred over the parent. */
    case CENTER = 'center';

    /** Draw the ruby as one solid run, aligned to the parent's leading edge. */
    case START = 'start';
}
