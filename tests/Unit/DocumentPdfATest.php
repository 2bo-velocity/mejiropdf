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
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Security\Encryption;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentPdfATest extends TestCase
{
    use FindFont;

    private function pdfA(): Document
    {
        $pdf = new Document(pdfA: true);
        $pdf->setDefaultFont(TrueTypeFont::fromFile($this->findTrueTypeFont()));

        return $pdf;
    }

    #[Test]
    public function it_emits_the_pdfa_identifier_output_intent_and_id(): void
    {
        $pdf = $this->pdfA();
        $pdf->addPage()->text(20, 30, 'Archived document');

        $out = $pdf->output();

        self::assertStringStartsWith('%PDF-1.4', $out);
        self::assertStringContainsString('<pdfaid:part>1</pdfaid:part>', $out);
        self::assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $out);
        self::assertStringContainsString('/Type /Metadata /Subtype /XML', $out);
        self::assertStringContainsString('/S /GTS_PDFA1', $out);
        self::assertStringContainsString('/DestOutputProfile', $out);
        self::assertMatchesRegularExpression('~/ID \[ <[0-9a-f]{32}> <[0-9a-f]{32}> \]~', $out);
    }

    #[Test]
    public function it_emits_a_cidset_for_the_embedded_subset(): void
    {
        $pdf = $this->pdfA();
        $pdf->addPage()->text(20, 30, 'Archived');

        $out = $pdf->output();

        // ISO 19005-1 6.3.5: subset CIDFonts must declare their present CIDs.
        self::assertStringContainsString('/CIDSet ', $out);
        self::assertMatchesRegularExpression('~/FontFile2 \d+ 0 R /CIDSet \d+ 0 R~', $out);
    }

    #[Test]
    public function the_embedded_credit_glyphs_are_tracked_into_the_subset(): void
    {
        // Regression: the forced credit is drawn with the embedded font in
        // PDF/A mode; its glyphs must be subset in and width-listed (else it
        // renders blank and veraPDF flags a width inconsistency).
        //
        // The baseline is a one-character credit rather than a disabled one:
        // disabling now still draws the (invisible) licence marker, so both
        // documents track credit glyphs and only the character count differs.
        $withCredit = $this->widthEntryCount($this->pdfA());

        $shortCredit = new Document(pdfA: true, credit: new CreditOptions(text: 'x'));
        $shortCredit->setDefaultFont(TrueTypeFont::fromFile($this->findTrueTypeFont()));

        self::assertGreaterThan(
            $this->widthEntryCount($shortCredit),
            $withCredit,
            'The credit line must add its glyphs to the embedded font subset.',
        );
    }

    /**
     * Number of entries in the CIDFont's /W width array of a rendered document.
     */
    private function widthEntryCount(Document $pdf): int
    {
        $pdf->addPage()->text(20, 30, 'Report');
        $out = $pdf->output();
        if (\preg_match('~/W \[(.*?)\] >>~s', $out, $m) !== 1) {
            return 0;
        }

        return \substr_count($m[1], '[');
    }

    #[Test]
    public function non_embedded_fonts_are_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('embedded');

        // Default Helvetica (Standard-14) is not embeddable.
        $pdf = new Document(pdfA: true, credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'x');
        $pdf->output();
    }

    #[Test]
    public function page_numbers_are_rejected(): void
    {
        $pdf = $this->pdfA();
        $pdf->setPageNumbers(new PageNumberOptions());
        $pdf->addPage()->text(20, 30, 'x');

        $this->expectException(\LogicException::class);
        $pdf->output();
    }

    #[Test]
    public function encryption_is_rejected(): void
    {
        $this->expectException(\LogicException::class);

        new Document(pdfA: true, encryption: new Encryption('secret'));
    }
}
