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

use MejiroPDF\Block\ElementSet;
use MejiroPDF\Document;
use MejiroPDF\Font\FontMetrics;
use MejiroPDF\Font\FontResolver;
use MejiroPDF\Image\Image;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TextStyle;
use MejiroPDF\Text\TypesetterOptions;

/**
 * Draws the freely-placed contents of a template — labels, bound fields,
 * images, links — onto a page.
 *
 * Split out of {@see TemplateRenderer} so the block layer can reuse it. Both
 * callers need exactly the same painting; what differs is only *where the group
 * starts*, which arrives here as an offset. That offset is what lets one
 * element set be drawn once, once per row of a repeater, or twice on different
 * pages, without any of its coordinates changing.
 *
 * The class holds no state between calls: a page is a sequence of paints, and
 * nothing carried between them could survive the streaming model anyway.
 */
final class ElementPainter
{
    /**
     * Paint one element set with its origin moved to ($dx, $dy) millimetres.
     *
     * Order matches the page contract: static vector art (an opaque byte run)
     * first, then images, labels, bound values, and the link annotations last.
     *
     * @param array<string, mixed> $data
     */
    public function paint(
        Document $document,
        ElementSet $set,
        array $data,
        float $dx = 0.0,
        float $dy = 0.0,
        bool $opsAreLocal = false,
    ): void {
        if ($set->staticVectorOps !== '') {
            // Raw operators carry their own coordinates, so an offset cannot be
            // applied to them the way it is to a text box. A block's shapes are
            // therefore compiled in the block's own frame and translated into
            // place here — which is what lets the same set be drawn twice, on
            // different pages, without its numbers changing.
            $document->appendContentOps(
                $opsAreLocal
                    ? \sprintf(
                        "q\n1 0 0 1 %.4F %.4F cm\n%sQ\n",
                        PageSize::mm($dx),
                        $document->pageHeightMm() * PageSize::MM_TO_PT - PageSize::mm($dy),
                        $set->staticVectorOps,
                    )
                    : $set->staticVectorOps,
            );
        }

        foreach ($set->images as $image) {
            $this->drawImage($document, $image, $data, $dx, $dy);
        }

        foreach ($set->texts as $text) {
            $this->drawText($document, $text, $text->text, $dx, $dy);
        }

        foreach ($set->bindings as $binding) {
            $value = self::valueFor($binding, $data);
            if ($value !== '') {
                $this->drawText($document, $binding, $value, $dx, $dy);
            }
        }

        foreach ($set->links as $link) {
            if ($link->url !== '') {
                $document->link($link->x + $dx, $link->y + $dy, $link->width, $link->height, $link->url);
            }
        }
    }

    /**
     * The string a binding resolves to: the supplied value, or the template's
     * own fallback when the data has nothing for that key.
     *
     * The key may be a dotted path (`item.name`), which is how a repeater row
     * addresses its own record.
     *
     * @param array<string, mixed> $data
     */
    public static function valueFor(DynamicBinding $binding, array $data): string
    {
        $value = \array_key_exists($binding->key, $data)
            ? $data[$binding->key]
            : \MejiroPDF\Block\DataPath::resolve($data, $binding->key);

        $string = match (true) {
            $value === null, \is_array($value) => '',
            \is_bool($value) => $value ? '1' : '',
            \is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };

        return $string === '' ? $binding->fallback : $string;
    }

