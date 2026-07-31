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

namespace MejiroPDF\Font;

/**
 * Tracks the fonts used across a document (Standard 14, Japanese CIDFonts, or
 * embedded TrueType) and hands out stable PDF resource names (F1, F2, …).
 * Metrics are memoised, and embedded TrueType faces accumulate a per-document
 * used-character set for width/ToUnicode emission.
 *
 * @phpstan-type FontEntry array{
 *     font: FontFace,
 *     kind: FontKind,
 *     ref: string,
 *     metrics: FontMetricsInterface,
 *     tracker: CharacterTracker|null
 * }
 */
final class FontRegistry
{
    /** @var array<string, FontEntry> */
    private array $fonts = [];

    private int $counter = 0;

    public function resourceName(FontFace $font): string
    {
        return $this->ensure($font)['ref'];
    }

    public function metrics(FontFace $font): FontMetricsInterface
    {
        return $this->ensure($font)['metrics'];
    }

    /**
     * Record that $text is drawn in $font (used to subset TrueType widths).
     */
    public function track(FontFace $font, string $text): void
    {
        $this->ensure($font)['tracker']?->register($text);
    }

    /**
     * @phpstan-return FontEntry
     */
    private function ensure(FontFace $font): array
    {
        $key = $font->baseFontName();

        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = [
                'font' => $font,
                'kind' => $font->kind(),
                'ref' => 'F' . (++$this->counter),
                'metrics' => FontMetrics::for($font),
                'tracker' => $font->kind() === FontKind::TRUETYPE ? new CharacterTracker() : null,
            ];
        }

        return $this->fonts[$key];
    }

    /**
     * @phpstan-return list<FontEntry>
     */
    public function all(): array
    {
        return \array_values($this->fonts);
    }

    public function isEmpty(): bool
    {
        return $this->fonts === [];
    }
}
