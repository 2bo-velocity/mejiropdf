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

namespace MejiroPDF;

/**
 * The library's own version, following Semantic Versioning 2.0.0.
 *
 * Bump {@see MAJOR} for incompatible API changes, {@see MINOR} for backward
 * compatible feature additions, and {@see PATCH} for backward compatible bug
 * fixes. The single string is mirrored into every generated PDF's `/Producer`
 * (see {@see producer()}), so an output file alone reveals which build made it.
 */
final class Version
{
    /** The current release, SemVer `MAJOR.MINOR.PATCH`. */
    public const string VERSION = '1.3.1';

    public const int MAJOR = 1;
    public const int MINOR = 3;
    public const int PATCH = 1;

    private function __construct()
    {
    }

    /** The version string, e.g. `"1.3.1"` — for logging and `>=` checks. */
    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * The `/Producer` string embedded in generated PDFs, e.g. `"MejiroPDF 1.3.1"`.
     */
    public static function producer(): string
    {
        return 'MejiroPDF ' . self::VERSION;
    }
}
