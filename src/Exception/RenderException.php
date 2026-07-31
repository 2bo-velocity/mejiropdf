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

namespace MejiroPDF\Exception;

/**
 * Drawing / coordinate failures (MP-RND-*).
 */
final class RenderException extends AbstractPdfException
{
    /**
     * Geometry that falls outside the page, raised only while the document runs
     * with strict canvas checking.
     *
     * Off by default because drawing past the edge is legitimate — full-bleed
     * covers, crop marks and deliberately clipped art all rely on it, and a PDF
     * viewer simply does not paint what lies outside the MediaBox. What the
     * check buys is the other case: a millimetre/point mix-up or an off-by-one
     * column offset that quietly drops content off the sheet. Turned on in
     * development, that becomes an exception naming the element and its box.
     */
    public static function canvasOverflow(
        string $what,
        float $x,
        float $y,
        float $width,
        float $height,
        float $pageWidth,
        float $pageHeight,
    ): self {
        return new self(
            ErrorCode::CANVAS_OVERFLOW->message(\sprintf(
                '%s at [%.2F, %.2F, %.2F × %.2F] mm falls outside the %.2F × %.2F mm page.',
                $what,
                $x,
                $y,
                $width,
                $height,
                $pageWidth,
                $pageHeight,
            )),
            ErrorCode::CANVAS_OVERFLOW,
            context: [
                'element' => $what,
                'x' => $x,
                'y' => $y,
                'width' => $width,
                'height' => $height,
                'pageWidth' => $pageWidth,
                'pageHeight' => $pageHeight,
            ],
        );
    }
}
