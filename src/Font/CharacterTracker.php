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
 * Records which Unicode code points a document uses in a given font, so only
 * the needed glyph widths (and ToUnicode entries) are emitted.
 */
final class CharacterTracker
{
    /** @var array<int, true> */
    private array $codepoints = [];

    public function register(string $text): void
    {
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            // mb_str_split yields non-empty characters, so mb_ord returns an int.
            $this->codepoints[\mb_ord($char, 'UTF-8')] = true;
        }
    }

    /**
     * Distinct code points used, ascending.
     *
     * @return list<int>
     */
    public function codepoints(): array
    {
        $cps = \array_keys($this->codepoints);
        \sort($cps);

        return $cps;
    }

    public function isEmpty(): bool
    {
        return $this->codepoints === [];
    }
}
