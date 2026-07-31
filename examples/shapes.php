<?php

declare(strict_types=1);

/**
 * MejiroPDF — shapes, gradients and styled tables.
 *
 * Run on PHP 8.3+:  php examples/shapes.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Table\Column;
use MejiroPDF\Table\TableBorder;

$pdf = new Document();
$pdf->addPage()
    ->text(20, 22, 'Shapes & gradients', size: 20.0, color: '#1a3a7a')

    // Vector shapes (mm; Bézier-approximated).
    ->circle(40, 55, 18, weight: 0.6, stroke: '#1a3a7a', fill: '#06B6D4')
    ->ellipse(100, 55, 28, 14, fill: '#22aa55')
    ->roundedRect(150, 40, 40, 30, 6, weight: 0.6, stroke: '#333333', fill: '#F0F9FF')

    // Gradients.
    ->linearGradient(20, 85, 170, 16, '#06B6D4', '#1a3a7a')
    ->text(24, 95, 'linear gradient', size: 11.0, color: '#FFFFFF')
    ->radialGradient(20, 108, 40, 40, '#FFFFFF', '#B00020');

// A zebra-striped table with horizontal rules only.
$table = $pdf->table(
    [
        new Column('#', 15, Alignment::CENTER),
        new Column('Item', 90, Alignment::LEFT),
        new Column('Amount', 35, Alignment::RIGHT),
    ],
    x: 70,
    y: 110,
    fontSize: 9.0,
)
    ->zebra('#EEF3F8', null)
    ->borders(TableBorder::HORIZONTAL)
    ->header(['#', 'Item', 'Amount']);

for ($i = 1; $i <= 8; $i++) {
    $table->row([(string) $i, "Line item {$i}", '$' . \number_format($i * 123.45, 2)]);
}

$pdf->save(__DIR__ . '/shapes.pdf');

echo "Wrote shapes.pdf.\n";
