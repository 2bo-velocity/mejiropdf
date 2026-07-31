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

use MejiroPDF\Exception\FontException;

/**
 * A parsed TrueType (.ttf) font, embedded as a CIDFontType2 with Identity-H.
 *
 * Only the tables needed for embedding are read: head, hhea, maxp, hmtx, cmap
 * (format 4, Unicode BMP), and name. Text is encoded as 2-byte glyph ids; a
 * ToUnicode CMap keeps the output searchable/copyable.
 *
 * The whole font file is embedded (no glyph subsetting yet), which is correct
 * and portable; dropping unused glyphs is a future size optimisation.
 */
final class TrueTypeFont implements FontFace, FontMetricsInterface
{
    private function __construct(
        public readonly string $rawBytes,
        public readonly string $baseName,
        public readonly int $unitsPerEm,
        public readonly int $numGlyphs,
        public readonly int $ascent1000,
        public readonly int $descent1000,
        public readonly int $capHeight1000,
        /** @var array{0:int,1:int,2:int,3:int} scaled FontBBox [xMin yMin xMax yMax] */
        public readonly array $bbox1000,
        private readonly int $numHMetrics,
        private readonly int $hmtxOffset,
        private readonly int $segCount,
        private readonly int $endOffset,
        private readonly int $startOffset,
        private readonly int $deltaOffset,
        private readonly int $rangeOffset,
    ) {
    }

    public static function fromFile(string $path, ?string $name = null): self
    {
        $raw = @\file_get_contents($path);
        if ($raw === false) {
            throw FontException::loadFailed($path);
        }

        return self::fromString($raw, $name);
    }

    public static function fromString(string $raw, ?string $name = null): self
    {
        if (\strlen($raw) < 12) {
            throw FontException::loadFailed('TrueType data is too short.');
        }

        $numTables = self::u16($raw, 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            $tables[\substr($raw, $rec, 4)] = self::u32($raw, $rec + 8);
        }

        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap'] as $required) {
            if (!isset($tables[$required])) {
                throw FontException::loadFailed("TrueType font is missing the '{$required}' table.");
            }
        }

        $head = $tables['head'];
        $unitsPerEm = self::u16($raw, $head + 18) ?: 1000;
        $scale = 1000.0 / $unitsPerEm;
        $bbox = [
            (int) \round(self::s16($raw, $head + 36) * $scale),
            (int) \round(self::s16($raw, $head + 38) * $scale),
            (int) \round(self::s16($raw, $head + 40) * $scale),
            (int) \round(self::s16($raw, $head + 42) * $scale),
        ];

        $hhea = $tables['hhea'];
        $ascent = (int) \round(self::s16($raw, $hhea + 4) * $scale);
        $descent = (int) \round(self::s16($raw, $hhea + 6) * $scale);
        $numHMetrics = self::u16($raw, $hhea + 34);

        $numGlyphs = self::u16($raw, $tables['maxp'] + 4);

        [$segCount, $endO, $startO, $deltaO, $rangeO] = self::parseCmap($raw, $tables['cmap']);

        $baseName = self::sanitizeName($name ?? self::readPostScriptName($raw, $tables['name'] ?? null) ?? 'MejiroEmbedded');

