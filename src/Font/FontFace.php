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
 * A font face usable by the engine: a Standard 14 font, a built-in Japanese
 * CIDFont, or an embedded TrueType font. Lets the public API accept any of them
 * through one type.
 */
interface FontFace
{
    /**
     * The PDF /BaseFont value (PostScript name).
     */
    public function baseFontName(): string;

    /**
     * How this face is emitted into the PDF.
     */
    public function kind(): FontKind;
}
