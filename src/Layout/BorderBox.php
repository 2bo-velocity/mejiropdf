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
 * A framed text block whose height follows its content: {@see \MejiroPDF\Document::drawBox()}
 * typesets the (markup) text within the padding, then draws the background and
 * border around the measured height and returns it, so following content is
 * pushed down and never overlaps the rule.
 */
final readonly class BorderBox
{
    public function __construct(
        public string $text,
        public Borders $borders = new Borders(),
        public Padding $padding = new Padding(),
        public ?string $backgroundColor = null,
    ) {
    }
}
