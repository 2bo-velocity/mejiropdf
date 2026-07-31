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
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\BorderBox;
use MejiroPDF\Layout\Borders;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Layout\Padding;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(BorderBox::class)]
#[CoversClass(Borders::class)]
#[CoversClass(BorderStyle::class)]
#[CoversClass(Padding::class)]
final class DocumentBoxTest extends TestCase
{
    private function document(): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        return $pdf;
    }

    #[Test]
    public function it_frames_text_and_returns_the_outer_height(): void
    {
        $pdf = $this->document();
        $box = new BorderBox(
            'Hello',
            borders: Borders::all(new BorderStyle(0.5)),
            padding: Padding::all(4.0),
            backgroundColor: '#EEEEEE',
        );

        $height = $pdf->drawBox($box, 20, 20, 120, font: StandardFont::HELVETICA, size: 12.0);
        $out = $pdf->output();

        // One 12 pt line (1.6 line-height) in mm plus 4 mm padding top and bottom.
        self::assertEqualsWithDelta(12.0 * 1.6 / (72.0 / 25.4) + 8.0, $height, 0.05);
        self::assertStringContainsString('(H) Tj', $out);                 // text drawn
        self::assertStringContainsString('0.933 0.933 0.933 rg', $out);   // #EEEEEE fill
        self::assertSame(4, \substr_count($out, "l\nS"));                 // four solid edges
    }

    #[Test]
    public function the_box_grows_with_its_content(): void
    {
        $borders = Borders::all(new BorderStyle());

        $short = $this->document()->drawBox(new BorderBox('One line'), 20, 20, 120, font: StandardFont::HELVETICA, size: 11.0);
        $long = $this->document()->drawBox(
            new BorderBox(str_repeat('word ', 60), borders: $borders),
            20,
            20,
            60,
            font: StandardFont::HELVETICA,
            size: 11.0,
        );

        self::assertGreaterThan($short, $long);
    }

    #[Test]
    public function a_double_border_strokes_two_lines_per_edge(): void
    {
        $pdf = $this->document();
        $pdf->drawBox(
            new BorderBox('x', borders: Borders::all(new BorderStyle(0.4, '#000000', LineStyle::DOUBLE))),
            20,
            20,
            120,
            font: StandardFont::HELVETICA,
            size: 11.0,
        );

        self::assertSame(8, \substr_count($pdf->output(), "l\nS")); // 4 edges × 2 lines
    }

    #[Test]
    public function it_rejects_vertical_writing(): void
    {
        $this->expectException(\LogicException::class);

        $this->document()->drawBox(
            new BorderBox('x'),
            20,
            20,
            120,
            font: StandardFont::HELVETICA,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
    }

    #[Test]
    public function it_rejects_a_width_smaller_than_the_padding(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->document()->drawBox(
            new BorderBox('x', padding: Padding::all(10.0)),
            20,
            20,
            15, // 15 − 10 − 10 < 0
            font: StandardFont::HELVETICA,
        );
    }
}
