<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Integration;

use MejiroPDF\Document;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Tests\Support\ExternalCommand;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Validates a MejiroPDF PDF/A-1b document against the reference validator,
 * veraPDF, so conformance is proven by an independent authority rather than by
 * asserting on our own byte output. Skips when `verapdf` (or a TrueType test
 * font) is unavailable.
 */
#[Group('integration')]
final class PdfAConformanceTest extends TestCase
{
    use ExternalCommand;
    use FindFont;

    protected function setUp(): void
    {
        $this->requireCommand('verapdf');
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles();
    }

    #[Test]
    public function a_generated_document_passes_verapdf_pdfa_1b_validation(): void
    {
        $pdf = new Document(pdfA: true);
        $pdf->setDefaultFont(TrueTypeFont::fromFile($this->findTrueTypeFont()));
        $pdf->addPage()
            ->text(20, 30, 'PDF/A-1b archival document', size: 16.0)
            ->text(20, 45, 'Embedded fonts, sRGB output intent, pdfaid XMP metadata.', size: 11.0);

        $file = $this->tempFile();
        $pdf->save($file);

        // --nonpdfext: our temp file has no .pdf extension.
        [$code, $report] = $this->runCommand(
            'verapdf --flavour 1b --format text --nonpdfext ' . \escapeshellarg($file),
        );

        // veraPDF exits 0 and prints "PASS <file>" for a compliant document.
        self::assertSame(0, $code, "veraPDF reported non-conformance:\n{$report}");
        self::assertStringContainsString('PASS', $report, "Unexpected veraPDF report:\n{$report}");
    }

    #[Test]
    public function authored_metadata_keeps_the_info_dictionary_and_xmp_in_agreement(): void
    {
        // PDF/A-1b requires any property carried in both the info dictionary
        // and the XMP packet to hold the same value. The engine writes them
        // from one source; this is the independent check that it worked —
        // including the licence marker, which shares /Keywords with the author.
        $pdf = new Document(
            pdfA: true,
            credit: CreditOptions::disabled(),
            info: new DocumentInfo(
                title: 'Archival Record',
                author: 'A Novelist',
                subject: 'Metadata agreement',
                keywords: 'archive, metadata',
                language: 'en',
            ),
        );
        $pdf->setDefaultFont(TrueTypeFont::fromFile($this->findTrueTypeFont()));
        $pdf->addPage()->text(20, 30, 'Info and XMP agree.', size: 14.0);

        $file = $this->tempFile();
        $pdf->save($file);

        [$code, $report] = $this->runCommand(
            'verapdf --flavour 1b --format text --nonpdfext ' . \escapeshellarg($file),
        );

        self::assertSame(0, $code, "veraPDF reported non-conformance:\n{$report}");
        self::assertStringContainsString('PASS', $report, "Unexpected veraPDF report:\n{$report}");
    }
}
