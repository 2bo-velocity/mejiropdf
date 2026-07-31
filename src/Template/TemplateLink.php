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

namespace MejiroPDF\Template;

/**
 * A clickable rectangle in a compiled template. Coordinates are millimetres
 * from the page top-left; the text under it is a separate {@see TemplateText}.
 */
final readonly class TemplateLink
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $url,
    ) {
    }
}
