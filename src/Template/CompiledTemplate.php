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

use MejiroPDF\Block\Block;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\JapaneseSpacing;

/**
 * A loaded, ready-to-render compiled template (`.mpb`).
 *
 * The static segment holds background vector operators that stream straight to
 * the page; static labels and variable bindings are drawn with document-managed
 * fonts. Memory footprint is a few KB regardless of report length.
 */
final readonly class CompiledTemplate
{
    /**
     * @param string                        $staticVectorOps Raw PDF vector operators (points, PDF space).
     * @param list<TemplateText>            $texts           Fixed labels.
     * @param array<string, DynamicBinding> $bindings        Variable slots, keyed by field name.
     * @param list<TemplateImage>           $images          Raster placements, drawn under the text.
     * @param list<TemplateLink>            $links           Clickable rectangles.
     * @param list<Block>                   $blocks          Structural blocks stacked down the page.
     *                                                       Empty means the template is pure free
     *                                                       placement, which is how every `.mpb`
     *                                                       written before blocks existed reads.
     * @param array<string, TrueTypeFont>   $fonts           Fonts carried inside the file, keyed by
     *                                                       the name bindings refer to them by. A
     *                                                       template that embeds its fonts needs
     *                                                       nothing installed on the server.
     */
    public function __construct(
        public int $version,
        public int $flags,
        public float $width,
        public float $height,
        public string $staticVectorOps,
        public array $texts,
        public array $bindings,
        public array $images = [],
        public array $links = [],
        public array $blocks = [],
        public float $marginTop = 0.0,
        public float $marginRight = 0.0,
        public float $marginBottom = 0.0,
        public float $marginLeft = 0.0,
        public array $fonts = [],
        /**
         * Spacing between Japanese and Latin, chosen by the designer in Studio.
         * Off unless the template asks: switching it on moves every line break,
         * so a template built before it existed must keep its measurements.
         */
        public JapaneseSpacing $japaneseSpacing = JapaneseSpacing::NONE,
        /**
         * The document's own title, author, subject and keywords, as the
         * designer wrote them — `{{ field }}` and all. They are the *output's*
         * properties, not the template's name, which is why they are separate
         * from anything Studio shows in its own title bar. Resolved against the
         * record at render time.
         *
         * @var array{title?: string, author?: string, subject?: string, keywords?: string}
         */
        public array $document = [],
    ) {
    }

    public function pageWidthMm(): float
    {
        return $this->width / PageSize::MM_TO_PT;
    }

    public function pageHeightMm(): float
    {
        return $this->height / PageSize::MM_TO_PT;
    }

    public function pageSize(): PageSize
    {
        return new PageSize($this->width, $this->height);
    }
}
