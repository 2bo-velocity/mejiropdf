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
use MejiroPDF\Block\BlockColumn;
use MejiroPDF\Block\BlockKind;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Font\TrueTypeFont;

/**
 * Serialises a template into `.mpb` (MPBF) bytes.
 *
 * MejiroPDF Studio (Rust) is the primary producer of `.mpb`; this PHP writer is
 * a reference implementation of the exact byte layout, useful for tests and for
 * a PHP-side compiler.
 */
final class MpbWriter
{
    /**
     * @param string               $staticVectorOps Raw PDF vector operators (points, PDF space).
     * @param list<TemplateText>   $texts           Fixed labels.
     * @param list<DynamicBinding> $bindings        Variable slots.
     * @param list<TemplateImage>  $images          Raster placements.
     * @param list<TemplateLink>   $links           Clickable rectangles.
     * @param list<Block>          $blocks          Structural blocks stacked down the page.
     * @param array{top?: float, right?: float, bottom?: float, left?: float} $margin Page margins (mm).
     * @param array<string, TrueTypeFont> $fonts Fonts to carry inside the file, keyed by the name
     *                                           bindings refer to them by.
     */
    public static function build(
        float $width,
        float $height,
        string $staticVectorOps,
        array $texts = [],
        array $bindings = [],
        int $flags = 0,
        array $images = [],
        array $links = [],
        array $blocks = [],
        array $margin = [],
        array $fonts = [],
    ): string {
        $bindingMap = [];
        foreach ($bindings as $b) {
            $bindingMap[$b->key] = [
                'fallback' => $b->fallback,
                'maxLength' => $b->maxLength,
                'maxLines' => $b->maxLines,
            ] + self::box($b);
        }

        $payload = [
            'meta' => ['width' => $width, 'height' => $height],
            'texts' => \array_map(
                static fn (TemplateText $t): array => ['text' => $t->text] + self::box($t),
                $texts,
            ),
            'bindings' => (object) $bindingMap,
            'images' => \array_map(self::image(...), $images),
            'links' => \array_map(self::link(...), $links),
        ];

        // Written only when present, so a template without blocks produces the
        // exact bytes it produced before blocks existed.
        if ($blocks !== []) {
            $payload['blocks'] = \array_map(self::block(...), $blocks);
        }
        $margins = [
            'top' => (float) ($margin['top'] ?? 0.0),
            'right' => (float) ($margin['right'] ?? 0.0),
            'bottom' => (float) ($margin['bottom'] ?? 0.0),
            'left' => (float) ($margin['left'] ?? 0.0),
        ];
        // An all-zero margin is the absence of one, and writing it would change
        // the bytes of every template that predates margins.
        if ($margins !== ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0]) {
            $payload['margin'] = $margins;
        }

        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to JSON-encode template bindings.');
        }

        // The flag is derived, not asked for: a caller who passes fonts always
        // means for them to be readable, and one who passes none must not
        // produce a file that claims a segment it does not have.
        if ($fonts !== []) {
            $flags |= Mpb::FLAG_FONTS;
        }

        $header = \pack(
            'a4nnNN',
            Mpb::MAGIC,
            Mpb::VERSION,
            $flags,
            \strlen($staticVectorOps),
            \strlen($json),
        );

        return $header . $staticVectorOps . $json . self::fontSegment($fonts);
    }

    /**
     * Encode the font segment described by {@see Mpb::FLAG_FONTS}.
     *
     * Fonts go in whole and uncompressed. Whole, because the template does not
     * know its data — cutting the font down to the glyphs in the static text
     * would blank the first customer name with a character the designer never
     * typed; the engine subsets per document instead, where the glyphs actually
     * drawn are known. Uncompressed, because Studio's compiler carries no
     * dependencies, and a DEFLATE encoder is a poor trade for a font that is
     * mostly already-compact outline data.
     *
     * @param array<string, TrueTypeFont> $fonts
     */
    private static function fontSegment(array $fonts): string
    {
        if ($fonts === []) {
            return '';
        }

        $out = \pack('n', \count($fonts));
        foreach ($fonts as $name => $font) {
            $out .= \pack('n', \strlen($name)) . $name
                . \pack('N', \strlen($font->rawBytes)) . $font->rawBytes;
        }

        return $out;
    }

    /**
     * Serialise a {@see CompiledTemplate} back into `.mpb` bytes.
     */
    public static function fromTemplate(CompiledTemplate $template): string
    {
        return self::build(
            $template->width,
            $template->height,
            $template->staticVectorOps,
            $template->texts,
            \array_values($template->bindings),
            $template->flags,
            $template->images,
            $template->links,
            $template->blocks,
            [
                'top' => $template->marginTop,
                'right' => $template->marginRight,
                'bottom' => $template->marginBottom,
                'left' => $template->marginLeft,
            ],
            $template->fonts,
        );
    }

    /**
     * The geometry and typography a label and a binding describe identically.
     *
     * @return array<string, mixed>
     */
    private static function box(TemplateText|DynamicBinding $part): array
    {
        return [
            'x' => $part->x,
            'y' => $part->y,
            'w' => $part->width,
            'h' => $part->height,
            'rotation' => $part->rotation,
            'font' => $part->font,
            'size' => $part->fontSize,
            'align' => $part->align->value,
            'valign' => $part->verticalAlign->value,
            'lineHeight' => $part->lineHeight,
            'color' => $part->color,
            'underline' => $part->underline,
            'multiline' => $part->multiline,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(TemplateImage $i): array
    {
        return [
            'data' => \base64_encode($i->data),
            'key' => $i->key,
            'x' => $i->x,
            'y' => $i->y,
            'w' => $i->width,
            'h' => $i->height,
            'rotation' => $i->rotation,
            'fit' => $i->fit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function link(TemplateLink $l): array
    {
        return ['x' => $l->x, 'y' => $l->y, 'w' => $l->width, 'h' => $l->height, 'url' => $l->url];
    }

    /**
     * One block and everything under it.
     *
     * Only the keys a given kind actually uses are written, so a `single` block
     * does not carry an empty repeater's worth of nulls.
     *
     * @return array<string, mixed>
     */
    private static function block(Block $b): array
    {
        $out = [
            'id' => $b->id,
            'name' => $b->name,
            'type' => $b->kind->value,
            'positionMode' => $b->positionMode->value,
            'x' => $b->x,
            'y' => $b->y,
            'w' => $b->width,
            'marginBottom' => $b->marginBottom,
            'visible' => $b->visible,
        ];
        if ($b->condition !== null) {
            $out['condition'] = $b->condition;
        }

        return $out + match ($b->kind) {
            BlockKind::SINGLE => ['elements' => self::elements($b->elements)],
            BlockKind::REPEATER => \array_filter([
                'dataSource' => $b->dataSource,
                'repeatHeaderOnPageBreak' => $b->repeatHeaderOnPageBreak,
                'header' => $b->header === null ? null : self::elements($b->header),
                'headerPage2' => $b->headerPage2 === null ? null : self::elements($b->headerPage2),
                'item' => $b->item === null ? null : self::elements($b->item),
                'footer' => $b->footer === null ? null : self::elements($b->footer),
            ], static fn (mixed $v): bool => $v !== null),
            BlockKind::COLUMNS => [
                'equalHeight' => $b->equalHeight,
                'columns' => \array_map(self::column(...), $b->columns),
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function column(BlockColumn $c): array
    {
        $out = [
            'id' => $c->id,
            'width' => $c->width,
            'autoExpand' => $c->autoExpand,
            'elements' => self::elements($c->elements),
        ];
        if ($c->condition !== null) {
            $out['condition'] = $c->condition;
        }

        return $out;
    }

    /**
     * A block's freely-placed contents — the same shape as the page-level one.
     *
     * @return array<string, mixed>
     */
    private static function elements(ElementSet $set): array
    {
        $out = ['h' => $set->height];
        if ($set->staticVectorOps !== '') {
            $out['static'] = $set->staticVectorOps;
        }
        if ($set->texts !== []) {
            $out['texts'] = \array_map(
                static fn (TemplateText $t): array => ['text' => $t->text] + self::box($t),
                $set->texts,
            );
        }
        if ($set->bindings !== []) {
            // A list, not a map: a repeater row draws the same key every time,
            // so keying by field name would collapse the row to one element.
            $out['bindings'] = \array_map(
                static fn (DynamicBinding $b): array => [
                    'key' => $b->key,
                    'fallback' => $b->fallback,
                    'maxLength' => $b->maxLength,
                    'maxLines' => $b->maxLines,
                ] + self::box($b),
                $set->bindings,
            );
        }
        if ($set->images !== []) {
            $out['images'] = \array_map(self::image(...), $set->images);
        }
        if ($set->links !== []) {
            $out['links'] = \array_map(self::link(...), $set->links);
        }

        return $out;
    }
}
