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
 * A glyph required for rendering is missing from the embedded font subset and
 * no matching local font could be found (MP-FNT-2002). Fail-Fast: aborting is
 * preferable to silently emitting corrupted (tofu) text.
 */
final class GlyphNotFoundException extends FontException
{
    public static function forCharacter(string $char, int $page): self
    {
        return new self(
            \sprintf(
                'CRITICAL: Glyph for %s is missing in the embedded font subset, and no '
                . 'matching local font file was found. Execution aborted to prevent text corruption.',
                \json_encode($char, \JSON_UNESCAPED_UNICODE) ?: "'?'",
            ),
            ErrorCode::GLYPH_NOT_FOUND,
            context: ['char' => $char, 'page' => $page],
        );
    }
}
