<?php

declare(strict_types=1);

/**
 * MejiroPDF — flowText(): multi-paragraph body that auto-paginates, with
 * widow/orphan control keeping paragraphs off the page edges.
 *
 * Run on PHP 8.3+:  php examples/pagination.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TypesetterOptions;

$pdf = new Document(credit: CreditOptions::disabled());
$pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE} / {TOTAL}', alignment: Alignment::CENTER));
$pdf->addPage();

$pdf->flow('The Long Scroll', 25, 20, 160, font: StandardFont::HELVETICA, size: 22.0,
    options: new TypesetterOptions(align: TextAlign::CENTER));

// A dozen justified paragraphs flowed through a fixed column; the engine adds
// pages as needed and avoids leaving single lines at page tops/bottoms.
$lorem = 'MejiroPDF streams each page straight to the output, so this document '
    . 'stays flat in memory no matter how many pages the text runs to. Smart '
    . 'quotes, em--dashes and word wrapping all apply as the body flows.';
$body = \implode("\n\n", \array_fill(0, 14, $lorem));

$pdf->flowText(
    $body,
    25,
    36,
    160,
    280,
    font: StandardFont::HELVETICA,
    size: 11.5,
    options: new TypesetterOptions(align: TextAlign::JUSTIFY),
);

$pdf->save(__DIR__ . '/pagination.pdf');

echo 'Wrote pagination.pdf (' . $pdf->pageCount() . " pages, auto-flowed with widow/orphan control).\n";
