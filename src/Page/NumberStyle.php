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

namespace MejiroPDF\Page;

/**
 * How a page number is spelled.
 *
 * A book rarely uses one sequence throughout: the front matter — preface,
 * contents — is numbered i, ii, iii and the body starts again at 1, so that
 * material can be added to either without renumbering the other. Japanese
 * books follow the same practice, and older ones use kanji numerals.
 *
 * Where a sequence begins is {@see \MejiroPDF\Document::restartPageNumbering()};
 * this only says how to write the number once it is known.
 */
enum NumberStyle
{
    case ARABIC;

    case ROMAN_LOWER;

    case ROMAN_UPPER;

    /** 一, 二, …, 十一, …, 百二十三. */
    case KANJI;

    /** No number at all — for plates and blank leaves inside a numbered run. */
    case NONE;

    /** @var list<array{0: int, 1: string}> */
    private const array ROMAN = [
        [1000, 'm'], [900, 'cm'], [500, 'd'], [400, 'cd'],
        [100, 'c'], [90, 'xc'], [50, 'l'], [40, 'xl'],
        [10, 'x'], [9, 'ix'], [5, 'v'], [4, 'iv'], [1, 'i'],
    ];

    private const array KANJI_DIGITS = ['〇', '一', '二', '三', '四', '五', '六', '七', '八', '九'];

    /** @var list<array{0: int, 1: string}> */
    private const array KANJI_UNITS = [[1000, '千'], [100, '百'], [10, '十']];

    /**
     * The number as it should be printed.
     *
     * Roman and kanji have nothing to say about zero or negatives, and a page
     * number that lands there means the caller's arithmetic went wrong rather
     * than that the document is unprintable — so those fall back to digits
     * instead of throwing at finalize, where a document is already half
     * written.
     */
    public function format(int $number): string
    {
        if ($this === self::NONE) {
            return '';
        }
        if ($number < 1) {
            return (string) $number;
        }

        return match ($this) {
            self::ROMAN_LOWER => self::roman($number),
            self::ROMAN_UPPER => \strtoupper(self::roman($number)),
            self::KANJI => self::kanji($number),
            default => (string) $number,
        };
    }

    /** Roman numerals run out at 3999; beyond that, digits say more. */
    private static function roman(int $number): string
    {
        if ($number > 3999) {
            return (string) $number;
        }

        $out = '';
        foreach (self::ROMAN as [$value, $numeral]) {
            while ($number >= $value) {
                $out .= $numeral;
                $number -= $value;
            }
        }

        return $out;
    }

    /**
     * Kanji numerals in the everyday positional style: 十一 rather than 一十一,
     * and 百 rather than 一百. Above 9999 the reading gets long enough that
     * digits serve a page number better.
     */
    private static function kanji(int $number): string
    {
        if ($number > 9999) {
            return (string) $number;
        }
        if ($number < 10) {
            return self::KANJI_DIGITS[$number];
        }

        $out = '';
        foreach (self::KANJI_UNITS as [$value, $unit]) {
            $count = \intdiv($number, $value);
            if ($count === 0) {
                continue;
            }
            // The leading one is left unsaid: 十, 百, 千 — not 一十.
            $out .= ($count === 1 ? '' : self::KANJI_DIGITS[$count]) . $unit;
            $number -= $count * $value;
        }
        if ($number > 0) {
            $out .= self::KANJI_DIGITS[$number];
        }

        return $out;
    }
}
