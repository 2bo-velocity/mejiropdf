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

use MejiroPDF\Block\BlockRenderer;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Document;
use MejiroPDF\Exception\TemplateException;
use MejiroPDF\Font\FontMetrics;
use MejiroPDF\Font\FontFace;
use MejiroPDF\Font\FontResolver;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\LineBreaker;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TextToken;
use MejiroPDF\Text\TypesetterOptions;

/**
 * Renders a {@see CompiledTemplate} onto a {@see Document}: a fresh page
 * carries the static vector background verbatim, then the images, the fixed
 * labels, every variable binding, and finally the link annotations. No template
 * parsing happens here beyond looking up values — the heavy work was done at
 * compile time.
 *
 * ## Two layers, not two models
 * A template's freely-placed elements and its blocks are **nested, not
 * alternatives**. The page-level elements are painted first, at the coordinates
 * the designer gave them; blocks then stack down the page, and each block holds
 * a freely-placed element set of its own. A template with no blocks is simply
 * one with a single implicit block covering the page, which is why every
 * `.mpb` written before blocks existed still renders byte-identically.
 *
 * ## Draw order
 * The order above is the `.mpb` contract, not an accident: the static segment
 * is a single opaque byte run the engine streams out untouched, so anything the
 * engine has to lay out itself necessarily comes after it. MejiroPDF Studio
 * stacks its canvas the same way, so what the designer sees is what the page
 * gets.
 */
final class TemplateRenderer
{
    private readonly ElementPainter $painter;

    public function __construct(?ElementPainter $painter = null)
    {
        $this->painter = $painter ?? new ElementPainter();
    }

    /**
     * The document's properties with `{{ field }}` resolved against the record.
     *
     * A template's own name is not the output's title: an invoice is "3 月分
     * 請求書 — 山田商店", which is only knowable once the record is in hand.
     * That is the whole reason these hold placeholders rather than being taken
     * from whatever the designer called the file.
     *
     * @param array<string, mixed> $data
     */
    private static function documentInfo(CompiledTemplate $template, array $data): DocumentInfo
    {
        $resolve = static function (?string $text) use ($data): ?string {
            if ($text === null) {
                return null;
            }
            $filled = (string) \preg_replace_callback(
                '/\{\{\s*([^}\s]+)\s*\}\}/u',
                // A field the record does not carry becomes nothing, as it does
                // everywhere else in a template: a half-filled title beats
                // printing the placeholder, and beats refusing the batch.
                static function (array $m) use ($data): string {
                    $value = $data[$m[1]] ?? '';

                    return \is_scalar($value) ? (string) $value : '';
                },
                $text,
            );
            $filled = \trim($filled);

            return $filled === '' ? null : $filled;
        };

        return new DocumentInfo(
            title: $resolve($template->document['title'] ?? null),
            author: $resolve($template->document['author'] ?? null),
            subject: $resolve($template->document['subject'] ?? null),
            keywords: $resolve($template->document['keywords'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $data Values for {@see DynamicBinding} keys.
     */
    public function render(Document $document, CompiledTemplate $template, array $data = []): void
    {
        // Before anything else, so the checks below and the painting after
        // measure with the same faces the file carries.
        $document->adoptTemplateFonts($template->fonts);

        // Every field is checked before the page is even opened, so a record
        // that violates a declared ceiling costs nothing and leaves no
        // half-drawn page behind (MP-VAL-MAX_LENGTH).
        foreach ($template->bindings as $binding) {
            self::assertFits($binding, ElementPainter::valueFor($binding, $data), $document->namedFonts());
        }

        // Before the first page, so a document that is only a template render
        // still carries its properties even if nothing else is drawn.
        if ($template->document !== []) {
            $document->setDocumentInfo(self::documentInfo($template, $data));
        }

        $document->addPage($template->pageSize());

        // The template chose how Japanese and Latin sit together; the painter
        // that draws its labels and its blocks has to agree.
        $painter = $this->painter->forTemplate($template);

        $painter->paint(
            $document,
            new ElementSet(
                texts: $template->texts,
                bindings: \array_values($template->bindings),
                images: $template->images,
                links: $template->links,
                staticVectorOps: $template->staticVectorOps,
            ),
            $data,
        );

        if ($template->blocks !== []) {
            (new BlockRenderer($painter))->render(
                $document,
                $template,
                $data,
                $document->blockOverrides(),
            );
        }
    }

    /**
     * Reject a value longer than its slot declares (MP-VAL-MAX_LENGTH).
     *
     * Shared with {@see Document::fill()} so both fill paths refuse the same
     * data — a template that guards a 20-character invoice number must guard it
     * whether the caller went through the compiled renderer or the field list.
     */
    /**
     * @param array<string, FontFace> $fonts Faces resolvable by name (see {@see FontResolver}).
     */
    public static function assertFits(DynamicBinding $binding, string $value, array $fonts = []): void
    {
        if (!$binding->accepts($value)) {
            throw TemplateException::maxLengthExceeded(
                $binding->key,
                $binding->maxLength,
                \mb_strlen($value, 'UTF-8'),
            );
        }

        // Only a wrapping box can overflow a line count, and only then is it
        // worth breaking the text to find out.
        if ($binding->maxLines > 0 && $binding->wraps() && $value !== '') {
            $lines = self::countLines($binding, $value, $fonts);
            if (!$binding->acceptsLines($lines)) {
                throw TemplateException::maxLinesExceeded($binding->key, $binding->maxLines, $lines);
            }
        }
    }

    /**
     * How many lines $value breaks into inside this slot's box.
     *
     * Runs the real line breaker — the same one that will lay the text out — but
     * **without a Document**, so no page is opened to answer the question. That
     * is what lets the check stay alongside the length check, before anything is
     * drawn: a record that violates its template leaves no half-filled page.
     */
    /**
     * @param array<string, FontFace> $fonts
     */
    private static function countLines(DynamicBinding $binding, string $value, array $fonts = []): int
    {
        $face = FontResolver::resolve($binding->font, $fonts);
        // Studio authors verbatim content, so inline markup stays off here for
        // the same reason it is off when the text is drawn.
        $options = new TypesetterOptions(
            align: self::textAlign($binding->align),
            enableRuby: false,
            enableBouten: false,
            enableDropCaps: false,
            enableHyphenation: false,
            enableSmartQuotes: false,
            lineHeight: $binding->lineHeight,
        );

        $lines = (new LineBreaker(FontMetrics::for($face), $binding->fontSize, $options))
            ->break([new TextToken($value)], PageSize::mm($binding->width));

        return \count($lines);
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
