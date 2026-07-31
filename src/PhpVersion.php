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
 * Guards the minimum PHP version. Deliberately written without any
 * PHP 8.1+/8.3-only syntax (no enums, readonly, typed class constants, …) so it
 * still *parses* — and throws a clear, actionable message — on an older runtime,
 * where the rest of the engine would otherwise die with an opaque parse error.
 * It is loaded eagerly through Composer's `autoload.files`.
 */
final class PhpVersion
{
    /** Minimum supported PHP, matching composer.json "php": ">=8.3". */
    public const MINIMUM = '8.3.0';

    public const MINIMUM_ID = 80300;

    public const ERROR_CODE = 'MP-CORE-1001';

    /**
     * @throws \RuntimeException when $versionId is below {@see MINIMUM_ID}
     */
    public static function guard(int $versionId, string $version): void
    {
        if ($versionId < self::MINIMUM_ID) {
            throw new \RuntimeException(\sprintf(
                '[%s] MejiroPDF requires PHP %s or higher. This environment runs PHP %s; please upgrade PHP.',
                self::ERROR_CODE,
                self::MINIMUM,
                $version
            ));
        }
    }
}
