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
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentTest extends TestCase
{
    #[Test]
    public function it_produces_a_structurally_valid_pdf(): void
    {
        $pdf = new Document();
        $pdf->addPage()->text(20, 30, 'Hello, MejiroPDF', size: 24.0);

        $bytes = $pdf->output();

        self::assertStringStartsWith("%PDF-1.7\n", $bytes);
        self::assertStringContainsString('/Type /Catalog', $bytes);
        self::assertStringContainsString('/Type /Pages', $bytes);
        self::assertStringContainsString('/Type /Page ', $bytes);
        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
        self::assertStringContainsString('startxref', $bytes);
        self::assertStringContainsString('%%EOF', $bytes);
    }

    #[Test]
    public function it_injects_the_credit_by_default(): void
    {
        $pdf = new Document();
        $pdf->addPage()->text(20, 30, 'x');

        self::assertStringContainsString('Powered by MejiroPDF', $pdf->output());
    }

    #[Test]
    public function it_hides_rather_than_removes_the_credit_when_disabled(): void
    {
        // Disabling makes the credit invisible, not absent: the draw command
        // still goes out so a licence audit can extract the string from a
        // published file. See CreditOptions and 10_licensing_credit.md.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'x');
        $out = $pdf->output();

        self::assertStringContainsString('Powered by MejiroPDF [AGPLv3 Community Edition]', $out);
        self::assertStringNotContainsString('0.500 g', $out, 'The visible grey credit must not be drawn.');
    }

    #[Test]
    public function the_hidden_credit_is_painted_in_the_background_colour(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'x');

        // White on white: present in the byte stream, invisible on the page.
        self::assertStringContainsString('1.000 1.000 1.000 rg', $pdf->output());
    }

    #[Test]
    public function the_hidden_credit_blends_into_a_declared_background_colour(): void
    {
        $pdf = new Document(credit: new CreditOptions(enabled: false, markerColor: '#204080'));
        $pdf->addPage()->text(20, 30, 'x');

        self::assertStringContainsString('0.125 0.251 0.502 rg', $pdf->output());
    }

    #[Test]
    public function the_hidden_credit_is_drawn_before_the_page_body(): void
    {
        // PDF paints in stream order, so "behind everything" means "written
        // first". If the marker landed after the body it could paint over it.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'BODYMARKER');
        $out = $pdf->output();

        $marker = \strpos($out, '1.000 1.000 1.000 rg');
        $body = \strpos($out, 'BODYMARKER');

        self::assertIsInt($marker);
        self::assertIsInt($body);
        self::assertLessThan($body, $marker, 'The invisible credit must sit on the back layer.');
    }

    #[Test]
    public function the_licence_marker_is_recorded_in_the_metadata_when_hidden(): void
    {
        $hidden = new Document(credit: CreditOptions::disabled());
        $hidden->addPage()->text(20, 30, 'x');

        $shown = new Document();
        $shown->addPage()->text(20, 30, 'x');

        self::assertStringContainsString('/Keywords', $hidden->output());
        self::assertStringNotContainsString('/Keywords', $shown->output());
    }

    #[Test]
    public function it_counts_pages(): void
    {
        $pdf = new Document();
        $pdf->addPage()->text(10, 10, 'one');
        $pdf->addPage(PageSize::a4(Orientation::LANDSCAPE))->text(10, 10, 'two');

        self::assertSame(2, $pdf->pageCount());
    }

    #[Test]
    public function output_is_idempotent_after_finalize(): void
    {
        $pdf = new Document();
        $pdf->addPage()->text(10, 10, 'once');

        self::assertSame($pdf->output(), $pdf->output());
    }

    #[Test]
    public function it_escapes_parentheses_in_text(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(10, 10, 'a(b)c');

        self::assertStringContainsString('(a\\(b\\)c) Tj', $pdf->output());
    }
}
