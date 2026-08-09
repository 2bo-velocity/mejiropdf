<?php

declare(strict_types=1);

/**
 * MejiroPDF — flowInto(): a vertical (tategaki) novel poured through a region
 * and auto-paginated, with columns stacking right-to-left across pages.
 *
 * Run on PHP 8.3+:  php examples/vertical_book.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Layout\Section;
use MejiroPDF\Page\BindingEdge;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Page\OpenFit;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Page\RunningHeadOptions;
use MejiroPDF\Text\TypesetterOptions;

$pdf = new Document(
    defaultSize: PageSize::b6(Orientation::PORTRAIT),   // 四六判, the novel size
    credit: CreditOptions::disabled(),
    info: new DocumentInfo(
        title: '吾輩は猫である',
        author: '夏目漱石',
        subject: 'A vertically-set Japanese novel',
        keywords: '小説, 縦書き',
        language: 'ja',
    ),
    // Reading direction is left to ReadingDirection::AUTO: setting the body
    // vertically is already enough to know the spreads run right to left.
    openFit: OpenFit::WIDTH,
);
// On the fore-edge, so the number falls under the reader's thumb on both
// leaves rather than being buried in the gutter every other page. Which side
// that is follows the binding, and a vertical book is bound on the right.
$pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', alignment: BindingEdge::OUTER, fontSize: 8.0));
$pdf->addPage();

// The running head is set in the book's own face — its text is the
// chapter's, not the engine's, so Helvetica would not spell it.
$pdf->setDefaultFont(CidFontJapanese::HEISEI_MIN_W3);
$pdf->setRunningHead(new RunningHeadOptions(fontSize: 7.0));

$paragraph = '　吾輩は猫である。名前はまだ無い。どこで生れたか頓と見当がつかぬ。'
    . '何でも薄暗いじめじめした所でニャーニャー泣いていた事だけは記憶している。'
    . '吾輩はここで始めて人間というものを見た。しかもあとで聞くとそれは書生という'
    . '人間中で一番|獰悪《どうあく》な種族であったそうだ。';
$body = \implode("\n\n", \array_fill(0, 12, $paragraph));

// Each chapter is one act: break the page, set the heading in the first column,
// leave a column clear, register a bookmark and a running head, then pour the
// body — which starts under the heading here and at the top of every page after.
$region = FlowRegion::vertical(rightX: 108.0, topY: 18.0, columnHeight: 146.0, leftX: 14.0);
$finalX = 0.0;
foreach (['第一章　吾輩は猫である', '第二章　書生という人間', '第三章　薄暗い所にて'] as $title) {
    $finalX = $pdf->section(
        new Section($title, $body, titleSize: 12.0),
        $region,
        font: CidFontJapanese::HEISEI_MIN_W3,
        size: 9.5,
        options: TypesetterOptions::novel(),
        // A wider inner margin, so the text does not disappear into the spine.
        gutter: 4.0,
    );
}

$pdf->save(__DIR__ . '/vertical_book.pdf');

\printf(
    "Wrote vertical_book.pdf (%d pages, three chapters flowed right-to-left; last column ended at x = %.1f mm).\n",
    $pdf->pageCount(),
    $finalX,
);
