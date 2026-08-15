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
use MejiroPDF\Block\PositionMode;
use MejiroPDF\Exception\TemplateException;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\VerticalAlignment;
use MejiroPDF\Text\JapaneseSpacing;

/**
 * Loads and validates a compiled `.mpb` (MPBF) template.
 *
 * Loading is Fail-Fast: a bad magic, an unsupported version, or a truncated
 * segment raises immediately (MP-VAL-1001 / MP-VAL-1002) rather than producing
 * a broken document.
 */
final class CompiledTemplateLoader
{
    public static function loadFile(string $path): CompiledTemplate
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw TemplateException::notFound($path);
        }

        $bytes = @\file_get_contents($path);
        if ($bytes === false) {
            throw TemplateException::notFound($path);
        }

        return self::loadString($bytes);
    }

    public static function loadString(string $bytes): CompiledTemplate
    {
        if (\strlen($bytes) < Mpb::HEADER_SIZE) {
            throw TemplateException::invalidHeader('Template is shorter than the 16-byte MPBF header.');
        }

        /** @var array{magic: string, version: int, flags: int, staticLen: int, bindLen: int}|false $header */
        $header = \unpack(Mpb::HEADER_FORMAT, \substr($bytes, 0, Mpb::HEADER_SIZE));
        if ($header === false) {
            throw TemplateException::invalidHeader('Could not unpack the MPBF header.');
        }

        if ($header['magic'] !== Mpb::MAGIC) {
            throw TemplateException::invalidHeader(
                \sprintf('Invalid magic bytes; expected "%s", got "%s".', Mpb::MAGIC, $header['magic']),
            );
        }

        if ($header['version'] !== Mpb::VERSION) {
            throw TemplateException::invalidHeader(
                \sprintf('Unsupported MPBF version %d (this engine supports %d).', $header['version'], Mpb::VERSION),
            );
        }

        $expected = Mpb::HEADER_SIZE + $header['staticLen'] + $header['bindLen'];
        if (\strlen($bytes) < $expected) {
            throw TemplateException::corrupted(\sprintf(
                'Truncated template: expected at least %d bytes, got %d.',
                $expected,
                \strlen($bytes),
            ));
        }

        $static = \substr($bytes, Mpb::HEADER_SIZE, $header['staticLen']);
        $bindingsRaw = \substr($bytes, Mpb::HEADER_SIZE + $header['staticLen'], $header['bindLen']);

        $payload = self::parsePayload($bindingsRaw);
        $fonts = Mpb::hasFlag($header['flags'], Mpb::FLAG_FONTS)
            ? self::parseFonts(\substr($bytes, $expected))
            : [];

        return new CompiledTemplate(
            version: $header['version'],
            flags: $header['flags'],
            width: $payload['width'],
            height: $payload['height'],
            staticVectorOps: $static,
            texts: $payload['texts'],
            bindings: $payload['bindings'],
            images: $payload['images'],
            links: $payload['links'],
            blocks: $payload['blocks'],
            marginTop: $payload['margin']['top'],
            marginRight: $payload['margin']['right'],
            marginBottom: $payload['margin']['bottom'],
            marginLeft: $payload['margin']['left'],
            japaneseSpacing: $payload['japaneseSpacing'],
            document: $payload['document'],
            fonts: $fonts,
        );
    }

    /**
     * Decode the font segment that follows the bindings.
     *
     * Self-describing rather than sized by the header, which is fixed at 16
     * bytes; see {@see Mpb::FLAG_FONTS} for the layout. Every length is checked
     * against what is actually there, because a font segment that lies about
     * its sizes would otherwise be read as whatever bytes happen to follow.
     *
     * @return array<string, TrueTypeFont>
     */
    private static function parseFonts(string $segment): array
    {
        $total = \strlen($segment);
        if ($total < 2) {
            throw TemplateException::corrupted('Template declares embedded fonts but carries no font segment.');
        }

        $count = self::u16($segment, 0);
        $at = 2;
        $fonts = [];

        for ($i = 0; $i < $count; $i++) {
            self::needs($segment, $at, 2, 'a font name length');
            $nameLen = self::u16($segment, $at);
            $at += 2;

            self::needs($segment, $at, $nameLen, 'a font name');
            $name = \substr($segment, $at, $nameLen);
            $at += $nameLen;

            self::needs($segment, $at, 4, 'a font data length');
            /** @var array{1: int}|false $unpacked */
            $unpacked = \unpack('N', $segment, $at);
            $dataLen = $unpacked === false ? 0 : $unpacked[1];
            $at += 4;

            self::needs($segment, $at, $dataLen, \sprintf('the data for font "%s"', $name));
            // Parsed now rather than on first use: a font that cannot be read
            // is a broken template, and the point of loading is to find that
            // out before a page is written.
            $fonts[$name] = TrueTypeFont::fromString(\substr($segment, $at, $dataLen), $name);
            $at += $dataLen;
        }

        if ($at !== $total) {
            throw TemplateException::corrupted(\sprintf(
                'Font segment has %d trailing byte(s) after %d font(s).',
                $total - $at,
                $count,
            ));
        }

        return $fonts;
    }

    /** Fail before reading past the end of the segment. */
    private static function needs(string $segment, int $at, int $length, string $what): void
    {
        if ($length < 0 || $at + $length > \strlen($segment)) {
            throw TemplateException::corrupted("Truncated font segment: not enough bytes for {$what}.");
        }
    }

    private static function u16(string $segment, int $at): int
    {
        /** @var array{1: int}|false $unpacked */
        $unpacked = \unpack('n', $segment, $at);

        return $unpacked === false ? 0 : $unpacked[1];
    }

    /**
     * Decode the JSON payload segment.
     *
     * Every field is optional and falls back to a safe default: the payload is
     * additive by design (see the `.mpb` v1 contract), so a template written by
     * an older Studio simply omits the box geometry and renders as the
     * point-anchored labels it was authored as.
     *
     * @return array{
     *     width: float,
     *     height: float,
     *     texts: list<TemplateText>,
     *     bindings: array<string, DynamicBinding>,
     *     images: list<TemplateImage>,
     *     links: list<TemplateLink>,
     *     blocks: list<Block>,
     *     margin: array{top: float, right: float, bottom: float, left: float},
     *     japaneseSpacing: JapaneseSpacing,
     *     document: array{title?: string, author?: string, subject?: string, keywords?: string},
     * }
     */
    private static function parsePayload(string $raw): array
    {
        $empty = [
            'width' => 0.0, 'height' => 0.0,
            'texts' => [], 'bindings' => [], 'images' => [], 'links' => [], 'blocks' => [],
            'margin' => ['top' => 0.0, 'right' => 0.0, 'bottom' => 0.0, 'left' => 0.0],
            'japaneseSpacing' => JapaneseSpacing::NONE,
            'document' => [],
        ];
        if ($raw === '') {
            return $empty;
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            throw TemplateException::corrupted('Bindings segment is not valid JSON.');
        }

        /** @var array<string, mixed> $meta */
        $meta = \is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $empty['width'] = (float) ($meta['width'] ?? 0.0);
        $empty['height'] = (float) ($meta['height'] ?? 0.0);

        foreach (self::rows($data, 'texts') as $t) {
            $empty['texts'][] = self::text($t);
        }

        foreach (\is_array($data['bindings'] ?? null) ? $data['bindings'] : [] as $key => $b) {
            if (!\is_array($b)) {
                continue;
            }
            $key = (string) $key;
            $empty['bindings'][$key] = self::binding($key, $b);
        }

        foreach (self::rows($data, 'images') as $i) {
            $empty['images'][] = self::image($i);
        }

        foreach (self::rows($data, 'links') as $l) {
            $empty['links'][] = self::link($l);
        }

        // Absent in every `.mpb` written before blocks existed, which is
        // exactly what "no blocks" means — such a template is pure free
        // placement and renders as it always did.
        foreach (self::rows($data, 'blocks') as $b) {
            $block = self::block($b);
            if ($block !== null) {
                $empty['blocks'][] = $block;
            }
        }

        // Absent in every `.mpb` written before the setting existed, which is
        // exactly what "solid setting" means — such a template measures as it
        // always did. An unknown value is treated the same way rather than
        // refused: a newer Studio must not make a template unreadable.
        // Absent in every `.mpb` written before document properties existed,
        // and absent again whenever the designer left the fields empty: there
        // is nothing to say about a document that says nothing about itself.
        $document = \is_array($data['document'] ?? null) ? $data['document'] : [];
        foreach (['title', 'author', 'subject', 'keywords'] as $key) {
            $value = $document[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $empty['document'][$key] = $value;
            }
        }

        $typography = \is_array($data['typography'] ?? null) ? $data['typography'] : [];
        $empty['japaneseSpacing'] = match ($typography['japaneseSpacing'] ?? null) {
            'jis_x_4051' => JapaneseSpacing::JIS_X_4051,
            default => JapaneseSpacing::NONE,
        };

        $margin = \is_array($data['margin'] ?? null) ? $data['margin'] : [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $empty['margin'][$side] = (float) ($margin[$side] ?? 0.0);
        }

        return $empty;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $rows = [];
        foreach (\is_array($data[$key] ?? null) ? $data[$key] : [] as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function align(mixed $value): Alignment
    {
        return \is_string($value) ? (Alignment::tryFrom($value) ?? Alignment::LEFT) : Alignment::LEFT;
    }

    private static function verticalAlign(mixed $value): VerticalAlignment
    {
        return \is_string($value)
            ? (VerticalAlignment::tryFrom($value) ?? VerticalAlignment::TOP)
            : VerticalAlignment::TOP;
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A declared ceiling, or 0 for "no limit".
     *
     * Missing, non-numeric and negative all decode to 0 on purpose: an older
     * compiler that never wrote the key must not start rejecting data, and a
     * corrupt value should not either.
     */
    private static function ceiling(mixed $value): int
    {
        $limit = \is_numeric($value) ? (int) $value : 0;

        return \max(0, $limit);
    }

    /** A non-positive or missing multiplier falls back to the engine default. */
    private static function lineHeight(mixed $value): float
    {
        $height = (float) (\is_numeric($value) ? $value : 0.0);

        return $height > 0.0 ? $height : 1.6;
    }

    /**
     * @param array<string, mixed> $i
     */
    private static function image(array $i): TemplateImage
    {
        $encoded = (string) ($i['data'] ?? '');
        // Base64 travels in the payload so the segment stays valid JSON;
        // a malformed blob decodes to nothing and simply draws no image.
        $bytes = $encoded === '' ? '' : (\base64_decode($encoded, true) ?: '');

        return new TemplateImage(
            data: $bytes,
            key: self::nullableString($i['key'] ?? null),
            x: (float) ($i['x'] ?? 0.0),
            y: (float) ($i['y'] ?? 0.0),
            width: (float) ($i['w'] ?? 0.0),
            height: (float) ($i['h'] ?? 0.0),
            rotation: (float) ($i['rotation'] ?? 0.0),
            fit: (string) ($i['fit'] ?? 'contain'),
        );
    }

    /**
     * @param array<string, mixed> $l
     */
    private static function link(array $l): TemplateLink
    {
        return new TemplateLink(
            x: (float) ($l['x'] ?? 0.0),
            y: (float) ($l['y'] ?? 0.0),
            width: (float) ($l['w'] ?? 0.0),
            height: (float) ($l['h'] ?? 0.0),
            url: (string) ($l['url'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $t
     */
    private static function text(array $t): TemplateText
    {
        return new TemplateText(
            text: (string) ($t['text'] ?? ''),
            x: (float) ($t['x'] ?? 0.0),
            y: (float) ($t['y'] ?? 0.0),
            font: (string) ($t['font'] ?? 'Helvetica'),
            fontSize: (float) ($t['size'] ?? 9.0),
            align: self::align($t['align'] ?? null),
            color: self::nullableString($t['color'] ?? null),
            width: (float) ($t['w'] ?? 0.0),
            height: (float) ($t['h'] ?? 0.0),
            rotation: (float) ($t['rotation'] ?? 0.0),
            verticalAlign: self::verticalAlign($t['valign'] ?? null),
            lineHeight: self::lineHeight($t['lineHeight'] ?? null),
            underline: ($t['underline'] ?? false) === true,
            multiline: ($t['multiline'] ?? false) === true,
        );
    }

    /**
     * @param array<string, mixed> $b
     */
    private static function binding(string $key, array $b): DynamicBinding
    {
        return new DynamicBinding(
            key: $key,
            x: (float) ($b['x'] ?? 0.0),
            y: (float) ($b['y'] ?? 0.0),
            font: (string) ($b['font'] ?? 'Helvetica'),
            fontSize: (float) ($b['size'] ?? 9.0),
            align: self::align($b['align'] ?? null),
            color: self::nullableString($b['color'] ?? null),
            width: (float) ($b['w'] ?? 0.0),
            height: (float) ($b['h'] ?? 0.0),
            rotation: (float) ($b['rotation'] ?? 0.0),
            verticalAlign: self::verticalAlign($b['valign'] ?? null),
            lineHeight: self::lineHeight($b['lineHeight'] ?? null),
            underline: ($b['underline'] ?? false) === true,
            multiline: ($b['multiline'] ?? false) === true,
            fallback: (string) ($b['fallback'] ?? ''),
            maxLength: self::ceiling($b['maxLength'] ?? null),
            maxLines: self::ceiling($b['maxLines'] ?? null),
        );
    }

    /**
     * One block. An unknown `type` yields null and the block is dropped rather
     * than guessed at: drawing a repeater's rows as a single panel would be a
     * worse outcome than leaving a gap the designer can see.
     *
     * @param array<string, mixed> $b
     */
    private static function block(array $b): ?Block
    {
        $kind = \is_string($b['type'] ?? null) ? BlockKind::tryFrom($b['type']) : null;
        if ($kind === null) {
            return null;
        }

        $id = (string) ($b['id'] ?? '');
        $mode = \is_string($b['positionMode'] ?? null)
            ? (PositionMode::tryFrom($b['positionMode']) ?? PositionMode::FLOW)
            : PositionMode::FLOW;
        $condition = self::nullableString($b['condition'] ?? null);
        $x = (float) ($b['x'] ?? 0.0);
        $y = (float) ($b['y'] ?? 0.0);
        $width = (float) ($b['w'] ?? 0.0);
        $marginBottom = (float) ($b['marginBottom'] ?? 0.0);
        $visible = ($b['visible'] ?? true) !== false;
        $name = (string) ($b['name'] ?? '');

        return match ($kind) {
            BlockKind::SINGLE => Block::single(
                id: $id,
                elements: self::elements($b['elements'] ?? null),
                positionMode: $mode,
                condition: $condition,
                x: $x,
                y: $y,
                width: $width,
                marginBottom: $marginBottom,
                visible: $visible,
                name: $name,
            ),
            BlockKind::REPEATER => Block::repeater(
                id: $id,
                dataSource: (string) ($b['dataSource'] ?? ''),
                item: self::elements($b['item'] ?? null),
                header: isset($b['header']) ? self::elements($b['header']) : null,
                footer: isset($b['footer']) ? self::elements($b['footer']) : null,
                headerPage2: isset($b['headerPage2']) ? self::elements($b['headerPage2']) : null,
                repeatHeaderOnPageBreak: ($b['repeatHeaderOnPageBreak'] ?? true) !== false,
                positionMode: $mode,
                condition: $condition,
                x: $x,
                y: $y,
                width: $width,
                marginBottom: $marginBottom,
                visible: $visible,
                name: $name,
            ),
            BlockKind::COLUMNS => Block::columns(
                id: $id,
                columns: self::columns($b['columns'] ?? null),
                equalHeight: ($b['equalHeight'] ?? true) !== false,
                positionMode: $mode,
                condition: $condition,
                x: $x,
                y: $y,
                width: $width,
                marginBottom: $marginBottom,
                visible: $visible,
                name: $name,
            ),
        };
    }

    /**
     * @return list<BlockColumn>
     */
    private static function columns(mixed $raw): array
    {
        $columns = [];
        foreach (\is_array($raw) ? $raw : [] as $c) {
            if (!\is_array($c)) {
                continue;
            }
            $columns[] = new BlockColumn(
                elements: self::elements($c['elements'] ?? null),
                width: (string) ($c['width'] ?? '100%'),
                autoExpand: ($c['autoExpand'] ?? true) !== false,
                condition: self::nullableString($c['condition'] ?? null),
                id: (string) ($c['id'] ?? ''),
            );
        }

        return $columns;
    }

    /**
     * A block's freely-placed contents. Anything missing decodes to empty, so a
     * partially written block still renders whatever it does carry.
     */
    private static function elements(mixed $raw): ElementSet
    {
        if (!\is_array($raw)) {
            return new ElementSet();
        }

        $texts = [];
        foreach (\is_array($raw['texts'] ?? null) ? $raw['texts'] : [] as $t) {
            if (\is_array($t)) {
                $texts[] = self::text($t);
            }
        }

        $bindings = [];
        foreach (\is_array($raw['bindings'] ?? null) ? $raw['bindings'] : [] as $key => $b) {
            if (!\is_array($b)) {
                continue;
            }
            // A list carries its key inside each entry; a map (as the page-level
            // segment writes them) carries it as the array key.
            $bindings[] = self::binding((string) ($b['key'] ?? $key), $b);
        }

        $images = [];
        foreach (\is_array($raw['images'] ?? null) ? $raw['images'] : [] as $i) {
            if (\is_array($i)) {
                $images[] = self::image($i);
            }
        }

        $links = [];
        foreach (\is_array($raw['links'] ?? null) ? $raw['links'] : [] as $l) {
            if (\is_array($l)) {
                $links[] = self::link($l);
            }
        }

        return new ElementSet(
            texts: $texts,
            bindings: $bindings,
            images: $images,
            links: $links,
            height: (float) ($raw['h'] ?? 0.0),
            staticVectorOps: (string) ($raw['static'] ?? ''),
        );
    }
}
