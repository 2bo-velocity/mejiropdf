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

use MejiroPDF\Text\TextAlign;

/**
 * One {@see GridTable} cell. The text is inline markup (ruby, bold, …). Any of
 * alignment, background and borders may be set to override the column/table
 * defaults — e.g. a right-aligned amount with a DOUBLE bottom rule for a total.
 *
 * A cell may also span neighbouring columns ({@see $colspan}) or the rows that
 * follow it ({@see $rowspan}) — a category name standing beside the three lines
 * it groups.
 */
final readonly class Cell
{
    /**
     * @param int $rowspan How many rows this cell covers. Rows after the first
     *                     leave its columns alone, and the cell is drawn once
     *                     the last of them is known — so it is sized to what it
     *                     actually spans rather than to a guess. Header cells
     *                     ignore it, since the header is redrawn per page.
     */
    public function __construct(
        public string $text,
        public ?TextAlign $align = null,
        public ?string $backgroundColor = null,
        public ?Borders $borders = null,
        public int $colspan = 1,
        public int $rowspan = 1,
    ) {
        if ($colspan < 1) {
            throw new \InvalidArgumentException('colspan must be at least 1.');
        }
        if ($rowspan < 1) {
            throw new \InvalidArgumentException('rowspan must be at least 1.');
        }
    }
}
