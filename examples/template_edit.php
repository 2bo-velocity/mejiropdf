<?php

declare(strict_types=1);

/**
 * MejiroPDF — non-destructive template-PDF text editing with named fields.
 *
 * Opens an existing PDF (a pre-printed form / letterhead) and stamps dynamic
 * text onto it, faithfully preserving the original layout and fonts: the
 * source's own content streams are copied through untouched and MejiroPDF only
 * *overlays* on top. Each new page is sized to match its source page, so the
 * field coordinates line up exactly with the template you designed.
 *
 * The form's fields are described once (position, font, alignment, colour) and
 * then filled per record by name — the same fill path a compiled `.mpb`
 * template uses.
 *
 * Run on PHP 8.3+:  php examples/template_edit.php [template.pdf]
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Template\DynamicBinding;

$template = $argv[1] ?? __DIR__ . '/../tests/fixtures/pdf/vector.pdf';

// Describe the form once: where each named field sits and how it looks.
$fields = [
    new DynamicBinding('title',    x: 60,  y: 40,  fontSize: 24.0, color: '#1a3a7a'),
    new DynamicBinding('number',   x: 60,  y: 55,  fontSize: 12.0),
    new DynamicBinding('date',     x: 60,  y: 62,  fontSize: 12.0),
    new DynamicBinding('customer', x: 60,  y: 100, font: 'HeiseiMin-W3', fontSize: 12.0),
    new DynamicBinding('total',    x: 150, y: 120, fontSize: 12.0, align: Alignment::RIGHT),
];

// One record per source page (here just one).
$records = [
    ['title' => 'INVOICE', 'number' => 'No. INV-2026-001', 'date' => 'Date: 2026-07-25',
        'customer' => '御中: 株式会社メジロ', 'total' => '¥300,000'],
];

$pdf = new Document(credit: CreditOptions::disabled());
$pages = $pdf->importedPageCount($template);

for ($page = 1; $page <= $pages; $page++) {
    $pdf->editTemplatePage($template, $page)
        ->fill($fields, $records[$page - 1] ?? []);
}

$pdf->save(__DIR__ . '/template_edit.pdf');

echo "Wrote template_edit.pdf ({$pages} page(s), named fields filled over the template).\n";
