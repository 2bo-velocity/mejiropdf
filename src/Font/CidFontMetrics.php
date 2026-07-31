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
 * Approximate metrics for a Japanese CIDFont: single-byte ASCII is treated as
 * half-width (500), everything else as full-width (1000). This is accurate for
 * the overwhelming majority of Japanese report text and needs no font file.
 */
final readonly class CidFontMetrics implements FontMetricsInterface
{
    private const int HALF_WIDTH = 500;

    public function __construct(
        public CidFontJapanese $font,
    ) {
    }

    public function charWidth(string $char): int
    {
        // A single byte can only be ASCII (half-width); multibyte => full-width.
        return \strlen($char) === 1 ? self::HALF_WIDTH : $this->font->defaultWidth();
    }

    public function textWidth(string $text, float $fontSize): float
    {
        if ($text === '') {
            return 0.0;
        }

        $units = 0;
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            $units += $this->charWidth($char);
        }

        return ($units / 1000.0) * $fontSize;
    }
}