        return new self(
            rawBytes: $raw,
            baseName: $baseName,
            unitsPerEm: $unitsPerEm,
            numGlyphs: $numGlyphs,
            ascent1000: $ascent,
            descent1000: $descent,
            capHeight1000: (int) \round($ascent * 0.9),
            bbox1000: $bbox,
            numHMetrics: $numHMetrics,
            hmtxOffset: $tables['hmtx'],
            segCount: $segCount,
            endOffset: $endO,
            startOffset: $startO,
            deltaOffset: $deltaO,
            rangeOffset: $rangeO,
        );
    }

    public function baseFontName(): string
    {
        return $this->baseName;
    }

    public function kind(): FontKind
    {
        return FontKind::TRUETYPE;
    }

    /**
     * Glyph id for a Unicode code point (0 = .notdef when unmapped).
     */
    public function gidForCodepoint(int $cp): int
    {
        for ($i = 0; $i < $this->segCount; $i++) {
            $end = self::u16($this->rawBytes, $this->endOffset + $i * 2);
            if ($cp > $end) {
                continue;
            }

            $start = self::u16($this->rawBytes, $this->startOffset + $i * 2);
            if ($cp < $start) {
                return 0;
            }

            $delta = self::s16($this->rawBytes, $this->deltaOffset + $i * 2);
            $rangeOffset = self::u16($this->rawBytes, $this->rangeOffset + $i * 2);
            if ($rangeOffset === 0) {
                return ($cp + $delta) & 0xFFFF;
            }

            $glyphAddr = $this->rangeOffset + $i * 2 + $rangeOffset + ($cp - $start) * 2;
            $glyph = self::u16($this->rawBytes, $glyphAddr);

            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
        }

        return 0;
    }

    /**
     * Advance width of a glyph, scaled to the 1000-unit em.
     */
    public function advanceWidth1000(int $gid): int
    {
        $g = $gid < $this->numHMetrics ? $gid : $this->numHMetrics - 1;
        $g = \max(0, $g);
        $advance = self::u16($this->rawBytes, $this->hmtxOffset + $g * 4);

        return (int) \round($advance * 1000.0 / $this->unitsPerEm);
    }

    /**
     * Encode UTF-8 text as a PDF hex string of 2-byte glyph ids (Identity-H).
     */
    public function encodeToGidHex(string $text): string
    {
        $hex = '';
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            // mb_str_split yields non-empty characters, so mb_ord returns an int.
            $hex .= \sprintf('%04X', $this->gidForCodepoint(\mb_ord($char, 'UTF-8')));
        }

        return '<' . $hex . '>';
    }

    public function charWidth(string $char): int
    {
        if ($char === '') {
            return 0;
        }

        return $this->advanceWidth1000($this->gidForCodepoint(\mb_ord($char, 'UTF-8')));
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

    // ------------------------------------------------------------------ parsing

    /**
     * @return array{0:int,1:int,2:int,3:int,4:int} [segCount, endO, startO, deltaO, rangeO]
     */
    private static function parseCmap(string $raw, int $cmap): array
    {
        $subtables = self::u16($raw, $cmap + 2);
        $best = null;
        for ($i = 0; $i < $subtables; $i++) {
            $rec = $cmap + 4 + $i * 8;
            $platform = self::u16($raw, $rec);
            $encoding = self::u16($raw, $rec + 2);
            $offset = $cmap + self::u32($raw, $rec + 4);
            if (self::u16($raw, $offset) !== 4) {
                continue;
            }
            if (($platform === 3 && $encoding === 1) || $platform === 0) {
                $best = $offset;
                break;
            }
            $best ??= $offset;
        }

        if ($best === null) {
            throw FontException::loadFailed('TrueType font has no usable (format 4) Unicode cmap.');
        }

        $segX2 = self::u16($raw, $best + 6);
        $segCount = \intdiv($segX2, 2);
        $endO = $best + 14;
        $startO = $endO + $segX2 + 2;   // + reservedPad
        $deltaO = $startO + $segX2;
        $rangeO = $deltaO + $segX2;

        return [$segCount, $endO, $startO, $deltaO, $rangeO];
    }

    private static function readPostScriptName(string $raw, ?int $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $count = self::u16($raw, $name + 2);
        $stringOffset = $name + self::u16($raw, $name + 4);

        $ascii = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $name + 6 + $i * 12;
            $platform = self::u16($raw, $rec);
            $nameId = self::u16($raw, $rec + 6);
            if ($nameId !== 6) {
                continue;
            }
            $length = self::u16($raw, $rec + 8);
            $value = \substr($raw, $stringOffset + self::u16($raw, $rec + 10), $length);

            if ($platform === 3) {
                $decoded = \mb_convert_encoding($value, 'UTF-8', 'UTF-16BE');
                if ($decoded !== '') {
                    return $decoded;
                }
            } elseif ($ascii === null && $value !== '') {
                $ascii = $value;
            }
        }

        return $ascii;
    }

    private static function sanitizeName(string $name): string
    {
        $clean = \preg_replace('/[^A-Za-z0-9-]/', '', $name) ?? '';

        return $clean === '' ? 'MejiroEmbedded' : $clean;
    }

    private static function u16(string $b, int $o): int
    {
        return (\ord($b[$o]) << 8) | \ord($b[$o + 1]);
    }

    private static function s16(string $b, int $o): int
    {
        $v = self::u16($b, $o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $b, int $o): int
    {
        return (\ord($b[$o]) << 24) | (\ord($b[$o + 1]) << 16) | (\ord($b[$o + 2]) << 8) | \ord($b[$o + 3]);
    }
}
