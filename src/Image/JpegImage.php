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
use MejiroPDF\Writer\DocumentWriter;

/**
 * A JPEG embedded by pass-through: PDF decodes it natively via /DCTDecode, so
 * the raw bytes stream straight in with zero re-compression (chunk-stream).
 */
final class JpegImage implements RasterImage
{
    private function __construct(
        private readonly string $data,
        private readonly int $width,
        private readonly int $height,
        private readonly int $bits,
        private readonly string $colorSpace,
    ) {
    }

    public static function fromString(string $data): self
    {
        $length = \strlen($data);
        $i = 2; // skip SOI (FF D8)

        while ($i + 1 < $length) {
            if (\ord($data[$i]) !== 0xFF) {
                $i++;
                continue;
            }

            $marker = \ord($data[$i + 1]);
            $i += 2;

            // Standalone markers without a length payload.
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            if ($i + 1 >= $length) {
                break;
            }
            $segmentLength = (\ord($data[$i]) << 8) | \ord($data[$i + 1]);

            // SOF0..SOF15 (except DHT=C4, JPG=C8, DAC=CC) carry the frame header.
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($i + 7 >= $length) {
                    break;
                }
                $bits = \ord($data[$i + 2]);
                $height = (\ord($data[$i + 3]) << 8) | \ord($data[$i + 4]);
                $width = (\ord($data[$i + 5]) << 8) | \ord($data[$i + 6]);
                $components = \ord($data[$i + 7]);

                $colorSpace = match ($components) {
                    1 => '/DeviceGray',
                    3 => '/DeviceRGB',
                    4 => '/DeviceCMYK',
                    default => throw ImageException::unsupported("Unsupported JPEG component count: {$components}."),
                };

                return new self($data, $width, $height, $bits, $colorSpace);
            }

            $i += $segmentLength;
        }

        throw ImageException::invalid('No JPEG start-of-frame (SOF) marker found.');
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function embed(DocumentWriter $writer): int
    {
        $id = $writer->allocateObjectId();
        $writer->writeStreamObject($id, $this->data, \sprintf(
            '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s '
            . '/BitsPerComponent %d /Filter /DCTDecode',
            $this->width,
            $this->height,
            $this->colorSpace,
            $this->bits,
        ));

        return $id;
    }
}
