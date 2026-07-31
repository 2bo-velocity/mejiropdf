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

use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\TemplateImage;
use MejiroPDF\Template\TemplateLink;
use MejiroPDF\Template\TemplateText;

/**
 * The freely-placed contents of one block: the same labels, bindings, images
 * and links a template has always had.
 *
 * Blocks did not replace free placement — they wrap it. A block decides *where
 * the group starts* and whether it appears at all; inside it, elements keep the
 * absolute millimetre coordinates a designer dragged them to. **Coordinates
 * here are relative to the block's own top-left**, which is what lets the same
 * element set be drawn once, or once per row, or twice on different pages,
 * without any of its numbers changing.
 *
 * The draw order within a set matches the page contract: static vector art
 * first (it is an opaque byte run), then images, labels, bindings, and finally
 * the link annotations.
 */
final readonly class ElementSet
{
    /**
     * @param string              $staticVectorOps Raw PDF operators, already in the block's local frame.
     * @param list<TemplateText>  $texts           Fixed labels.
     * @param list<DynamicBinding> $bindings       Variable slots. A list rather than a map: a repeater
     *                                             draws the same key once per row.
     * @param list<TemplateImage> $images          Raster placements.
     * @param list<TemplateLink>  $links           Clickable rectangles.
     * @param float               $height          Declared height (mm). Zero lets the renderer measure
     *                                             the content instead.
     */
    public function __construct(
        public array $texts = [],
        public array $bindings = [],
        public array $images = [],
        public array $links = [],
        public float $height = 0.0,
        public string $staticVectorOps = '',
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->texts === []
            && $this->bindings === []
            && $this->images === []
            && $this->links === []
            && $this->staticVectorOps === '';
    }

    /**
     * The height this set occupies (mm): the declared one, or the lowest edge
     * of anything in it.
     *
     * Measuring the box rather than the text is deliberate. Reflowing every
     * element to find the true ink extent would mean laying the block out twice
     * — once to measure, once to draw — and that second pass is exactly the
     * cost this engine exists to avoid. A designer who wants a taller block
     * says so.
     */
    public function measuredHeight(): float
    {
        if ($this->height > 0.0) {
            return $this->height;
        }

        $bottom = 0.0;
        foreach ($this->texts as $t) {
            $bottom = \max($bottom, $t->y + $t->height);
        }
        foreach ($this->bindings as $b) {
            $bottom = \max($bottom, $b->y + $b->height);
        }
        foreach ($this->images as $i) {
            $bottom = \max($bottom, $i->y + $i->height);
        }
        foreach ($this->links as $l) {
            $bottom = \max($bottom, $l->y + $l->height);
        }

        return $bottom;
    }
}
