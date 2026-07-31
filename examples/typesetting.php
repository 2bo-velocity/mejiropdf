<?php

declare(strict_types=1);

/**
 * MejiroPDF — the typesetting engine: inline markup, ruby, emphasis dots,
 * decoration, colour/highlight, alignment and Japanese line-breaking.
 *
 * Run on PHP 8.3+:  php examples/typesetting.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TypesetterOptions;

$pdf = new Document(credit: CreditOptions::disabled());
$pdf->addPage();

// Centred Latin heading with mixed emphasis, a link and a highlight.
$pdf->typeset(
    'The **MejiroPDF** *typesetting* engine — [docs](https://mejiropdf.com) and ==highlights==.',
    20,
    25,
    170,
    font: StandardFont::HELVETICA,
    size: 16.0,
    options: new TypesetterOptions(align: TextAlign::CENTER),
);

// Japanese body: ruby, emphasis dots, a coloured attribute span, and automatic
// wrapping with 行頭禁則 / ぶら下げ inside the 90 mm column.
$pdf->typeset(
    "|吾輩《わがはい》は|猫《ねこ》である。名前はまだ《《無い》》。"
        . 'どこで[生れた]{color=#1A56DB}かとんと見当がつかぬ。'
        . 'なんでも|薄暗《うすぐら》いじめじめした所でニャーニャー泣いていた事だけは記憶している。',
    20,
    55,
    90,
    font: CidFontJapanese::HEISEI_MIN_W3,
    size: 12.0,
);

// Vertical right-to-left column (novel preset): (x, y) is the top-right corner
// and the width argument is the column height; columns flow leftward.
$pdf->typeset(
    '|春《はる》はあけぼの。第24段、やうやう白くなりゆく|山際《やまぎは》、'
        . 'すこしあかりて、紫だちたる雲のほそくたなびきたる。',
    195,
    150,
    120,
    font: CidFontJapanese::HEISEI_MIN_W3,
    size: 12.0,
    options: TypesetterOptions::novel(),
);

$pdf->save(__DIR__ . '/typesetting.pdf');

echo "Wrote typesetting.pdf (ruby, emphasis dots, decoration, highlight, alignment, vertical).\n";
