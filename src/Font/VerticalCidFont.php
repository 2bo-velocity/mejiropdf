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
 * A built-in Japanese CIDFont addressed through the **vertical** CMap.
 *
 * `UniJIS-UTF16-V` maps each code unit to Adobe-Japan1's vertical CID, so the
 * font itself supplies the shapes vertical setting needs — the rotated 「」（）
 * ー, the full stop moved to the cell's upper right, the small kana nudged the
 * same way. None of that has to be reproduced by moving glyphs about, and none
 * of it can drift out of step with the typeface, because it *is* the typeface.
 *
 * The CMap also carries the vertical metrics: text advances downward by one em
 * per character (Adobe-Japan1's default `DW2`), which is why a whole column can
 * be written as a single `Tj` rather than one text object per glyph.
 *
 * Only the parent Type0 font differs from the horizontal face — the descendant
 * CIDFont is emitted once and referenced by both ({@see \MejiroPDF\Document}),
 * as the PDF specification intends and as other generators do.
 */
final readonly class VerticalCidFont implements FontFace
{
    private function __construct(public CidFontJapanese $horizontal)
    {
    }

    /**
     * The vertical face for $font, or $font unchanged when it is not one of the
     * built-in CIDFonts — callers can hand over whatever face they hold.
     */
    public static function of(FontFace $font): FontFace
    {
        return $font instanceof CidFontJapanese ? new self($font) : $font;
    }

    /**
     * Distinct from the horizontal face's key so the two get their own entries
     * in the resource dictionary; a document may use both at once.
     */
    public function baseFontName(): string
    {
        return $this->horizontal->baseFontName() . '-V';
    }

    /** The PDF `/BaseFont` value, which is the typeface, not the direction. */
    public function postScriptName(): string
    {
        return $this->horizontal->postScriptName();
    }

    public function kind(): FontKind
    {
        return FontKind::CID_JP;
    }

    /** Predefined CMap: UTF-16BE code units to Adobe-Japan1 *vertical* CIDs. */
    public function cmap(): string
    {
        return 'UniJIS-UTF16-V';
    }

    public function registry(): string
    {
        return $this->horizontal->registry();
    }

    public function ordering(): string
    {
        return $this->horizontal->ordering();
    }

    public function supplement(): int
    {
        return $this->horizontal->supplement();
    }

    public function defaultWidth(): int
    {
        return $this->horizontal->defaultWidth();
    }
}
