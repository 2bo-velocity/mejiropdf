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
use MejiroPDF\Page\CreditOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentShapeTest extends TestCase
{
    private function doc(): Document
    {
        return new Document(credit: CreditOptions::disabled());
    }

    #[Test]
    public function circle_uses_four_bezier_curves(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->circle(50, 50, 20, stroke: '#000000', fill: '#FF0000');

        $out = $pdf->output();

        self::assertSame(4, \substr_count($out, " c\n")); // 4 quarter arcs
        self::assertStringContainsString("B\n", $out);     // fill + stroke
    }

    #[Test]
    public function rounded_rect_has_edges_and_corner_arcs(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->roundedRect(10, 10, 60, 30, 5, stroke: '#333333');

        $out = $pdf->output();

        self::assertSame(4, \substr_count($out, " c\n")); // 4 corners
        self::assertSame(4, \substr_count($out, " l\n")); // 4 edges
        self::assertStringContainsString("S\n", $out);    // stroked
    }

    #[Test]
    public function linear_gradient_registers_an_axial_shading(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->linearGradient(10, 10, 100, 20, '#FFFFFF', '#000000');

        $out = $pdf->output();

        self::assertStringContainsString('/ShadingType 2', $out);
        self::assertStringContainsString('/FunctionType 2', $out);
        self::assertMatchesRegularExpression('~/Shading << /Sh0 \d+ 0 R~', $out);
        self::assertStringContainsString(" sh\n", $out); // paint the shading
        self::assertStringContainsString('re W n', $out); // clipped to the rectangle
    }

    #[Test]
    public function radial_gradient_registers_a_radial_shading(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->radialGradient(10, 10, 40, 40, '#06B6D4', '#1a3a7a');

        self::assertStringContainsString('/ShadingType 3', $pdf->output());
    }

    #[Test]
    public function ellipse_fill_only_uses_the_fill_operator(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->ellipse(50, 50, 30, 15, fill: '#00FF00');

        $out = $pdf->output();

        self::assertStringContainsString("f\n", $out); // fill, no stroke
        self::assertStringContainsString('0.000 1.000 0.000 rg', $out);
    }
}
