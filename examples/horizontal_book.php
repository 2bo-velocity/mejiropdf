<?php

declare(strict_types=1);

/**
 * MejiroPDF — a Japanese horizontally-set book: front matter numbered i, ii,
 * iii and a body that starts again at 1, with chapters, running heads and
 * fore-edge folios.
 *
 * Run on PHP 8.3+:  php examples/horizontal_book.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Layout\Section;
use MejiroPDF\Page\BindingEdge;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Page\NumberStyle;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Page\RunningHeadOptions;
use MejiroPDF\Text\FootnoteStyle;
use MejiroPDF\Text\JapaneseSpacing;
use MejiroPDF\Text\TypesetterOptions;

$pdf = new Document(
    defaultSize: PageSize::a5(Orientation::PORTRAIT),
    credit: CreditOptions::disabled(),
    info: new DocumentInfo(
        title: '横組みの組版について',
        author: '坪上 悠介',
        subject: 'A horizontally-set Japanese book',
        keywords: '組版, 横組み',
        language: 'ja',
    ),
);
$pdf->setDefaultFont(CidFontJapanese::HEISEI_MIN_W3);
// Verso: the chapter. Recto: the section — falling back to the chapter until a
// subheading has been met, so the front matter is unaffected.
$pdf->setRunningHead(new RunningHeadOptions(fontSize: 7.5));
// Roman for the front matter, on the fore-edge so a thumb finds it.
$pdf->setPageNumbers(new PageNumberOptions(
    format: '{PAGE}',
    alignment: BindingEdge::OUTER,
    fontSize: 8.0,
    style: NumberStyle::ROMAN_LOWER,
));

$region = FlowRegion::horizontal(x: 20.0, topY: 22.0, width: 108.0, bottomY: 190.0);
// A quarter em between 和字 and Latin, and closing punctuation that gives up
// its empty half. Opt-in, because switching it on moves the text of every
// document that was set without it.
$options = new TypesetterOptions(japaneseSpacing: JapaneseSpacing::JIS_X_4051);

// --- Front matter: numbered i, ii, iii -------------------------------------
$pdf->addPage();
$pdf->section(
    new Section('まえがき', \implode("\n\n", \array_fill(0, 6,
        '　本書は横組みの日本語書籍を対象に、ページ装置と組版の作法をまとめたものである。'
        . '前付けはローマ数字で数え、本文で改めて一から数え直す。こうしておけば、'
        . 'どちらに材料を足しても他方の番号が動かない。')),
        bookmarkLevel: 0),
    $region,
    size: 9.5,
    options: $options,
    gutter: 3.0,
);

// --- Body: numbering starts again at 1 in Arabic ---------------------------
$pdf->restartPageNumbering(NumberStyle::ARABIC);

// The paragraph describes the quarter-em space between Japanese and Latin, and
// now has it — compare "Ruby on Rails" against the same text set with
// JapaneseSpacing::NONE.
$paragraph = '　和文と欧文が隣り合うところには四分アキを入れる((四分アキは JIS X 4051 の規定である。))。'
    . 'たとえば Ruby on Rails のような語が本文に混ざるとき、'
    . 'アキが無いと文字が詰まって読みにくい。約物の詰めも同じ理屈である。';
// A `.subhead` paragraph is held to the text it introduces — it never ends a
// page alone — and names the recto's running head. The verso keeps the chapter,
// so an opened book tells the reader both where they are and how they got there.
$body = \implode("\n\n", [
    ...\array_fill(0, 4, $paragraph),
    '[一　四分アキ]{.subhead}',
    ...\array_fill(0, 5, $paragraph),
    '[二　約物の詰め]{.subhead}',
    ...\array_fill(0, 5, $paragraph),
]);

foreach (['第一章　版面と余白', '第二章　和欧混植', '第三章　脚注と柱'] as $title) {
    $pdf->section(
        new Section($title, $body, titleSize: 12.0),
        $region,
        size: 9.5,
        options: $options,
        gutter: 3.0,
        // The band's depth is worked out per page: the notes are only known
        // once the page is full, so the page is laid out again against them.
        footnotes: new FootnoteStyle(),
    );
}

$pdf->save(__DIR__ . '/horizontal_book.pdf');

\printf(
    "Wrote horizontal_book.pdf (%d pages: front matter in roman, body restarting at 1).\n",
    $pdf->pageCount(),
);
