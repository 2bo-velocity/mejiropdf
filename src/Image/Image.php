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

namespace MejiroPDF\Image;

use MejiroPDF\Exception\ImageException;

/**
 * Detects an image's format from its signature and returns the right decoder.
 */
final class Image
{
    private const string JPEG_MAGIC = "\xFF\xD8\xFF";
    private const string PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    public static function fromFile(string $path): RasterImage
    {
        $bytes = @\file_get_contents($path);
        if ($bytes === false) {
            throw ImageException::notReadable($path);
        }

        return self::fromString($bytes);
    }

    public static function fromString(string $bytes): RasterImage
    {
        if (\str_starts_with($bytes, self::JPEG_MAGIC)) {
            return JpegImage::fromString($bytes);
        }

        if (\str_starts_with($bytes, self::PNG_MAGIC)) {
            return PngImage::fromString($bytes);
        }

        throw ImageException::unsupported('Unrecognised image format (expected JPEG or PNG).');
    }
}
