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

namespace MejiroPDF\Text;

/**
 * Opening and closing punctuation, from Unicode's `Line_Break` property,
 * version 17.0.0 — the two classes {@see JapaneseSpacing} has to tell
 * apart to close the gap between adjacent marks.
 *
 * **Derived from the Unicode Character Database, not written by hand.** The
 * table below is machine-generated from `LineBreak.txt`: every code point whose
 * `Line_Break` value is `OP` becomes {@see OPEN} and every `CL` becomes
 * {@see CLOSE}, with adjacent code points of the same class merged into a
 * range. Editing an entry here would put this file out of step with the
 * standard it claims to follow; correct the derivation instead and rebuild the
 * whole table.
 *
 * 208 ranges, searched by bisection. Nothing here distinguishes 和字 from
 * 欧字: {@see VerticalOrientation} already does, the letters that stand upright
 * in a vertical column being exactly the ones that count as Japanese.
 */
final class PunctuationClass
{
    /** `（` `「` — ink in the right half of the em box. */
    public const int OPEN = 1;

    /** `）` `」` `、` `。` `！` `？` — ink in the left half. */
    public const int CLOSE = 2;

    /** @var list<array{0: int, 1: int, 2: int}> start, end, class — sorted, disjoint. */
    private const array RANGES = [
        [0x0021, 0x0021, 2],
        [0x0028, 0x0028, 1],
        [0x0029, 0x0029, 2],
        [0x003F, 0x003F, 2],
        [0x005B, 0x005B, 1],
        [0x005D, 0x005D, 2],
        [0x007B, 0x007B, 1],
        [0x007D, 0x007D, 2],
        [0x00A1, 0x00A1, 1],
        [0x00BF, 0x00BF, 1],
        [0x05C6, 0x05C6, 2],
        [0x061B, 0x061B, 2],
        [0x061D, 0x061F, 2],
        [0x06D4, 0x06D4, 2],
        [0x07F9, 0x07F9, 2],
        [0x0F0D, 0x0F11, 2],
        [0x0F14, 0x0F14, 2],
        [0x0F3A, 0x0F3A, 1],
        [0x0F3B, 0x0F3B, 2],
        [0x0F3C, 0x0F3C, 1],
        [0x0F3D, 0x0F3D, 2],
        [0x169B, 0x169B, 1],
        [0x169C, 0x169C, 2],
        [0x1802, 0x1803, 2],
        [0x1808, 0x1809, 2],
        [0x1944, 0x1945, 2],
        [0x201A, 0x201A, 1],
        [0x201E, 0x201E, 1],
        [0x2045, 0x2045, 1],
        [0x2046, 0x2046, 2],
        [0x207D, 0x207D, 1],
        [0x207E, 0x207E, 2],
        [0x208D, 0x208D, 1],
        [0x208E, 0x208E, 2],
        [0x2308, 0x2308, 1],
        [0x2309, 0x2309, 2],
        [0x230A, 0x230A, 1],
        [0x230B, 0x230B, 2],
        [0x2329, 0x2329, 1],
        [0x232A, 0x232A, 2],
        [0x2762, 0x2763, 2],
        [0x2768, 0x2768, 1],
        [0x2769, 0x2769, 2],
        [0x276A, 0x276A, 1],
        [0x276B, 0x276B, 2],
        [0x276C, 0x276C, 1],
        [0x276D, 0x276D, 2],
        [0x276E, 0x276E, 1],
        [0x276F, 0x276F, 2],
        [0x2770, 0x2770, 1],
        [0x2771, 0x2771, 2],
        [0x2772, 0x2772, 1],
        [0x2773, 0x2773, 2],
        [0x2774, 0x2774, 1],
        [0x2775, 0x2775, 2],
        [0x27C5, 0x27C5, 1],
        [0x27C6, 0x27C6, 2],
        [0x27E6, 0x27E6, 1],
        [0x27E7, 0x27E7, 2],
        [0x27E8, 0x27E8, 1],
        [0x27E9, 0x27E9, 2],
        [0x27EA, 0x27EA, 1],
        [0x27EB, 0x27EB, 2],
        [0x27EC, 0x27EC, 1],
        [0x27ED, 0x27ED, 2],
        [0x27EE, 0x27EE, 1],
        [0x27EF, 0x27EF, 2],
        [0x2983, 0x2983, 1],
        [0x2984, 0x2984, 2],
        [0x2985, 0x2985, 1],
        [0x2986, 0x2986, 2],
        [0x2987, 0x2987, 1],
        [0x2988, 0x2988, 2],
        [0x2989, 0x2989, 1],
        [0x298A, 0x298A, 2],
        [0x298B, 0x298B, 1],
        [0x298C, 0x298C, 2],
        [0x298D, 0x298D, 1],
        [0x298E, 0x298E, 2],
        [0x298F, 0x298F, 1],
        [0x2990, 0x2990, 2],
        [0x2991, 0x2991, 1],
        [0x2992, 0x2992, 2],
        [0x2993, 0x2993, 1],
        [0x2994, 0x2994, 2],
        [0x2995, 0x2995, 1],
        [0x2996, 0x2996, 2],
        [0x2997, 0x2997, 1],
        [0x2998, 0x2998, 2],
        [0x29D8, 0x29D8, 1],
        [0x29D9, 0x29D9, 2],
        [0x29DA, 0x29DA, 1],
        [0x29DB, 0x29DB, 2],
        [0x29FC, 0x29FC, 1],
        [0x29FD, 0x29FD, 2],
        [0x2CF9, 0x2CF9, 2],
        [0x2CFE, 0x2CFE, 2],
        [0x2E18, 0x2E18, 1],
        [0x2E22, 0x2E22, 1],
        [0x2E23, 0x2E23, 2],
        [0x2E24, 0x2E24, 1],
        [0x2E25, 0x2E25, 2],
        [0x2E26, 0x2E26, 1],
        [0x2E27, 0x2E27, 2],
        [0x2E28, 0x2E28, 1],
        [0x2E29, 0x2E29, 2],
        [0x2E2E, 0x2E2E, 2],
        [0x2E42, 0x2E42, 1],
        [0x2E53, 0x2E54, 2],
        [0x2E55, 0x2E55, 1],
        [0x2E56, 0x2E56, 2],
        [0x2E57, 0x2E57, 1],
        [0x2E58, 0x2E58, 2],
        [0x2E59, 0x2E59, 1],
        [0x2E5A, 0x2E5A, 2],
        [0x2E5B, 0x2E5B, 1],
        [0x2E5C, 0x2E5C, 2],
        [0x3001, 0x3002, 2],
        [0x3008, 0x3008, 1],
        [0x3009, 0x3009, 2],
        [0x300A, 0x300A, 1],
        [0x300B, 0x300B, 2],
        [0x300C, 0x300C, 1],
        [0x300D, 0x300D, 2],
        [0x300E, 0x300E, 1],
        [0x300F, 0x300F, 2],
        [0x3010, 0x3010, 1],
        [0x3011, 0x3011, 2],
        [0x3014, 0x3014, 1],
        [0x3015, 0x3015, 2],
        [0x3016, 0x3016, 1],
        [0x3017, 0x3017, 2],
        [0x3018, 0x3018, 1],
        [0x3019, 0x3019, 2],
        [0x301A, 0x301A, 1],
        [0x301B, 0x301B, 2],
        [0x301D, 0x301D, 1],
        [0x301E, 0x301F, 2],
        [0xA60E, 0xA60E, 2],
        [0xA876, 0xA877, 2],
        [0xFD3E, 0xFD3E, 2],
        [0xFD3F, 0xFD3F, 1],
        [0xFE10, 0xFE12, 2],
        [0xFE15, 0xFE16, 2],
        [0xFE17, 0xFE17, 1],
        [0xFE18, 0xFE18, 2],
        [0xFE35, 0xFE35, 1],
        [0xFE36, 0xFE36, 2],
        [0xFE37, 0xFE37, 1],
        [0xFE38, 0xFE38, 2],
        [0xFE39, 0xFE39, 1],
        [0xFE3A, 0xFE3A, 2],
        [0xFE3B, 0xFE3B, 1],
        [0xFE3C, 0xFE3C, 2],
        [0xFE3D, 0xFE3D, 1],
        [0xFE3E, 0xFE3E, 2],
        [0xFE3F, 0xFE3F, 1],
        [0xFE40, 0xFE40, 2],
        [0xFE41, 0xFE41, 1],
        [0xFE42, 0xFE42, 2],
        [0xFE43, 0xFE43, 1],
        [0xFE44, 0xFE44, 2],
        [0xFE47, 0xFE47, 1],
        [0xFE48, 0xFE48, 2],
        [0xFE50, 0xFE50, 2],
        [0xFE52, 0xFE52, 2],
        [0xFE56, 0xFE57, 2],
        [0xFE59, 0xFE59, 1],
        [0xFE5A, 0xFE5A, 2],
        [0xFE5B, 0xFE5B, 1],
        [0xFE5C, 0xFE5C, 2],
        [0xFE5D, 0xFE5D, 1],
        [0xFE5E, 0xFE5E, 2],
        [0xFF01, 0xFF01, 2],
        [0xFF08, 0xFF08, 1],
        [0xFF09, 0xFF09, 2],
        [0xFF0C, 0xFF0C, 2],
        [0xFF0E, 0xFF0E, 2],
        [0xFF1F, 0xFF1F, 2],
        [0xFF3B, 0xFF3B, 1],
        [0xFF3D, 0xFF3D, 2],
        [0xFF5B, 0xFF5B, 1],
        [0xFF5D, 0xFF5D, 2],
        [0xFF5F, 0xFF5F, 1],
        [0xFF60, 0xFF61, 2],
        [0xFF62, 0xFF62, 1],
        [0xFF63, 0xFF64, 2],
        [0x115C4, 0x115C5, 2],
        [0x11C71, 0x11C71, 2],
        [0x13258, 0x1325A, 1],
        [0x1325B, 0x1325D, 2],
        [0x13282, 0x13282, 2],
        [0x13286, 0x13286, 1],
        [0x13287, 0x13287, 2],
        [0x13288, 0x13288, 1],
        [0x13289, 0x13289, 2],
        [0x13379, 0x13379, 1],
        [0x1337A, 0x1337B, 2],
        [0x1342F, 0x1342F, 1],
        [0x13437, 0x13437, 1],
        [0x13438, 0x13438, 2],
        [0x1343C, 0x1343C, 1],
        [0x1343D, 0x1343D, 2],
        [0x1343E, 0x1343E, 1],
        [0x1343F, 0x1343F, 2],
        [0x145CE, 0x145CE, 1],
        [0x145CF, 0x145CF, 2],
        [0x1E95E, 0x1E95F, 1],
    ];

    /** The class for a code point, or 0 when it is not bracketing punctuation. */
    public static function of(int $codePoint): int
    {
        $low = 0;
        $high = \count(self::RANGES) - 1;

        while ($low <= $high) {
            $mid = \intdiv($low + $high, 2);
            [$start, $end, $class] = self::RANGES[$mid];
            if ($codePoint < $start) {
                $high = $mid - 1;
            } elseif ($codePoint > $end) {
                $low = $mid + 1;
            } else {
                return $class;
            }
        }

        return 0;
    }
}
