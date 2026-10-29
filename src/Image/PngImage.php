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
 * A PNG image.
 *
 * Opaque images (grayscale / truecolor / palette) stream through untouched:
 * their IDAT data is a Flate stream and PDF replays PNG row filtering via
 * /DecodeParms /Predictor 15. Images with an alpha channel are decoded, the
 * colour and alpha are split, and the alpha becomes a grayscale /SMask.
 * Interlaced PNGs are not supported.
 *
 * The header is not trusted: an image is refused when it has no area, and the
 * alpha path — the only one that decodes pixels in PHP — checks the inflated
 * data against the size the header claims, and that size against
 * {@see MAX_DECODED_BYTES}, before touching a single row. Otherwise a few dozen
 * bytes claiming 50000 × 50000 would keep a worker busy indefinitely.
 */
final class PngImage implements RasterImage
{
    private const int GRAY = 0;
    private const int RGB = 2;
    private const int PALETTE = 3;
    private const int GRAY_ALPHA = 4;
    private const int RGBA = 6;

    /**
     * Largest decoded size (filter bytes included) accepted for an image with
     * an alpha channel, whose pixels have to be split in PHP. 256 MiB is an
     * 8192 × 8192 RGBA image; opaque images are passed through undecoded and
     * are not subject to it.
     */
    public const int MAX_DECODED_BYTES = 256 * 1024 * 1024;

    private function __construct(
        private readonly int $width,
        private readonly int $height,
        private readonly int $bitDepth,
        private readonly int $colorType,
        private readonly string $idat,
        private readonly string $palette,
    ) {
    }

    public static function fromString(string $data): self
    {
        $length = \strlen($data);
        $i = 8; // skip signature
        $ihdr = null;
        $palette = '';
        $idat = '';

        while ($i + 8 <= $length) {
            $chunkLength = self::u32($data, $i);
            $type = \substr($data, $i + 4, 4);
            $chunkData = \substr($data, $i + 8, $chunkLength);
            $i += 12 + $chunkLength; // length + type + data + crc

            match ($type) {
                'IHDR' => $ihdr = $chunkData,
                'PLTE' => $palette = $chunkData,
                'IDAT' => $idat .= $chunkData,
                default => null,
            };

            if ($type === 'IEND') {
                break;
            }
        }

        if ($ihdr === null || \strlen($ihdr) < 13) {
            throw ImageException::invalid('PNG is missing its IHDR chunk.');
        }

        $width = self::u32($ihdr, 0);
        $height = self::u32($ihdr, 4);
        $bitDepth = \ord($ihdr[8]);
        $colorType = \ord($ihdr[9]);
        $interlace = \ord($ihdr[12]);

        if ($width < 1 || $height < 1) {
            throw ImageException::invalid("PNG has no area ({$width} x {$height}).");
        }
        if ($interlace !== 0) {
            throw ImageException::unsupported('Interlaced PNG is not supported.');
        }
        if (!\in_array($colorType, [self::GRAY, self::RGB, self::PALETTE, self::GRAY_ALPHA, self::RGBA], true)) {
            throw ImageException::unsupported("Unsupported PNG colour type: {$colorType}.");
        }
        if ($idat === '') {
            throw ImageException::invalid('PNG has no image data (IDAT).');
        }

        return new self($width, $height, $bitDepth, $colorType, $idat, $palette);
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
        return match ($this->colorType) {
            self::GRAY_ALPHA, self::RGBA => $this->embedWithAlpha($writer),
            default => $this->embedOpaque($writer),
        };
    }

    private function embedOpaque(DocumentWriter $writer): int
    {
        [$colorSpace, $colors] = match ($this->colorType) {
            self::RGB => ['/DeviceRGB', 3],
            self::GRAY => ['/DeviceGray', 1],
            default => [$this->indexedColorSpace(), 1], // PALETTE
        };

        $decodeParms = \sprintf(
            '<< /Predictor 15 /Colors %d /BitsPerComponent %d /Columns %d >>',
            $colors,
            $this->bitDepth,
            $this->width,
        );

        $id = $writer->allocateObjectId();
        $writer->writeStreamObject($id, $this->idat, \sprintf(
            '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s '
            . '/BitsPerComponent %d /Filter /FlateDecode /DecodeParms %s',
            $this->width,
            $this->height,
            $colorSpace,
            $this->bitDepth,
            $decodeParms,
        ));

        return $id;
    }

