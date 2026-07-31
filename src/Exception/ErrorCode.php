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
 * Canonical, language-independent error codes.
 *
 * Format: MP-[COMPONENT]-[####]. The code is the contract: it is stable across
 * releases and across languages, so callers may switch on it while the message
 * text stays free to change. MejiroPDF Studio uses the MPS- prefix for its own
 * codes, which are out of scope for this engine.
 *
 * Component slugs:
 *  - VAL  : validation (input / template / layout)
 *  - FNT  : font (loading / glyph resolution / embedding)
 *  - SIO  : stream / IO (tmp files, stream writes, disk)
 *  - RND  : render (drawing / coordinates / canvas)
 *  - IMG  : image (format support / decoding)
 *  - SEC  : security (encryption / signing)
 *  - CORE : core engine limits / control
 */
enum ErrorCode: string
{
    // Validation (VAL)
    case TEMPLATE_BROKEN         = 'MP-VAL-1001';
    case LAYOUT_SYNTAX           = 'MP-VAL-1002';
    case PDF_IMPORT_UNSUPPORTED  = 'MP-VAL-1003';
    case PDF_IMPORT_INVALID      = 'MP-VAL-1004';
    case MAX_LENGTH_EXCEEDED     = 'MP-VAL-MAX_LENGTH';

    // Font (FNT)
    case FONT_LOAD_FAILED     = 'MP-FNT-2001';
    case GLYPH_NOT_FOUND      = 'MP-FNT-2002';

    // Stream / IO (SIO)
    case STREAM_WRITE_FAILED  = 'MP-SIO-3001';
    case DISK_FULL            = 'MP-SIO-3002';

    // Render (RND)
    case CANVAS_OVERFLOW      = 'MP-RND-4001';

    // Image (IMG)
    case IMAGE_UNSUPPORTED    = 'MP-IMG-5001';
    case IMAGE_INVALID        = 'MP-IMG-5002';

    // Security (SEC)
    case ENCRYPTION_FAILED    = 'MP-SEC-6001';

    // Core (CORE)
    case UNSUPPORTED_PHP_VERSION = 'MP-CORE-1001';
    case PAGE_OVERFLOW           = 'MP-CORE-PAGE_OVERFLOW';

    /**
     * Format a message with the code and enum-case name prefixed.
     */
    public function message(string $detail = ''): string
    {
        $detail = $detail === '' ? '' : ' ' . $detail;

        return \sprintf('[%s] %s%s', $this->value, $this->name, $detail);
    }

    /**
     * The 3–4 letter component slug embedded in the code (VAL, FNT, SIO, …).
     */
    public function component(): string
    {
        // 'MP-VAL-1001' -> 'VAL'
        $parts = \explode('-', $this->value);

        return $parts[1] ?? 'CORE';
    }
}
