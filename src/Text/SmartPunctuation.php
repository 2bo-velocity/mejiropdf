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
 * Typographic substitution for a single text run (SmartyPants-style): straight
 * quotes become curly ones by context, an apostrophe after a letter/digit
 * becomes a right single quote, and `--`/`---` become an em dash.
 *
 * Applied per {@see TextToken} after tokenizing, so it never rewrites URLs or
 * attribute values, and gated by {@see TypesetterOptions::$enableSmartQuotes}.
 */
final class SmartPunctuation
{
    private const string LEFT_DOUBLE = "\u{201C}";  // "
    private const string RIGHT_DOUBLE = "\u{201D}"; // "
    private const string LEFT_SINGLE = "\u{2018}";  // '
    private const string RIGHT_SINGLE = "\u{2019}"; // '
    private const string EM_DASH = "\u{2014}";      // —

    /** Characters after which a quote opens rather than closes. */
    private const array OPENING_AFTER = ['', '(', '[', '{', '-', self::EM_DASH, self::LEFT_SINGLE, self::LEFT_DOUBLE];

    public static function apply(string $run): string
    {
        if ($run === '') {
            return $run;
        }

        $run = \str_replace(['---', '--'], self::EM_DASH, $run);

        // Only the quotes need deciding, so seek to them rather than walking
        // every character. Splitting the run into single-character strings
        // costs tens of bytes per character — on a book-length run that alone
        // is more memory than the rest of the flow put together, and it is
        // spent on characters that were never going to change.
        $length = \strlen($run);
        $out = '';
        $copied = 0;
        $cursor = 0;

        while ($cursor < $length) {
            $cursor += \strcspn($run, '"\'', $cursor);
            if ($cursor >= $length) {
                break;
            }

            // Safe on bytes: a quote is ASCII, and UTF-8 never puts an ASCII
            // byte inside a multi-byte sequence.
            $prev = self::precedingCharacter($run, $cursor);
            $out .= \substr($run, $copied, $cursor - $copied);
            $out .= $run[$cursor] === '"'
                ? (self::isOpening($prev) ? self::LEFT_DOUBLE : self::RIGHT_DOUBLE)
                : (self::isApostrophe($prev)
                    ? self::RIGHT_SINGLE
                    : (self::isOpening($prev) ? self::LEFT_SINGLE : self::RIGHT_SINGLE));

            $cursor++;
            $copied = $cursor;
        }

        return $copied === 0 ? $run : $out . \substr($run, $copied);
    }

    /**
     * The whole character before $position — walked back over UTF-8
     * continuation bytes, because {@see isApostrophe()} asks whether the
     * preceding character is a letter and `é` must answer as one.
     */
    private static function precedingCharacter(string $run, int $position): string
    {
        if ($position === 0) {
            return '';
        }

        $start = $position - 1;
        // A continuation byte is 10xxxxxx; at most three precede a lead byte.
        while ($start > 0 && $position - $start < 4 && (\ord($run[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return \substr($run, $start, $position - $start);
    }

    private static function isOpening(string $prev): bool
    {
        return \in_array($prev, self::OPENING_AFTER, true) || \preg_match('/^\s$/u', $prev) === 1;
    }

    private static function isApostrophe(string $prev): bool
    {
        return \preg_match('/^[\p{L}\p{N}]$/u', $prev) === 1;
    }
}
