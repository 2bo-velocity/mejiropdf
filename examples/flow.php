<?php

declare(strict_types=1);

/**
 * MejiroPDF — flowing typeset blocks: each flow() returns the height it used,
 * so the next block is placed directly beneath the previous one.
 *
 * Run on PHP 8.3+:  php examples/flow.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Layout\BorderBox;
use MejiroPDF\Layout\Borders;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Layout\Padding;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TypesetterOptions;

$pdf = new Document(credit: CreditOptions::disabled());
$pdf->addPage();

$x = 25.0;
$width = 160.0;
$y = 25.0;

// Centred title, then paragraphs justified and stacked automatically.
$y += $pdf->flow(
    '**MejiroPDF** — Flowing Layout',
    $x,
    $y,
    $width,
    font: StandardFont::HELVETICA,
    size: 20.0,
    options: new TypesetterOptions(align: TextAlign::CENTER),
) + 6.0;

$paragraphs = [
    '[T]{.dropcap}his paragraph opens with a drop cap: the first letter is set '
        . 'three lines tall and the following lines are indented beside it, then '
        . 'flow() still reports the height so the next block starts below.',
    'Each block is justified to the column edges, wraps on word boundaries, and '
        . 'turns "straight quotes" and -- dashes into typographic ones. Add as '
        . 'many blocks as you like and they simply stack down the page.',
    'When a block would run past the page, higher layers can compare the '
        . 'returned height against the remaining space and break to a new page.',
];

foreach ($paragraphs as $paragraph) {
    $y += $pdf->flow(
        $paragraph,
        $x,
        $y,
        $width,
        font: StandardFont::HELVETICA,
        size: 11.0,
        options: new TypesetterOptions(align: TextAlign::JUSTIFY),
    ) + 4.0;
}

// A note box whose height follows its (wrapping) text, with a coloured fill,
// a left accent rule and a double bottom rule.
$y += 2.0;
$y += $pdf->drawBox(
    new BorderBox(
        '**Note.** A BorderBox measures its text and frames it, so the border '
            . 'never crosses the words no matter how many lines they wrap to.',
        borders: new Borders(
            left: new BorderStyle(1.2, '#1A56DB'),
            bottom: new BorderStyle(1.0, '#94A3B8', LineStyle::DOUBLE),
        ),
        padding: new Padding(top: 4, right: 6, bottom: 4, left: 8),
        backgroundColor: '#F1F5F9',
    ),
    $x,
    $y,
    $width,
    font: StandardFont::HELVETICA,
    size: 11.0,
);

// A diagonal watermark: a centred, rotated TextObject pinned to the page middle.
$pdf->textObject(
    '[DRAFT]{color=#E2E8F0}',
    105,
    150,
    120,
    font: StandardFont::HELVETICA,
    size: 64.0,
    options: new TypesetterOptions(align: TextAlign::CENTER),
    anchor: Anchor::CENTER,
    rotation: 45.0,
);

$pdf->save(__DIR__ . '/flow.pdf');

echo "Wrote flow.pdf (stacked blocks, a BorderBox, and a rotated watermark).\n";
