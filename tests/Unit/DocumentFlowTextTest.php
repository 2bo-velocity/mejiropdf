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
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentFlowTextTest extends TestCase
{
    #[Test]
    public function it_paginates_text_that_overflows_the_content_area(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $body = \implode("\n\n", \array_fill(0, 6, \str_repeat('word ', 40)));
        $finalY = $pdf->flowText($body, 20, 20, 170, 55, font: StandardFont::HELVETICA, size: 11.0);

        self::assertGreaterThan(1, $pdf->pageCount());   // spilled onto further pages
        self::assertLessThanOrEqual(55.0, $finalY);      // ends within the last page's area
        self::assertStringContainsString('(w) Tj', $pdf->output());
    }

    #[Test]
    public function short_text_stays_on_one_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $pdf->flowText('A short paragraph.', 20, 20, 170, 280, font: StandardFont::HELVETICA, size: 11.0);

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function it_rejects_vertical_writing(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $pdf->flowText(
            'x',
            20,
            20,
            170,
            280,
            font: StandardFont::HELVETICA,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
    }
}
