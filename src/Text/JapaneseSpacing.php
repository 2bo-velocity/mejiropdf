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
 * The spacing Japanese setting puts between adjacent characters, after
 * JIS X 4051.
 *
 * Two rules carry most of the difference between text that reads as Japanese
 * and text that merely contains it:
 *
 * 1. **A quarter em between 和字 and Latin.** Without it `Ruby on Rails を使う`
 *    runs together at both seams. This is the single most visible thing the
 *    engine was missing horizontally; vertical setting hid it, being set solid
 *    in full-width cells.
 * 2. **Closing punctuation gives up its empty half before more punctuation.**
 *    `）` and `。` each carry their ink in the left half of a full em, so `）。`
 *    set solid leaves a hole the width of a character.
 *
 * Both come out as a signed adjustment after a character — a quarter em
 * forward, half an em back — so one mechanism expresses them.
 *
 * **Which character is which comes from Unicode, not from a list typed out
 * here**: {@see VerticalOrientation} separates 和字 from 欧字 (the letters that
 * stand upright in a vertical column are exactly the Japanese ones), and
 * {@see PunctuationClass} carries the brackets. A hand-written table of
 * vertical forms was wrong in four places before it was generated from the
 * standard; the same discipline applies here.
 */
enum JapaneseSpacing
{
    /**
     * Set solid — every character advances by its own width and nothing else.
     *
     * The default, and deliberately so: turning this on moves the text of
     * every existing Japanese document, and the engine's rule is that output
     * does not change under callers who did not ask for it.
     */
    case NONE;

    case JIS_X_4051;

    /** A quarter of an em between 和字 and Latin. */
    public const float LATIN_GAP = 0.25;

    /** Half an em recovered where closing punctuation meets more punctuation. */
    public const float PUNCTUATION_CLOSE_UP = -0.5;

    /**
     * The adjustment (in ems) to add after $left when $right follows it, or
     * 0.0 when the pair needs none.
     */
    public function between(string $left, string $right): float
    {
        if ($this === self::NONE || $left === '' || $right === '') {
            return 0.0;
        }

        $before = \mb_ord($left, 'UTF-8');
        $after = \mb_ord($right, 'UTF-8');

        // Closing punctuation runs into whatever punctuation follows it.
        if (PunctuationClass::of($before) === PunctuationClass::CLOSE
            && PunctuationClass::of($after) !== 0
        ) {
            return self::PUNCTUATION_CLOSE_UP;
        }

        return self::isJapanese($before) !== self::isJapanese($after)
            && self::isWord($left) && self::isWord($right)
                ? self::LATIN_GAP
                : 0.0;
    }

    /** A letter or digit — punctuation and spaces take no gap of their own. */
    private static function isWord(string $character): bool
    {
        return \preg_match('/^[\p{L}\p{N}]$/u', $character) === 1;
    }

    /**
     * Whether a letter belongs to Japanese setting rather than to a script
     * that reads sideways. Full-width Latin counts as Japanese, which is
     * right: it already occupies a cell of its own and wants no gap beside
     * 和字.
     */
    private static function isJapanese(int $codePoint): bool
    {
        // Any entry at all means the character belongs to CJK setting: upright
        // letters, the prolonged sound mark, the small kana. Only the scripts
        // that read sideways are absent, which is what makes them Latin here.
        //
        // Testing for UPRIGHT_LETTER alone was wrong: `ー` is recorded as a
        // rotating character and the small kana as shifting ones, so `ページ`
        // and `きゃっ` were being pulled apart as though they were foreign.
        return VerticalOrientation::of($codePoint) !== 0;
    }
}
