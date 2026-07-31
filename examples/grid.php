<?php

declare(strict_types=1);

/**
 * MejiroPDF — a content-sized GridTable: streamed rows, zebra striping, a
 * right-aligned amount column, a category column that spans its rows, and a
 * DOUBLE total rule.
 *
 * Run on PHP 8.3+:  php examples/grid.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\Borders;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\Cell;
use MejiroPDF\Layout\GridColumn;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TextAlign;

$pdf = new Document(credit: CreditOptions::disabled());
$pdf->addPage();

$pdf->text(25, 22, 'Invoice INV-2026-001', size: 16.0, color: '#1A3A7A');

$grid = $pdf->grid(
    [
        new GridColumn(26, TextAlign::CENTER), // category (spans its rows)
        new GridColumn(28),                    // code
        new GridColumn(56),                    // description
        new GridColumn(18, TextAlign::CENTER), // qty
        new GridColumn(30, TextAlign::END),    // amount
    ],
    25,
    30,
    font: StandardFont::HELVETICA,
    size: 10.0,
);

$grid->headerFill('#1A3A7A')
    ->zebra('#FFFFFF', '#F1F5F9')
    ->header([
        new Cell('**Category**', backgroundColor: null),
        '**Code**',
        '**Description**',
        '**Qty**',
        '**Amount**',
    ]);

// The category cell covers the two rows under it: the rows that follow leave
// its column alone, and it is drawn once their height is known.
$grid->row([
    new Cell('**Software**', backgroundColor: '#E8EEF9', rowspan: 2),
    'ITEM-001',
    'Modern PDF engine licence',
    '1',
    '120,000',
])
    ->row(['ITEM-002', 'Studio designer, per seat, wrapping description that grows the row', '3', '90,000'])
    ->row([
        new Cell('**Services**', backgroundColor: '#E8EEF9'),
        'ITEM-003',
        'Priority support (annual)',
        '1',
        '60,000',
    ]);

// Total row: the label spans the first four columns (colspan), amount
// right-aligned, both with a double top rule.
$double = new BorderStyle(0.5, '#1A3A7A', LineStyle::DOUBLE);
$grid->row([
    new Cell('**Total (JPY)**', align: TextAlign::END, borders: new Borders(top: $double), colspan: 4),
    new Cell('**270,000**', align: TextAlign::END, borders: new Borders(top: $double)),
]);

// Draws any span still waiting for rows that never came. Harmless here, where
// every span was filled, but it costs nothing to always close.
$grid->close();

$pdf->save(__DIR__ . '/grid.pdf');

echo "Wrote grid.pdf (streamed table: zebra, alignment, wrapping rows, double total rule).\n";
