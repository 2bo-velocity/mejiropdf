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
 * What becomes of Latin letters and digits inside a vertical column.
 *
 * There is no single right answer, which is why this is a setting rather than
 * a rule: a book may stand a product code upright, turn a URL on its side, or
 * widen everything to full-width so each character keeps its own cell. Both of
 * the Japanese generators measured for this work chose full-width; a typeset
 * novel more often turns long runs and stands short ones up.
 *
 * Applies only to vertical setting, and only to ASCII letters and digits —
 * full-width forms are already one cell each and are left alone.
 */
enum LatinInVertical
{
    /**
     * Each character upright in its own cell, at its natural width. The
     * default, because it is what the engine has always done.
     */
    case UPRIGHT;

    /**
     * Mapped to the full-width forms, so every character occupies one cell and
     * the column stays on the grid. What both sampled generators do.
     */
    case FULLWIDTH;

    /** A run set sideways as one piece, read by tilting the head. */
    case ROTATE;

    /**
     * The Japanese convention: a short run stands upright across the column
     * (tate-chu-yoko) and a longer one is turned. Where the line falls is
     * {@see TypesetterOptions::$tcyLimit} — two characters by custom, being as
     * much as fits an em without crowding.
     */
    case TCY;

    /** Whether a run of $length characters becomes one upright cell. */
    public function standsUprightInOneCell(int $length, int $tcyLimit): bool
    {
        return $this === self::TCY && $length <= $tcyLimit;
    }

    /**
     * ASCII letters and digits widened to their full-width forms; anything
     * else is returned unchanged.
     */
    public static function toFullWidth(string $run): string
    {
        $wide = '';
        foreach (\str_split($run) as $byte) {
            $code = \ord($byte);
            $wide .= $code >= 0x21 && $code <= 0x7E
                ? \mb_chr($code - 0x21 + 0xFF01, 'UTF-8')
                : ($byte === ' ' ? "\u{3000}" : $byte);
        }

        return $wide;
    }
}
