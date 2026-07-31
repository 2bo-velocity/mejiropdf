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
 * Resolves a font's PostScript/base name (as stored in a compiled template) to
 * a concrete font face. Unknown names Fail-Fast with MP-FNT-2001.
 */
final class FontResolver
{
    /**
     * @param array<string, FontFace> $extra Faces the caller can supply by name —
     *                                           fonts carried inside the template, and
     *                                           fonts registered on the document.
     *
     * These are consulted **first**. A template that embeds its font measured its
     * boxes against that exact file, so a same-named face found elsewhere is a
     * different font and would re-break the designer's lines.
     */
    public static function resolve(string $baseName, array $extra = []): FontFace
    {
        return $extra[$baseName]
            ?? StandardFont::tryFrom($baseName)
            ?? CidFontJapanese::tryFrom($baseName)
            ?? throw FontException::loadFailed($baseName);
    }
}
