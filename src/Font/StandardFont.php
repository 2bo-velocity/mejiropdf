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
 * The PDF Standard 14 Fonts. These need no embedding — every conformant PDF
 * viewer is required to render them — so MejiroPDF ships only their metrics.
 */
enum StandardFont: string implements FontFace
{
    case HELVETICA             = 'Helvetica';
    case HELVETICA_BOLD        = 'Helvetica-Bold';
    case HELVETICA_OBLIQUE     = 'Helvetica-Oblique';
    case HELVETICA_BOLDOBLIQUE = 'Helvetica-BoldOblique';
    case TIMES_ROMAN           = 'Times-Roman';
    case TIMES_BOLD            = 'Times-Bold';
    case TIMES_ITALIC          = 'Times-Italic';
    case TIMES_BOLDITALIC      = 'Times-BoldItalic';
    case COURIER               = 'Courier';
    case COURIER_BOLD          = 'Courier-Bold';
    case COURIER_OBLIQUE       = 'Courier-Oblique';
    case COURIER_BOLDOBLIQUE   = 'Courier-BoldOblique';
    case SYMBOL                = 'Symbol';
    case ZAPF_DINGBATS         = 'ZapfDingbats';

    /**
     * PostScript name used as the PDF /BaseFont value.
     */
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
        return FontKind::STANDARD;
    }

    /**
     * Whether this face is a monospaced (Courier) font.
     */
    public function isMonospaced(): bool
    {
        return match ($this) {
            self::COURIER,
            self::COURIER_BOLD,
            self::COURIER_OBLIQUE,
            self::COURIER_BOLDOBLIQUE => true,
            default => false,
        };
    }

    /**
     * The metric family (which width table applies) for this face.
     */
    public function family(): StandardFontFamily
    {
        return match ($this) {
            self::TIMES_ROMAN,
            self::TIMES_BOLD,
            self::TIMES_ITALIC,
            self::TIMES_BOLDITALIC => StandardFontFamily::TIMES,

            self::COURIER,
            self::COURIER_BOLD,
            self::COURIER_OBLIQUE,
            self::COURIER_BOLDOBLIQUE => StandardFontFamily::COURIER,

            self::SYMBOL,
            self::ZAPF_DINGBATS => StandardFontFamily::SYMBOL,

            default => StandardFontFamily::HELVETICA,
        };
    }
}
