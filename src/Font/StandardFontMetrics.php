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
 * AFM (Adobe Font Metrics) widths for the Standard 14 Fonts.
 *
 * Only the printable ASCII range (32–126) is tabulated — roughly 1 KB per
 * family — which is all that is needed for wrapping/alignment of WinAnsi text.
 * No font file is parsed and no glyph data is loaded: metrics are pure data.
 */
final class StandardFontMetrics implements FontMetricsInterface
{
    private const int DEFAULT_WIDTH = 500;
    private const int COURIER_WIDTH = 600;

    /** @var array<int, int> ASCII code => advance width (Helvetica family). */
    private const array HELVETICA_WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
        112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 334, 124 => 260, 125 => 334, 126 => 584,
    ];

    /** @var array<int, int> ASCII code => advance width (Times family). */
    private const array TIMES_WIDTHS = [
        32 => 250, 33 => 333, 34 => 408, 35 => 500, 36 => 500, 37 => 833, 38 => 778, 39 => 180,
        40 => 333, 41 => 333, 42 => 500, 43 => 564, 44 => 250, 45 => 333, 46 => 250, 47 => 278,
        48 => 500, 49 => 500, 50 => 500, 51 => 500, 52 => 500, 53 => 500, 54 => 500, 55 => 500,
        56 => 500, 57 => 500, 58 => 278, 59 => 278, 60 => 564, 61 => 564, 62 => 564, 63 => 444,
        64 => 921, 65 => 722, 66 => 667, 67 => 667, 68 => 722, 69 => 611, 70 => 556, 71 => 722,
        72 => 722, 73 => 333, 74 => 389, 75 => 722, 76 => 611, 77 => 889, 78 => 722, 79 => 722,
        80 => 667, 81 => 722, 82 => 667, 83 => 556, 84 => 611, 85 => 722, 86 => 556, 87 => 833,
        88 => 611, 89 => 556, 90 => 556, 91 => 333, 92 => 278, 93 => 333, 94 => 469, 95 => 500,
        96 => 333, 97 => 444, 98 => 500, 99 => 444, 100 => 500, 101 => 444, 102 => 333, 103 => 500,
        104 => 500, 105 => 278, 106 => 278, 107 => 500, 108 => 278, 109 => 778, 110 => 500, 111 => 500,
        112 => 500, 113 => 500, 114 => 333, 115 => 389, 116 => 278, 117 => 500, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 444, 123 => 480, 124 => 200, 125 => 480, 126 => 541,
    ];

    /** @var array<int, int> The active width table (empty for monospaced Courier). */
    private readonly array $widths;

    public function __construct(
        public readonly StandardFont $font,
    ) {
        $this->widths = match ($font->family()) {
            StandardFontFamily::TIMES => self::TIMES_WIDTHS,
            StandardFontFamily::COURIER => [],
            default => self::HELVETICA_WIDTHS,
        };
    }

    public function charWidth(string $char): int
    {
        if ($this->font->isMonospaced()) {
            return self::COURIER_WIDTH;
        }

        $code = \ord($char);

        return $this->widths[$code] ?? self::DEFAULT_WIDTH;
    }

    public function textWidth(string $text, float $fontSize): float
    {
        if ($text === '') {
            return 0.0;
        }

        $units = 0;
        $length = \strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $units += $this->charWidth($text[$i]);
        }

        return ($units / 1000.0) * $fontSize;
    }
}
