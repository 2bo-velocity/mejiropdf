<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Image\Image;
use MejiroPDF\Page\CreditOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentImageTest extends TestCase
{
    private function fixture(string $file): string
    {
        return __DIR__ . '/../fixtures/images/' . $file;
    }

    #[Test]
    public function it_places_an_image_and_registers_the_xobject(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($this->fixture('rgb.jpg'), 20, 20, 40);

        $out = $pdf->output();

        self::assertStringContainsString('/Filter /DCTDecode', $out);
        self::assertStringContainsString('/Im0 Do', $out);
        self::assertMatchesRegularExpression('~/XObject << /Im0 \d+ 0 R~', $out);
        // A cm matrix positions/scales the unit image square.
        self::assertMatchesRegularExpression('~q\n[\d.]+ 0 0 [\d.]+ [\d.]+ [\d.]+ cm~', $out);
    }

    #[Test]
    public function the_same_image_instance_is_embedded_once_across_pages(): void
    {
        $logo = Image::fromFile($this->fixture('rgb.png'));

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($logo, 10, 10, 20);
        $pdf->addPage()->image($logo, 10, 10, 20);

        $out = $pdf->output();

        self::assertSame(1, \substr_count($out, '/Subtype /Image')); // embedded once
        self::assertSame(2, \substr_count($out, '/Im0 Do'));          // painted twice
    }

    #[Test]
    public function width_only_preserves_aspect_ratio(): void
    {
        // rgba.png is 4x4 (square) — width and height come out equal.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($this->fixture('rgba.png'), 0, 0, 50);

        $out = $pdf->output();

        // 50 mm -> 141.73 pt, applied to both axes for a square source.
        self::assertMatchesRegularExpression('~141\.7\d 0 0 141\.7\d ~', $out);
    }
}
