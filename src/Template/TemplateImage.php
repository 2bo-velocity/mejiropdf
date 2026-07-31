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
 * A raster image placed by a compiled template.
 *
 * The bytes are carried inside the `.mpb` so a template is a single portable
 * file; {@see $key} instead defers the choice to render time, which is how a
 * report stamps a per-record photo or logo.
 */
final readonly class TemplateImage
{
    /**
     * @param string      $data     Raw JPEG/PNG bytes; empty when $key supplies them.
     * @param string|null $key      Data key whose value (raw bytes or a path) replaces $data.
     * @param float       $x        Box left (mm from the page top-left).
     * @param float       $y        Box top (mm).
     * @param float       $width    Box width (mm).
     * @param float       $height   Box height (mm).
     * @param float       $rotation Degrees counter-clockwise about the box centre.
     * @param string      $fit      `contain` | `cover` | `stretch`.
     */
    public function __construct(
        public string $data,
        public ?string $key,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $rotation = 0.0,
        public string $fit = 'contain',
    ) {
    }
}
