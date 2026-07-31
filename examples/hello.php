<?php

declare(strict_types=1);

/**
 * MejiroPDF — quick-start example.
 *
 * Run on PHP 8.3+:  php examples/hello.php  (writes hello.pdf next to this file)
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;

$pdf = new Document();

// Page 1 — A4 portrait (the default).
$pdf->addPage()
    ->text(20, 30, 'Hello, MejiroPDF', size: 28.0, color: '#06B6D4')
    ->text(20, 42, 'A modern, stream-first PDF engine for PHP 8.3+.', size: 12.0)
    ->line(20, 46, 190, 46, weight: 0.4, color: '#333333')
    ->text(20, 58, 'Times set in Times-Roman.', font: StandardFont::TIMES_ROMAN, size: 14.0)
    ->rect(20, 66, 60, 24, weight: 0.5, stroke: '#333333', fill: '#F0F9FF');

// Page 2 — A4 landscape.
$pdf->addPage(PageSize::a4(Orientation::LANDSCAPE))
    ->text(20, 25, 'Page 2 — landscape', size: 20.0);

$pdf->save(__DIR__ . '/hello.pdf');

echo "Wrote hello.pdf ({$pdf->pageCount()} pages).\n";
