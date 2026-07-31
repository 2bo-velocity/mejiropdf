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
 * Built-in Japanese CIDFonts (Adobe-Japan1) that PDF viewers render without any
 * embedded font file — the lightest possible way to put Japanese text on a page.
 *
 * Text is written as UTF-16BE and mapped to CIDs by the predefined
 * `UniJIS-UTF16-H` CMap. For pixel-perfect, portable output across every viewer,
 * embed a TrueType subset instead (a later increment).
 */
enum CidFontJapanese: string implements FontFace
{
    /** Mincho (serif). */
    case HEISEI_MIN_W3 = 'HeiseiMin-W3';

    /** Gothic (sans-serif). */
    case HEISEI_KAKU_GO = 'HeiseiKakuGo-W5';

    public function postScriptName(): string
    {
        return $this->value;
    }

    public function baseFontName(): string
    {
        return $this->value;
    }

    public function kind(): FontKind
    {
        return FontKind::CID_JP;
    }

    /**
     * Predefined CMap: maps UTF-16BE code units to Adobe-Japan1 CIDs.
     */
    public function cmap(): string
    {
        return 'UniJIS-UTF16-H';
    }

    public function registry(): string
    {
        return 'Adobe';
    }

    public function ordering(): string
    {
        return 'Japan1';
    }

    public function supplement(): int
    {
        return 6;
    }

    /**
     * Default advance width (full-width em) in 1000-unit glyph space.
     */
    public function defaultWidth(): int
    {
        return 1000;
    }
}
