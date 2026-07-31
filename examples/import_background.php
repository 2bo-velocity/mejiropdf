<?php

declare(strict_types=1);

/**
 * MejiroPDF — import a vector PDF as a page background.
 *
 * A designer's static PDF (letterhead / invoice frame) is imported as a Form
 * XObject; MejiroPDF then streams the dynamic data on top. The source must be a
 * classic-xref, unencrypted PDF (save as PDF 1.4 if in doubt).
 *
 * Run on PHP 8.3+:  php examples/import_background.php [background.pdf]
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;

$background = $argv[1] ?? __DIR__ . '/../tests/fixtures/pdf/vector.pdf';

$pdf = new Document();
$pdf->addPage()
    ->importPageAsBackground($background)   // behind everything
    ->text(60, 40, 'INVOICE', size: 24.0, color: '#1a3a7a')
    ->text(60, 55, 'No. INV-2026-001', size: 12.0)
    ->text(60, 62, 'Date: 2026-07-25', size: 12.0)
    ->text(60, 100, '御中: 株式会社メジロ', font: CidFontJapanese::HEISEI_MIN_W3, size: 12.0);

$pdf->save(__DIR__ . '/import_background.pdf');

echo "Wrote import_background.pdf (dynamic data over an imported vector background).\n";
