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
 * One column of a {@see BlockKind::COLUMNS} block.
 *
 * The width is written the way a designer thinks about it — `"60%"` of the
 * container, or `"80mm"` / `"220pt"` when the column holds something of a fixed
 * size like a seal box.
 */
final readonly class BlockColumn
{
    /**
     * @param string     $width      `"60%"`, `"80mm"` or `"220pt"`. Percentages are of the container.
     * @param bool       $autoExpand Take the space of a hidden sibling. False keeps the column where the
     *                               designer put it — which is what a seal box or a signature line needs,
     *                               since those are positioned against the paper, not against their neighbour.
     * @param string|null $condition Hides the column when false ({@see Condition}).
     */
    public function __construct(
        public ElementSet $elements,
        public string $width = '100%',
        public bool $autoExpand = true,
        public ?string $condition = null,
        public string $id = '',
    ) {
    }

    /**
     * This column's width in millimetres, given the container's.
     *
     * A malformed width falls back to the full container rather than to zero:
     * a column that is too wide is visible and obviously wrong, whereas one of
     * zero width silently disappears.
     */
    public function widthMm(float $containerWidth): float
    {
        $spec = \trim($this->width);

        if (\str_ends_with($spec, '%')) {
            $percent = (float) \rtrim($spec, '% ');

            return $containerWidth * $percent / 100.0;
        }
        if (\str_ends_with($spec, 'mm')) {
            return (float) \rtrim($spec, 'm ');
        }
        if (\str_ends_with($spec, 'pt')) {
            return (float) \rtrim($spec, 'pt ') / 2.834645669;
        }

        return \is_numeric($spec) ? (float) $spec : $containerWidth;
    }
}
