<?php

declare(strict_types=1);

/**
 * MejiroPDF — navigation example: table of contents, bookmarks and links.
 *
 * Run on PHP 8.3+:  php examples/report.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\PageNumberOptions;

$sections = [
    'Introduction' => 'Why streaming PDF generation matters.',
    'Architecture' => 'How MejiroPDF keeps memory flat.',
    'Fonts' => 'Standard 14, Japanese CIDFonts, embedded TrueType.',
    'Conclusion' => 'Ship large reports without OOM.',
];

$pdf = new Document();
$pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE} / {TOTAL}', alignment: Alignment::RIGHT));

// A contents page first; it is filled in at the end from the bookmarks below.
$pdf->tableOfContents('Table of Contents');

// One page per section, each bookmarked for the outline + the TOC.
foreach ($sections as $title => $body) {
    $pdf->addPage()
        ->bookmark($title)
        ->text(20, 30, $title, size: 22.0, color: '#06B6D4')
        ->text(20, 42, $body, size: 12.0);
}

// A closing link back to the project site.
$pdf->text(20, 60, 'https://mejiropdf.com', size: 12.0, color: '#0645AD')
    ->link(20, 55, 60, 7, 'https://mejiropdf.com');

$pdf->save(__DIR__ . '/report.pdf');

echo "Wrote report.pdf ({$pdf->pageCount()} pages) with a TOC, bookmarks and a link.\n";
