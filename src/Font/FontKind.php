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
 * How a registered font is emitted into the PDF.
 */
enum FontKind
{
    /** Standard 14 face — a single /Type1 font object, WinAnsi text. */
    case STANDARD;

    /** Japanese CIDFont — /Type0 + descendant /CIDFontType0, UTF-16BE text. */
    case CID_JP;

    /** Embedded TrueType — /Type0 + /CIDFontType2, Identity-H, glyph-id text. */
    case TRUETYPE;
}
