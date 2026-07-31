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
 * Font loading / embedding failures (MP-FNT-*).
 */
class FontException extends AbstractPdfException
{
    public static function loadFailed(string $path): self
    {
        return new self(
            ErrorCode::FONT_LOAD_FAILED->message("Font file could not be read: {$path}"),
            ErrorCode::FONT_LOAD_FAILED,
            context: ['path' => $path],
        );
    }
}
