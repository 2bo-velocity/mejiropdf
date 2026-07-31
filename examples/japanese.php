<?php

declare(strict_types=1);

/**
 * MejiroPDF — Japanese (CJK) example.
 *
 * Uses the built-in HeiseiKakuGo-W5 / HeiseiMin-W3 CIDFonts (no embedding), so
 * Japanese renders in any viewer with the Adobe-Japan1 CMaps.
 *
 * Run on PHP 8.3+ (ext-mbstring):  php examples/japanese.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Table\Column;

$mincho = CidFontJapanese::HEISEI_MIN_W3;
$gothic = CidFontJapanese::HEISEI_KAKU_GO;

$pdf = new Document();
$pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE} / {TOTAL}', alignment: Alignment::RIGHT));

$pdf->addPage()
    ->text(20, 25, '請求書', font: $gothic, size: 24.0, color: '#06B6D4')
    ->text(20, 35, '株式会社メジロ 御中', font: $mincho, size: 13.0)
    ->text(20, 43, 'Invoice No. INV-2026-001', font: StandardFont::HELVETICA, size: 10.0);

$table = $pdf->table(
    [
        new Column('品目', 90, Alignment::LEFT),
        new Column('数量', 25, Alignment::RIGHT),
        new Column('単価', 30, Alignment::RIGHT),
        new Column('金額', 35, Alignment::RIGHT),
    ],
    x: 20,
    y: 55,
    font: $mincho,
    fontSize: 10.0,
)->header(['品目', '数量', '単価', '金額']);

$items = [
    ['高性能PDFライブラリ ライセンス', 2, 500000],
    ['年間サポート契約', 1, 300000],
    ['オンサイト導入支援（宮崎）', 3, 80000],
];

foreach ($items as [$name, $qty, $price]) {
    $table->row([
        $name,
        (string) $qty,
        '¥' . \number_format($price),
        '¥' . \number_format($qty * $price),
    ]);
}

$pdf->save(__DIR__ . '/japanese.pdf');

echo "Wrote japanese.pdf ({$pdf->pageCount()} pages).\n";