    /**
     * Draw one label or filled field.
     *
     * A part with no width is the legacy point-anchored form: ($x, $y) is the
     * text baseline and the run is drawn as-is. Any positive width means the
     * designer gave the part a box, so the text is laid out inside it — wrapped
     * when it is a multiline part, aligned on both axes, and rotated about the
     * box centre.
     */
    private function drawText(
        Document $document,
        TemplateText|DynamicBinding $part,
        string $text,
        float $dx = 0.0,
        float $dy = 0.0,
    ): void {
        $face = FontResolver::resolve($part->font, $document->namedFonts());
        $x = $part->x + $dx;
        $y = $part->y + $dy;

        if (!$part->isBoxed()) {
            $document->textAligned($x, $y, $text, $face, $part->fontSize, $part->align, $part->color);

            return;
        }

        // Studio authors verbatim content, so inline markup stays off: a label
        // reading "**2024" is a label, not the start of a bold run. This is
        // also the typesetter's parsing-free fast path.
        $options = new TypesetterOptions(
            align: self::textAlign($part->align),
            enableRuby: false,
            enableBouten: false,
            enableDropCaps: false,
            enableHyphenation: false,
            enableSmartQuotes: false,
            baseStyle: new TextStyle(isUnderline: $part->underline, color: $part->color),
            lineHeight: $part->lineHeight,
        );

        // A single-line part must not wrap, however narrow its box: the Studio
        // warns about the overflow at design time rather than silently
        // reflowing text the designer laid out as one line.
        $width = $part->multiline
            ? $part->width
            : \max($part->width, FontMetrics::for($face)->textWidth($text, $part->fontSize) / PageSize::MM_TO_PT);

        if ($part->rotation !== 0.0) {
            $document->textObject(
                $text,
                $x + $part->width / 2.0,
                $y + $part->height / 2.0,
                $width,
                $face,
                $part->fontSize,
                $options,
                Anchor::CENTER,
                $part->rotation,
            );

            return;
        }

        $top = $y;
        if ($part->height > 0.0) {
            $content = $document->measureTextHeight($text, $width, $face, $part->fontSize, $options);
            $top += $part->verticalAlign->offsetIn($part->height, $content);
        }

        $document->typeset($text, $x, $top, $width, $face, $part->fontSize, $options);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function drawImage(
        Document $document,
        TemplateImage $image,
        array $data,
        float $dx = 0.0,
        float $dy = 0.0,
    ): void {
        $bytes = $this->imageBytes($image, $data);
        if ($bytes === '' || $image->width <= 0.0 || $image->height <= 0.0) {
            // A missing image degrades the page rather than failing the run — a
            // thousand-record batch should not die because one photo is gone —
            // so this is the one place the renderer needs to say something.
            $document->logger()->warning('Skipped a template image with no usable source', [
                'key' => $image->key,
                'x' => $image->x,
                'y' => $image->y,
            ]);

            return;
        }

        $raster = Image::fromString($bytes);
        [$drawWidth, $drawHeight] = $this->fitted($image, $raster->width() / \max($raster->height(), 1));
        $left = $image->x + $dx + ($image->width - $drawWidth) / 2.0;
        $top = $image->y + $dy + ($image->height - $drawHeight) / 2.0;

        // The drawn image is centred in its element box whatever the fit, so the
        // box centre the designer rotated about is the centre Document::image()
        // turns about.
        $document->image($raster, $left, $top, $drawWidth, $drawHeight, $image->rotation);
    }

    /**
     * The bytes to embed: a bound value when the data supplies one (either a
     * readable path or the raw bytes themselves), else the copy baked into the
     * template at compile time.
     *
     * @param array<string, mixed> $data
     */
    private function imageBytes(TemplateImage $image, array $data): string
    {
        if ($image->key === null) {
            return $image->data;
        }

        $value = $data[$image->key] ?? null;
        $supplied = \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        if ($supplied === '') {
            return $image->data;
        }
        if (!\is_file($supplied)) {
            return $supplied;
        }

        $contents = @\file_get_contents($supplied);

        return $contents === false ? $image->data : $contents;
    }

    /**
     * The drawn size (mm) for the requested fit. `cover` deliberately overflows
     * the box on one axis — the same trade CSS `object-fit: cover` makes.
     *
     * @return array{0: float, 1: float}
     */
    private function fitted(TemplateImage $image, float $aspect): array
    {
        if ($image->fit === 'stretch' || $aspect <= 0.0) {
            return [$image->width, $image->height];
        }

        $byWidth = [$image->width, $image->width / $aspect];
        $byHeight = [$image->height * $aspect, $image->height];

        if ($image->fit === 'cover') {
            return $byWidth[1] >= $image->height ? $byWidth : $byHeight;
        }

        return $byWidth[1] <= $image->height ? $byWidth : $byHeight;
    }

    private static function textAlign(Alignment $align): TextAlign
    {
        return match ($align) {
            Alignment::LEFT => TextAlign::START,
            Alignment::CENTER => TextAlign::CENTER,
            Alignment::RIGHT => TextAlign::END,
        };
    }
}
