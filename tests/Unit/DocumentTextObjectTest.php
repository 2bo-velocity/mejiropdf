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
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentTextObjectTest extends TestCase
{
    private function document(): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        return $pdf;
    }

    #[Test]
    public function it_wraps_the_text_in_an_identity_transform_when_unrotated(): void
    {
        $pdf = $this->document();
        $pdf->textObject('Hi', 50, 50, 100, font: StandardFont::HELVETICA, size: 20.0);

        $out = $pdf->output();

        self::assertMatchesRegularExpression("~q\n1\\.00000 0\\.00000 .* cm~", $out);
        self::assertStringContainsString('(H) Tj', $out);
        self::assertStringContainsString("Q\n", $out);
    }

    #[Test]
    public function rotation_sets_the_cosine_sine_matrix(): void
    {
        $pdf = $this->document();
        $pdf->textObject('Hi', 50, 50, 100, font: StandardFont::HELVETICA, size: 20.0, rotation: 90.0);

        // cos90=0, sin90=1 → the standard quarter-turn matrix.
        self::assertStringContainsString('0.00000 1.00000 -1.00000 0.00000', $pdf->output());
    }

    #[Test]
    public function centering_the_anchor_offsets_the_translation(): void
    {
        // At x=50 with a 100 mm box, CENTER puts the box centre on x=50, so the
        // horizontal translation is zero.
        $pdf = $this->document();
        $pdf->textObject('Hi', 50, 50, 100, font: StandardFont::HELVETICA, size: 20.0, anchor: Anchor::CENTER);

        self::assertMatchesRegularExpression('~ 0\.00 [\d.]+ cm~', $pdf->output());
    }

    #[Test]
    public function it_rejects_vertical_writing(): void
    {
        $this->expectException(\LogicException::class);

        $this->document()->textObject(
            'x',
            50,
            50,
            100,
            font: StandardFont::HELVETICA,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
    }
}
