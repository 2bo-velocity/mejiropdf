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
 * Subsets a TrueType font down to the glyphs a document actually uses.
 *
 * Strategy: **retain glyph ids**. Glyph numbering is preserved (so the text
 * encoding, CIDToGIDMap /Identity and the width array need no changes); the
 * `glyf` and `loca` tables are rebuilt keeping only the used outlines (with
 * their composite-glyph dependencies), and non-essential tables (`cmap`,
 * `post`, GPOS/GSUB, …) are dropped — a FontFile2 with Identity-H needs none of
 * them. The result typically shrinks a large CJK/Latin font by 10–20×.
 */
final class TrueTypeSubsetter
{
    /** Tables required to render outlines by glyph id (others are dropped). */
    private const array KEEP = ['glyf', 'loca', 'head', 'hhea', 'hmtx', 'maxp', 'cvt ', 'fpgm', 'prep'];

    /**
     * @param list<int> $usedGids glyph ids that must survive (glyph 0 is always kept)
     *
     * @throws FontException if the font has no `glyf` outlines (e.g. a CFF/OTF font)
     */
    public static function subset(string $raw, array $usedGids): string
    {
        $tables = self::tableDirectory($raw);
        foreach (['glyf', 'loca', 'head', 'hhea', 'maxp'] as $required) {
            if (!isset($tables[$required])) {
                throw FontException::loadFailed("Cannot subset: font has no '{$required}' table.");
            }
        }

        $head = $tables['head']['offset'];
        $longLoca = self::s16($raw, $head + 50) === 1;
        $numGlyphs = self::u16($raw, $tables['maxp']['offset'] + 4);

        $loca = self::readLoca($raw, $tables['loca']['offset'], $numGlyphs, $longLoca);
        $glyfBase = $tables['glyf']['offset'];

        $used = [0 => true];
        foreach ($usedGids as $gid) {
            if ($gid >= 0 && $gid < $numGlyphs) {
                $used[$gid] = true;
            }
        }
        self::addCompositeDependencies($raw, $glyfBase, $loca, $numGlyphs, $used);

        // Rebuild glyf (used outlines only) and loca (retain-GID).
        $newGlyf = '';
        $newLoca = [];
        for ($gid = 0; $gid < $numGlyphs; $gid++) {
            $newLoca[] = \strlen($newGlyf);
            if (isset($used[$gid])) {
                $data = self::glyphData($raw, $glyfBase, $loca, $gid);
                if (\strlen($data) % 2 === 1) {
                    $data .= "\x00"; // keep 2-byte alignment for short loca
                }
                $newGlyf .= $data;
            }
        }
        $newLoca[] = \strlen($newGlyf);

        $locaBin = '';
        foreach ($newLoca as $offset) {
            $locaBin .= $longLoca ? \pack('N', $offset) : \pack('n', $offset >> 1);
        }

        // Assemble the pruned table set.
        $out = [];
        foreach (self::KEEP as $tag) {
            if (isset($tables[$tag])) {
                $out[$tag] = \substr($raw, $tables[$tag]['offset'], $tables[$tag]['length']);
            }
        }
        $out['glyf'] = $newGlyf;
        $out['loca'] = $locaBin;

        return self::assemble($out);
    }

    /**
     * @return array<string, array{offset: int, length: int}>
     */
    private static function tableDirectory(string $raw): array
    {
        if (\strlen($raw) < 12) {
            throw FontException::loadFailed('TrueType data is too short to subset.');
        }

        $numTables = self::u16($raw, 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + $i * 16;
            $tables[\substr($raw, $record, 4)] = [
                'offset' => self::u32($raw, $record + 8),
                'length' => self::u32($raw, $record + 12),
            ];
        }

        return $tables;
    }

    /**
     * @return list<int>
     */
    private static function readLoca(string $raw, int $offset, int $numGlyphs, bool $long): array
    {
        $loca = [];
        for ($i = 0; $i <= $numGlyphs; $i++) {
            $loca[] = $long ? self::u32($raw, $offset + $i * 4) : self::u16($raw, $offset + $i * 2) * 2;
        }

        return $loca;
    }

