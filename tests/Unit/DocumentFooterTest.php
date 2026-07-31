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
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageNumberOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentFooterTest extends TestCase
{
    #[Test]
    public function it_prints_exact_totals_across_pages(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE} / {TOTAL}'));

        $pdf->addPage()->text(10, 10, 'one');
        $pdf->addPage()->text(10, 10, 'two');
        $pdf->addPage()->text(10, 10, 'three');

        $bytes = $pdf->output();

        self::assertStringContainsString('(1 / 3) Tj', $bytes);
        self::assertStringContainsString('(2 / 3) Tj', $bytes);
        self::assertStringContainsString('(3 / 3) Tj', $bytes);
    }

    #[Test]
    public function it_skips_the_cover_page_number(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}/{TOTAL}', skipPages: 1));

        $pdf->addPage()->text(10, 10, 'cover');
        $pdf->addPage()->text(10, 10, 'body');

        $bytes = $pdf->output();

        // Cover carries no number; the second page becomes "1/1".
        self::assertStringContainsString('(1/1) Tj', $bytes);
        self::assertStringNotContainsString('(2/', $bytes);
    }

    #[Test]
    public function each_page_gets_its_own_content_and_footer_stream(): void
    {
        $pdf = new Document();
        $pdf->addPage()->text(10, 10, 'x');
        $pdf->addPage()->text(10, 10, 'y');

        $bytes = $pdf->output();

        // Two pages, each referencing a [content footer] pair.
        self::assertSame(2, \substr_count($bytes, '/Type /Page '));
        self::assertSame(2, \substr_count($bytes, ' R ] >>')); // /Contents [ c 0 R f 0 R ]
    }

    #[Test]
    public function credit_and_page_number_coexist(): void
    {
        $pdf = new Document();
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}/{TOTAL}', alignment: Alignment::RIGHT));
        $pdf->addPage()->text(10, 10, 'x');

        $bytes = $pdf->output();

        self::assertStringContainsString('Powered by MejiroPDF', $bytes);
        self::assertStringContainsString('(1/1) Tj', $bytes);
    }
}
