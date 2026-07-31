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
use MejiroPDF\Import\PdfImportException;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Template\DynamicBinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentImportTest extends TestCase
{
    private function fixture(string $file): string
    {
        return __DIR__ . '/../fixtures/pdf/' . $file;
    }

    #[Test]
    public function it_imports_a_page_as_a_form_xobject_background(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()
            ->importPageAsBackground($this->fixture('vector.pdf'))
            ->text(20, 30, 'Overlay');

        $out = $pdf->output();

        self::assertStringContainsString('/Subtype /Form', $out);
        self::assertStringContainsString('/BBox [ 0 0 595 842 ]', $out);
        self::assertMatchesRegularExpression('~/XObject << /Im0 \d+ 0 R~', $out);
        self::assertStringContainsString('/Im0 Do', $out);
        // The imported vector op survives into the composite.
        self::assertStringContainsString('re f', $out);
    }

    #[Test]
    public function it_copies_referenced_resources_from_the_source(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->importPageAsBackground($this->fixture('with_font.pdf'));

        $out = $pdf->output();

        // The source's font was copied into the composite.
        self::assertStringContainsString('/BaseFont /Helvetica-Bold', $out);
        self::assertStringContainsString('/Subtype /Form', $out);
    }

    #[Test]
    public function it_imports_a_pdf_1_5_xref_stream_source(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->importPageAsBackground($this->fixture('xref_stream.pdf'));

        $out = $pdf->output();

        self::assertStringContainsString('/Subtype /Form', $out);
        self::assertStringContainsString('re f', $out); // imported vector content
    }

    #[Test]
    public function it_places_an_imported_page_at_a_position(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->importPage($this->fixture('vector.pdf'), 1, x: 20, y: 20, width: 80.0);

        $out = $pdf->output();

        self::assertStringContainsString('/Subtype /Form', $out);
        self::assertMatchesRegularExpression('~/Im0 Do~', $out);
        // A cm matrix positions/scales the imported form.
        self::assertMatchesRegularExpression('~q\n[\d.]+ 0 0 [\d.]+ [\d.]+ [\d.]+ cm~', $out);
    }

    #[Test]
    public function it_edits_a_template_page_sized_to_the_source_and_overlays_text(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->editTemplatePage($this->fixture('vector.pdf'))
            ->text(20, 30, 'Filled field');

        $out = $pdf->output();

        // The source page is embedded as a form and painted 1:1 (identity CTM).
        self::assertStringContainsString('/Subtype /Form', $out);
        self::assertStringContainsString('/BBox [ 0 0 595 842 ]', $out);
        // Painted 1:1 with an identity scale (translation is the -MediaBox origin).
        self::assertMatchesRegularExpression('~q\n1 0 0 1 -?0\.0000 -?0\.0000 cm\n/Im0 Do\nQ~', $out);
        // The new page adopts the source page's MediaBox (595 x 842 pt).
        self::assertStringContainsString('/MediaBox [0 0 595.00 842.00]', $out);
        // The imported vector op and the overlaid text both survive.
        self::assertStringContainsString('re f', $out);
        self::assertStringContainsString('(Filled field) Tj', $out);
    }

    #[Test]
    public function it_reports_the_source_page_count(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());

        self::assertSame(1, $pdf->importedPageCount($this->fixture('vector.pdf')));
    }

    #[Test]
    public function it_fills_named_fields_and_skips_missing_or_empty_values(): void
    {
        $fields = [
            new DynamicBinding('title', x: 60, y: 40, fontSize: 24.0, color: '#1a3a7a'),
            new DynamicBinding('number', x: 60, y: 55, fontSize: 12.0),
            new DynamicBinding('note', x: 60, y: 62, fontSize: 12.0),
            new DynamicBinding('unset', x: 60, y: 70, fontSize: 12.0),
        ];

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->editTemplatePage($this->fixture('vector.pdf'))
            ->fill($fields, [
                'title' => 'INVOICE',
                'number' => 'INV-2026-001',
                'note' => '',        // empty: skipped
                // 'unset' absent:   skipped
            ]);

        $out = $pdf->output();

        self::assertStringContainsString('(INVOICE) Tj', $out);
        self::assertStringContainsString('(INV-2026-001) Tj', $out);
        // Field colour is applied.
        self::assertMatchesRegularExpression('~0\.10\d* 0\.22\d* 0\.4\d* rg~', $out);
    }

    #[Test]
    public function it_fills_a_right_aligned_field_at_the_anchor(): void
    {
        $fields = [new DynamicBinding('total', x: 120, y: 40, fontSize: 10.0, align: Alignment::RIGHT)];

        $left = new Document(credit: CreditOptions::disabled());
        $left->editTemplatePage($this->fixture('vector.pdf'))
            ->fill([new DynamicBinding('total', x: 120, y: 40, fontSize: 10.0)], ['total' => '1,234']);

        $right = new Document(credit: CreditOptions::disabled());
        $right->editTemplatePage($this->fixture('vector.pdf'))->fill($fields, ['total' => '1,234']);

        // Right-aligning shifts the text's origin left of the same anchor.
        self::assertNotSame($left->output(), $right->output());
        self::assertStringContainsString('(1,234) Tj', $right->output());
    }

    #[Test]
    public function it_rejects_a_missing_file(): void
    {
        $this->expectException(PdfImportException::class);

        (new Document())->addPage()->importPageAsBackground('/no/such/file.pdf');
    }

    #[Test]
    public function it_rejects_a_missing_file_when_editing_a_template(): void
    {
        $this->expectException(PdfImportException::class);

        (new Document())->editTemplatePage('/no/such/file.pdf');
    }
}