    private function embedWithAlpha(DocumentWriter $writer): int
    {
        if ($this->bitDepth !== 8) {
            throw ImageException::unsupported('Only 8-bit PNGs with an alpha channel are supported.');
        }

        $channels = $this->colorType === self::RGBA ? 3 : 1;
        $bpp = $channels + 1;

        // One filter byte per row, then the samples.
        $expected = $this->height * ($this->width * $bpp + 1);
        if ($expected > self::MAX_DECODED_BYTES) {
            throw ImageException::unsupported(\sprintf(
                'PNG with alpha decodes to %d bytes, above the %d this engine will decode.',
                $expected,
                self::MAX_DECODED_BYTES,
            ));
        }
        $raw = self::inflate($this->idat, $expected);
        if (\strlen($raw) !== $expected) {
            throw ImageException::invalid(\sprintf(
                'PNG image data inflates to %d bytes; its header promises %d.',
                \strlen($raw),
                $expected,
            ));
        }
        $raw = $this->unfilter($raw, $bpp);

        $pixels = $this->width * $this->height;
        $color = '';
        $alpha = '';
        for ($p = 0; $p < $pixels; $p++) {
            $offset = $p * $bpp;
            $color .= \substr($raw, $offset, $channels);
            $alpha .= $raw[$offset + $channels];
        }

        $smaskId = $writer->allocateObjectId();
        $writer->writeStreamObject($smaskId, self::deflate($alpha), \sprintf(
            '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray '
            . '/BitsPerComponent 8 /Filter /FlateDecode',
            $this->width,
            $this->height,
        ));

        $id = $writer->allocateObjectId();
        $writer->writeStreamObject($id, self::deflate($color), \sprintf(
            '/Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s '
            . '/BitsPerComponent 8 /SMask %d 0 R /Filter /FlateDecode',
            $this->width,
            $this->height,
            $channels === 3 ? '/DeviceRGB' : '/DeviceGray',
            $smaskId,
        ));

        return $id;
    }

    /**
     * Reverse PNG scanline filtering, returning raw samples (no filter bytes).
     */
    private function unfilter(string $data, int $bpp): string
    {
        $stride = $this->width * $bpp;
        $out = '';
        $prev = \str_repeat("\x00", $stride);
        $pos = 0;

        for ($y = 0; $y < $this->height; $y++) {
            $filter = \ord($data[$pos]);
            $pos++;
            $cur = '';

            for ($x = 0; $x < $stride; $x++) {
                $raw = \ord($data[$pos + $x]);
                $a = $x >= $bpp ? \ord($cur[$x - $bpp]) : 0;
                $b = \ord($prev[$x]);
                $c = $x >= $bpp ? \ord($prev[$x - $bpp]) : 0;

                $value = match ($filter) {
                    1 => $raw + $a,
                    2 => $raw + $b,
                    3 => $raw + \intdiv($a + $b, 2),
                    4 => $raw + self::paeth($a, $b, $c),
                    default => $raw,
                };

                $cur .= \chr($value & 0xFF);
            }

            $pos += $stride;
            $out .= $cur;
            $prev = $cur;
        }

        return $out;
    }

    private function indexedColorSpace(): string
    {
        if ($this->palette === '') {
            throw ImageException::invalid('Palette (indexed) PNG is missing its PLTE chunk.');
        }

        $hival = \intdiv(\strlen($this->palette), 3) - 1;

        return \sprintf('[ /Indexed /DeviceRGB %d < %s > ]', $hival, \strtoupper(\bin2hex($this->palette)));
    }

    /**
     * Inflate until the output passes $limit, then stop: enough to tell a
     * stream of the right size from a longer one, without letting it run on.
     */
    private static function inflate(string $data, int $limit): string
    {
        $context = \inflate_init(\ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw ImageException::invalid('PNG image data could not be inflated.');
        }

        $out = '';
        foreach (\str_split($data, 8192) as $chunk) {
            $piece = @\inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                throw ImageException::invalid('PNG image data could not be inflated.');
            }
            $out .= $piece;
            if (\strlen($out) > $limit || \inflate_get_status($context) === \ZLIB_STREAM_END) {
                break;
            }
        }

        return $out;
    }

    private static function deflate(string $data): string
    {
        $z = \gzcompress($data, 6);

        return $z === false ? $data : $z;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = \abs($p - $a);
        $pb = \abs($p - $b);
        $pc = \abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
    }

    private static function u32(string $b, int $o): int
    {
        return (\ord($b[$o]) << 24) | (\ord($b[$o + 1]) << 16) | (\ord($b[$o + 2]) << 8) | \ord($b[$o + 3]);
    }
}
