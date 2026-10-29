<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Image;

use MejiroPDF\Exception\ImageException;
use MejiroPDF\Image\PngImage;
use MejiroPDF\Stream\SafeStreamWriter;
use MejiroPDF\Writer\DocumentWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PngImage::class)]
final class PngImageTest extends TestCase
{
    private function embed(string $file): string
    {
        $image = PngImage::fromString((string) \file_get_contents(__DIR__ . '/../../fixtures/images/' . $file));

        self::assertSame(4, $image->width());
        self::assertSame(4, $image->height());

        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        $image->embed(new DocumentWriter(new SafeStreamWriter($stream)));
        \rewind($stream);

        return (string) \stream_get_contents($stream);
    }

    #[Test]
    public function opaque_rgb_streams_through_with_a_png_predictor(): void
    {
        $out = $this->embed('rgb.png');

        self::assertStringContainsString('/Filter /FlateDecode', $out);
        self::assertStringContainsString('/Predictor 15', $out);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $out);
        self::assertStringNotContainsString('/SMask', $out);
    }

    #[Test]
    public function palette_becomes_an_indexed_colour_space(): void
    {
        $out = $this->embed('pal.png');

        self::assertStringContainsString('/Indexed /DeviceRGB', $out);
        self::assertStringContainsString('/Predictor 15', $out);
    }

    #[Test]
    public function rgba_is_split_into_an_image_and_a_soft_mask(): void
    {
        $out = $this->embed('rgba.png');

        // Two image XObjects: the colour image and its grayscale soft mask.
        self::assertSame(2, \substr_count($out, '/Subtype /Image'));
        self::assertStringContainsString('/SMask', $out);
        self::assertStringContainsString('/ColorSpace /DeviceGray', $out); // the mask
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $out);  // the colour
        // Alpha path decodes then re-deflates (no predictor on the split streams).
        self::assertStringNotContainsString('/Predictor', $out);
    }

    #[Test]
    public function it_rejects_non_png_data(): void
    {
        $this->expectException(ImageException::class);

        PngImage::fromString('nope');
    }

    /**
     * A minimal PNG: signature, IHDR, one IDAT holding $pixels compressed,
     * IEND.
     */
    private static function png(int $width, int $height, int $colorType, string $pixels): string
    {
        $chunk = static fn (string $type, string $data): string
            => \pack('N', \strlen($data)) . $type . $data . \pack('N', \crc32($type . $data));

        return "\x89PNG\r\n\x1A\n"
            . $chunk('IHDR', \pack('NNCCCCC', $width, $height, 8, $colorType, 0, 0, 0))
            . $chunk('IDAT', (string) \gzcompress($pixels))
            . $chunk('IEND', '');
    }

    private static function embedBytes(string $png): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        PngImage::fromString($png)->embed(new DocumentWriter(new SafeStreamWriter($stream)));
    }

    #[Test]
    public function an_image_with_no_area_is_refused(): void
    {
        // Used to reach Document::image() and divide by its zero width.
        $this->expectException(ImageException::class);

        PngImage::fromString(self::png(0, 0, 2, ''));
    }

    #[Test]
    public function alpha_data_shorter_than_its_header_promises_is_refused_quickly(): void
    {
        // A few dozen bytes claiming 4000 x 4000 RGBA — under the decode
        // ceiling, so the length check is what refuses it. Without that check
        // the rows were unfiltered one by one over data that is not there.
        $start = \microtime(true);
        try {
            self::embedBytes(self::png(4000, 4000, 6, "\0\0\0\0\0"));
            self::fail('Truncated alpha data must be refused.');
        } catch (ImageException $e) {
            self::assertSame('MP-IMG-5002', $e->getErrorCode());
        }
        self::assertLessThan(2.0, \microtime(true) - $start);
    }

    #[Test]
    public function alpha_data_longer_than_its_header_promises_is_refused(): void
    {
        $this->expectException(ImageException::class);

        // One 1 x 1 RGBA row is 5 bytes (filter byte + 4 samples); give it 6.
        self::embedBytes(self::png(1, 1, 6, "\0\1\2\3\4\5"));
    }

    #[Test]
    public function alpha_images_beyond_the_decode_ceiling_are_refused_before_inflating(): void
    {
        $start = \microtime(true);
        try {
            self::embedBytes(self::png(50000, 50000, 6, "\0"));
            self::fail('An alpha image beyond the decode ceiling must be refused.');
        } catch (ImageException $e) {
            self::assertSame('MP-IMG-5001', $e->getErrorCode());
        }
        self::assertLessThan(1.0, \microtime(true) - $start);
    }

    #[Test]
    public function well_formed_alpha_data_built_in_place_still_embeds(): void
    {
        // Two rows of 2 x RGBA, filter "none".
        $rows = "\0" . \str_repeat("\xFF\x00\x00\x80", 2) . "\0" . \str_repeat("\x00\xFF\x00\xFF", 2);
        self::embedBytes(self::png(2, 2, 6, $rows));

        $this->addToAssertionCount(1); // no exception
    }
}