    /**
     * @param list<int> $loca
     */
    private static function glyphData(string $raw, int $glyfBase, array $loca, int $gid): string
    {
        $start = $loca[$gid];
        $end = $loca[$gid + 1];

        return $end > $start ? \substr($raw, $glyfBase + $start, $end - $start) : '';
    }

    /**
     * Follow composite glyphs so their component glyphs are kept too.
     *
     * @param list<int>          $loca
     * @param array<int, true>   $used
     */
    private static function addCompositeDependencies(string $raw, int $glyfBase, array $loca, int $numGlyphs, array &$used): void
    {
        $stack = \array_keys($used);
        while ($stack !== []) {
            $gid = \array_pop($stack);
            $data = self::glyphData($raw, $glyfBase, $loca, $gid);
            if (\strlen($data) < 10 || self::s16($data, 0) >= 0) {
                continue; // empty or a simple (non-composite) glyph
            }

            $p = 10;
            while (true) {
                $flags = self::u16($data, $p);
                $component = self::u16($data, $p + 2);
                if (!isset($used[$component]) && $component < $numGlyphs) {
                    $used[$component] = true;
                    $stack[] = $component;
                }
                $p += 4;
                $p += ($flags & 0x0001) ? 4 : 2;      // ARG_1_AND_2_ARE_WORDS
                if ($flags & 0x0008) {                 // WE_HAVE_A_SCALE
                    $p += 2;
                } elseif ($flags & 0x0040) {           // WE_HAVE_AN_X_AND_Y_SCALE
                    $p += 4;
                } elseif ($flags & 0x0080) {           // WE_HAVE_A_TWO_BY_TWO
                    $p += 8;
                }
                if (!($flags & 0x0020)) {              // MORE_COMPONENTS
                    break;
                }
            }
        }
    }

    /**
     * Reassemble an sfnt from tables, fixing offsets, checksums and the head
     * checkSumAdjustment.
     *
     * @param array<string, string> $tables
     */
    private static function assemble(array $tables): string
    {
        // Zero head.checkSumAdjustment before any checksum is computed.
        if (isset($tables['head'])) {
            $tables['head'] = \substr_replace($tables['head'], "\x00\x00\x00\x00", 8, 4);
        }
        \ksort($tables);

        $count = \count($tables);
        $searchRange = 1;
        $entrySelector = 0;
        while ($searchRange * 2 <= $count) {
            $searchRange *= 2;
            $entrySelector++;
        }
        $searchRange *= 16;
        $rangeShift = $count * 16 - $searchRange;

        $header = \pack('Nnnnn', 0x00010000, $count, $searchRange, $entrySelector, $rangeShift);

        $offset = 12 + $count * 16;
        $directory = '';
        $body = '';
        $headBodyPos = 0;
        foreach ($tables as $tag => $data) {
            $length = \strlen($data);
            $padding = (4 - ($length % 4)) % 4;
            $directory .= $tag . \pack('NNN', self::checksum($data), $offset, $length);
            if ($tag === 'head') {
                $headBodyPos = \strlen($body);
            }
            $body .= $data . \str_repeat("\x00", $padding);
            $offset += $length + $padding;
        }

        $font = $header . $directory . $body;

        // head.checkSumAdjustment = 0xB1B0AFBA - checksum(entire font)
        $adjustment = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        $headAbsolute = 12 + $count * 16 + $headBodyPos;

        return \substr_replace($font, \pack('N', $adjustment), $headAbsolute + 8, 4);
    }

    private static function checksum(string $data): int
    {
        $padding = (4 - (\strlen($data) % 4)) % 4;
        if ($padding !== 0) {
            $data .= \str_repeat("\x00", $padding);
        }

        $sum = 0;
        $words = \intdiv(\strlen($data), 4);
        for ($i = 0; $i < $words; $i++) {
            $sum = ($sum + self::u32($data, $i * 4)) & 0xFFFFFFFF;
        }

        return $sum;
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
