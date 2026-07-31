<?php

declare(strict_types=1);

/**
 * MejiroPDF — a PDF/A-1b (ISO 19005-1, level B) archival document.
 *
 * PDF/A forbids non-embedded fonts and encryption, so a TrueType font must be
 * set as the default. An sRGB OutputIntent and pdfaid XMP metadata are added
 * automatically.
 *
 *   php examples/pdfa.php [/path/to/font.ttf]
 *
 * Validate conformance with veraPDF:  verapdf --flavour 1b pdfa.pdf
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\TrueTypeFont;

$path = $argv[1] ?? '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
if (!is_readable($path)) {
    fwrite(STDERR, "Font not found: {$path}\nPass a .ttf path as the first argument.\n");
    exit(1);
}

$pdf = new Document(pdfA: true);
$pdf->setDefaultFont(TrueTypeFont::fromFile($path));

$pdf->addPage()
    ->text(20, 30, 'PDF/A-1b archival document', size: 20.0)
    ->text(20, 42, 'Fonts are embedded; colour is tagged with an sRGB output intent.', size: 12.0);

$pdf->save(__DIR__ . '/pdfa.pdf');

echo "Wrote pdfa.pdf\n";
