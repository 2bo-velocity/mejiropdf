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
use MejiroPDF\Exception\RenderException;
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

    #[Test]
    public function an_unrotated_image_emits_no_transform_of_its_own(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($this->fixture('rgba.png'), 10, 10, 20, rotation: 0.0);

        // The placement is a single self-contained q … Do Q: nothing wraps it.
        self::assertMatchesRegularExpression('~q\n[\d.]+ 0 0 [\d.]+ [\d.]+ [\d.]+ cm\n/Im0 Do\nQ\n~', $pdf->output());
    }

    #[Test]
    public function rotation_wraps_the_placement_in_a_quarter_turn(): void
    {
        // A 20 mm square at (10, 10) on A4: its centre is at (20 mm, 20 mm) from
        // the top-left, i.e. (56.69, 785.20) pt in the page's y-up space.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($this->fixture('rgba.png'), 10, 10, 20, rotation: 90.0);

        $out = $pdf->output();

        // cos90 = 0 and sin90 = 1 exactly — no 1e-17 residue from deg2rad.
        self::assertStringContainsString("0.00000 1.00000 -1.00000 0.00000 ", $out);
        // …and the inner placement is untouched by the wrapper.
        self::assertMatchesRegularExpression('~cm\nq\n[\d.]+ 0 0 [\d.]+ [\d.]+ [\d.]+ cm\n/Im0 Do\nQ\nQ\n~', $out);
    }

    #[Test]
    public function rotation_turns_about_the_centre_of_the_box(): void
    {
        // Turning a square about its own centre by 180 degrees leaves it exactly
        // where it was, so the translation terms are twice the centre.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->image($this->fixture('rgba.png'), 10, 10, 20, rotation: 180.0);

        // Centre (56.69, 785.20) pt: e = 2cx = 113.39, f = 2cy = 1570.39.
        self::assertStringContainsString('-1.00000 0.00000 0.00000 -1.00000 113.39 1570.39 cm', $pdf->output());
    }

    #[Test]
    public function a_rotated_image_is_judged_on_its_pivot_under_a_strict_canvas(): void
    {
        // The same box either way: it starts 5 mm left of the sheet, but the
        // centre it turns about is on the page. Rotated, only the pivot counts,
        // because a turned picture is entitled to swing its corners off the
        // edge — the latitude textObject() already gives a diagonal watermark.
        $pdf = new Document(credit: CreditOptions::disabled(), strictCanvas: true);
        $pdf->addPage()->image($this->fixture('rgba.png'), -5, 10, 20, rotation: 45.0);

        self::assertStringContainsString('/Im0 Do', $pdf->output());
    }

    #[Test]
    public function an_unrotated_image_is_still_judged_on_its_whole_box(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), strictCanvas: true);

        $this->expectException(RenderException::class);

        $pdf->addPage()->image($this->fixture('rgba.png'), -5, 10, 20);
    }
}
