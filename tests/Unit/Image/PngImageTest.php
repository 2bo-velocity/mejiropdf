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
}
