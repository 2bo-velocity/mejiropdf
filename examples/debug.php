<?php

declare(strict_types=1);

/**
 * MejiroPDF — the two development-time flags.
 *
 * `debugGuides` outlines every text and image block, so a layout can be read
 * off the page instead of guessed at. `strictCanvas` turns geometry that falls
 * outside the page into an MP-RND-4001 exception rather than something a viewer
 * silently clips. Both are off by default; neither belongs in production.
 *
 * Run on PHP 8.3+:  php examples/debug.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Exception\RenderException;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Page\CreditOptions;

$pdf = new Document(credit: CreditOptions::disabled(), debugGuides: true);
$pdf->addPage();

$pdf->text(25, 22, 'Layout guides', size: 16.0, color: '#1A3A7A');

// Each of these gets a magenta hairline frame showing the box the engine used.
// (Helvetica is a single-byte face, so the text here stays ASCII.)
$pdf->typeset(
    'A typeset block is framed at the width it was given and the height it '
        . 'came to, which is the pair of numbers most layout bugs turn on.',
    25,
    32,
    80,
);

// Framed once per page, so a paragraph that paginates shows where each page's
// share of it landed. Kept short here so the whole example stays on one page.
$pdf->flowText(
    \str_repeat('Flowed text is framed per page. ', 12),
    115,
    32,
    70,
    120,
);

// A rotated object carries its frame inside the same transform, so the guide
// turns with the text rather than boxing it in page space.
$pdf->textObject('DRAFT', 105, 200, 60, size: 28.0, anchor: Anchor::CENTER, rotation: 30.0);

$pdf->save(__DIR__ . '/debug.pdf');
echo "Wrote debug.pdf (text blocks outlined in magenta, images in cyan).\n";

// The other flag: the same mistake, with and without strict canvas checking.
$lenient = new Document(credit: CreditOptions::disabled());
$lenient->addPage()->rect(180, 20, 60, 40); // 30 mm of it hangs off an A4 sheet
echo "Lenient: the overhanging rectangle was accepted and will simply be clipped.\n";

$strict = new Document(credit: CreditOptions::disabled(), strictCanvas: true);
$strict->addPage();

try {
    $strict->rect(180, 20, 60, 40);
} catch (RenderException $e) {
    echo "Strict:  {$e->getMessage()}\n";
    echo "         code={$e->getErrorCode()} element={$e->getContext()['element']}\n";
}
