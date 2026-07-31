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

namespace MejiroPDF\Block;

/**
 * Whether a block takes its place from the flow or from the page.
 */
enum PositionMode: string
{
    /**
     * Drawn at the cursor left by the previous flow block, and advances it.
     *
     * This is what makes a hidden block collapse: skipping it adds nothing to
     * the cursor, so everything below moves up on its own. No layout pass and
     * no reflow — the cursor is simply not incremented.
     */
    case FLOW = 'flow';

    /**
     * Drawn at its own coordinates, and leaves the cursor untouched.
     *
     * For the marks that belong to the sheet rather than to the sequence: a
     * seal box, a corner logo, a watermark.
     */
    case ABSOLUTE = 'absolute';

    public function isFlow(): bool
    {
        return $this === self::FLOW;
    }
}
