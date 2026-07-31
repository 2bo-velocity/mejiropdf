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
 * Resolves the correct {@see FontMetricsInterface} for any supported font face.
 */
final class FontMetrics
{
    public static function for(FontFace $font): FontMetricsInterface
    {
        return match (true) {
            $font instanceof TrueTypeFont => $font,
            $font instanceof CidFontJapanese => new CidFontMetrics($font),
            $font instanceof StandardFont => new StandardFontMetrics($font),
            default => throw new \LogicException('Unsupported font face: ' . $font::class),
        };
    }
}
