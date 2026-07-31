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
 * Whether a face can actually draw a string, asked before the engine commits to
 * it rather than after a reader has rendered 豆腐.
 *
 * Only used where a second face is available to fall back to — furigana set in
 * its own font being the case that motivated it. A missing glyph in the *body*
 * font is a Fail-Fast error (MP-FNT-2002), not something to paper over.
 */
final class GlyphCoverage
{
    /**
     * True when every character of $text has a glyph in $font.
     *
     * The three kinds answer differently because their encodings differ:
     *
     * - **TrueType** is authoritative — the embedded cmap either maps the code
     *   point or returns .notdef.
     * - **CIDFont** (Acrobat's built-in Japanese faces) carries the full JIS
     *   X 0208 repertoire including both kana, so any furigana it is asked for
     *   is covered; the engine cannot inspect a font it does not embed.
     * - **Standard 14** is a single-byte face: anything above Latin-1 — which is
     *   every kana — has no glyph.
     */
    public static function covers(FontFace $font, string $text): bool
    {
        if ($text === '') {
            return true;
        }

        return match (true) {
            $font instanceof TrueTypeFont => self::trueTypeCovers($font, $text),
            $font instanceof CidFontJapanese => true,
            default => self::isLatin1($text),
        };
    }

    private static function trueTypeCovers(TrueTypeFont $font, string $text): bool
    {
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            // mb_str_split yields non-empty characters, so mb_ord returns an int.
            if ($font->gidForCodepoint(\mb_ord($char, 'UTF-8')) === 0) {
                return false;
            }
        }

        return true;
    }

    private static function isLatin1(string $text): bool
    {
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            if (\mb_ord($char, 'UTF-8') > 0xFF) {
                return false;
            }
        }

        return true;
    }
}
