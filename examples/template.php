<?php

declare(strict_types=1);

/**
 * MejiroPDF — compiled template (.mpb) example.
 *
 * Normally MejiroPDF Studio compiles a JSON design into a `.mpb` binary. Here we
 * build one with the reference MpbWriter, save it, then load and render it with
 * per-request data — exactly what a server would do.
 *
 * Run on PHP 8.3+ (ext-mbstring):  php examples/template.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateText;

$a4 = PageSize::a4();

// --- 1. Compile a template to .mpb (Studio's job in production) -------------
$mpb = MpbWriter::build(
    width: $a4->width,
    height: $a4->height,
    // Static background: a framed header rule (raw PDF vector operators, points).
    staticVectorOps: "0.6 w 56.69 770 481.89 0 re S\n",
    texts: [
        new TemplateText('請求書', 20, 22, 'HeiseiKakuGo-W5', 26.0, Alignment::LEFT, '#06B6D4'),
        new TemplateText('Invoice', 190, 22, 'Helvetica', 12.0, Alignment::RIGHT),
    ],
    bindings: [
        new DynamicBinding('customer', 20, 40, 'HeiseiMin-W3', 14.0, Alignment::LEFT),
        new DynamicBinding('invoice_no', 190, 40, 'Helvetica', 11.0, Alignment::RIGHT),
        new DynamicBinding('total', 190, 60, 'HeiseiMin-W3', 16.0, Alignment::RIGHT),
    ],
);
\file_put_contents(__DIR__ . '/invoice.mpb', $mpb);

// --- 2. Server side: load the compiled template and pour in data ------------
$template = CompiledTemplateLoader::loadFile(__DIR__ . '/invoice.mpb');

$pdf = new Document();
$pdf->renderTemplate($template, [
    'customer' => '株式会社メジロ 御中',
    'invoice_no' => 'INV-2026-001',
    'total' => '合計 ¥1,300,000',
]);

$pdf->save(__DIR__ . '/template.pdf');

echo "Compiled invoice.mpb (" . \strlen($mpb) . " bytes) and rendered template.pdf.\n";
