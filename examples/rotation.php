<?php

declare(strict_types=1);

/**
 * MejiroPDF — the three ways to put something at an angle.
 *
 *   1. rotation:      one image or text object, turned about its own centre
 *   2. rotated():     a whole group of drawing calls, turned as one piece
 *   3. addPage(rotation:): the sheet itself, turned for display and printing
 *
 * Run on PHP 8.3+:  php examples/rotation.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Table\Column;

$pdf = new Document();
$pdf->addPage()
    ->text(20, 22, 'Rotation', size: 20.0, color: '#1a3a7a');

// 1. A single element. A diagonal watermark is a text object pinned by its
//    centre; the same `rotation` argument turns an image about its box centre.
$pdf->textObject(
    'DRAFT',
    x: 105,
    y: 160,
    width: 120,
    size: 60.0,
    anchor: Anchor::CENTER,
    rotation: 45.0,
);

// 2. A group. The band, its rule and its text keep their positions relative to
//    each other because one transform turns all three. Inside the callback the
//    coordinates are the ones you would have used unturned: a 180 x 22 mm band
//    laid out across the page at (25, 60), turned a quarter turn clockwise about
//    its own top-left corner so it comes to rest down the left edge.
$pdf->rotated(-90.0, x: 25, y: 60, draw: function (Document $pdf): void {
    $pdf->rect(25, 60, 180, 22, fill: '#1a3a7a');
    $pdf->line(29, 76, 201, 76, weight: 0.4, color: '#FFFFFF');
    $pdf->text(31, 72, 'Bound edge: turned as one piece', size: 11.0, color: '#FFFFFF');
});

$pdf->typeset(
    'The band on the left is a rectangle, a rule and a line of text, drawn '
    . 'across the page and turned together. Nothing inside the group knows it '
    . 'is at an angle, which is why the three keep their spacing.',
    x: 40,
    y: 60,
    width: 100,
);

// 3. The sheet. A wide table gets a landscape page marked /Rotate 90, so the
//    reader's page width stays the same as the portrait pages around it. The
//    drawing coordinates below are ordinary landscape ones — /Rotate turns the
//    paper, not the coordinate space.
$pdf->addPage(PageSize::a4(Orientation::LANDSCAPE), rotation: 90);
$pdf->text(15, 20, 'A wide page, presented turned', size: 14.0, color: '#1a3a7a');

$table = $pdf->table(
    [
        new Column('Quarter', 40),
        new Column('Region', 60),
        new Column('Units', 40),
        new Column('Revenue', 45),
    ],
    x: 15,
    y: 30,
    fontSize: 9.0,
)->header(['Quarter', 'Region', 'Units', 'Revenue']);

foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $i => $quarter) {
    $table->row([$quarter, 'Kanto', (string) (1200 + $i * 310), '$' . \number_format(48_000 + $i * 9_100)]);
}

$pdf->save(__DIR__ . '/rotation.pdf');

echo "Wrote rotation.pdf.\n";
