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
 * Image loading / decoding failures (MP-IMG-*).
 */
final class ImageException extends AbstractPdfException
{
    public static function notReadable(string $path): self
    {
        return new self(
            ErrorCode::IMAGE_INVALID->message("Image file not found or unreadable: {$path}"),
            ErrorCode::IMAGE_INVALID,
            context: ['path' => $path],
        );
    }

    public static function invalid(string $detail): self
    {
        return new self(
            ErrorCode::IMAGE_INVALID->message($detail),
            ErrorCode::IMAGE_INVALID,
        );
    }

    public static function unsupported(string $detail): self
    {
        return new self(
            ErrorCode::IMAGE_UNSUPPORTED->message($detail),
            ErrorCode::IMAGE_UNSUPPORTED,
        );
    }
}
