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
use MejiroPDF\Image\JpegImage;
use MejiroPDF\Stream\SafeStreamWriter;
use MejiroPDF\Writer\DocumentWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JpegImage::class)]
final class JpegImageTest extends TestCase
{
    private function fixture(string $file): string
    {
        return __DIR__ . '/../../fixtures/images/' . $file;
    }

    /**
     * @return array{0: resource, 1: DocumentWriter}
     */
    private function writer(): array
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);

        return [$stream, new DocumentWriter(new SafeStreamWriter($stream))];
    }

    #[Test]
    public function it_reads_dimensions_from_the_sof_marker(): void
    {
        $image = JpegImage::fromString((string) \file_get_contents($this->fixture('rgb.jpg')));

        self::assertSame(4, $image->width());
        self::assertSame(4, $image->height());
    }

    #[Test]
    public function it_embeds_as_a_dctdecode_image_xobject(): void
    {
        $image = JpegImage::fromString((string) \file_get_contents($this->fixture('rgb.jpg')));
        [$stream, $writer] = $this->writer();

        $id = $image->embed($writer);
        \rewind($stream);
        $out = (string) \stream_get_contents($stream);

        self::assertGreaterThan(0, $id);
        self::assertStringContainsString('/Subtype /Image', $out);
        self::assertStringContainsString('/Filter /DCTDecode', $out);
        self::assertStringContainsString('/Width 4', $out);
        self::assertStringContainsString('/ColorSpace /DeviceRGB', $out);
    }

    #[Test]
    public function it_rejects_non_jpeg_data(): void
    {
        $this->expectException(ImageException::class);

        JpegImage::fromString('not a jpeg at all');
    }

    #[Test]
    public function a_frame_with_no_area_is_refused(): void
    {
        // SOI, then an SOF0 declaring 8-bit, 0 x 0, 3 components.
        $jpeg = "\xFF\xD8\xFF\xC0\x00\x11\x08\x00\x00\x00\x00\x03" . \str_repeat("\x00", 9);

        $this->expectException(ImageException::class);

        JpegImage::fromString($jpeg);
    }
}
