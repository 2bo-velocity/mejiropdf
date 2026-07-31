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

use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\VerticalAlignment;

/**
 * A fixed text label baked into a compiled template (e.g. "Invoice", "合計").
 * Coordinates are in millimetres from the page top-left; size is in points.
 */
final readonly class TemplateText
{
    /**
     * @param float               $width         Box width (mm). **Zero selects the legacy
     *                                           point-anchored form**, where ($x, $y) is the
     *                                           text baseline and the run is never wrapped.
     *                                           Any positive width makes ($x, $y) the box
     *                                           top-left, as MejiroPDF Studio authors it.
     * @param float               $height        Box height (mm), used by $verticalAlign.
     * @param float               $rotation      Degrees counter-clockwise about the box centre.
     * @param float               $lineHeight    Line advance as a multiple of the font size.
     * @param bool                $underline     Underline the whole box.
     * @param bool                $multiline     False keeps the content on one line.
     */
    public function __construct(
        public string $text,
        public float $x,
        public float $y,
        public string $font = 'Helvetica',
        public float $fontSize = 9.0,
        public Alignment $align = Alignment::LEFT,
        public ?string $color = null,
        public float $width = 0.0,
        public float $height = 0.0,
        public float $rotation = 0.0,
        public VerticalAlignment $verticalAlign = VerticalAlignment::TOP,
        public float $lineHeight = 1.6,
        public bool $underline = false,
        public bool $multiline = false,
    ) {
    }

    /** True when this label carries a designer's box rather than a bare point. */
    public function isBoxed(): bool
    {
        return $this->width > 0.0;
    }
}
