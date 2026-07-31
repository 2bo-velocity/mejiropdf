<?php

declare(strict_types=1);

/**
 * MejiroPDF — the block model: an invoice built from `single`, `repeater` and
 * `columns` blocks that stack themselves down the page.
 *
 * Blocks do not replace free placement — they wrap it. Each block holds an
 * ElementSet whose coordinates are relative to the block's own corner, so the
 * same set can be drawn once, once per row, or on two different pages without
 * any of its numbers changing.
 *
 * Watch what happens when you flip `showBankDetails` to false: the panel is not
 * hidden in place, it is skipped, and everything below moves up to fill the gap.
 *
 * Run on PHP 8.3+:  php examples/blocks.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Block\Block;
use MejiroPDF\Block\BlockColumn;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Block\PositionMode;
use MejiroPDF\Document;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\TemplateText;

$gothic = 'HeiseiKakuGo-W5';
$mincho = 'HeiseiMin-W3';

/** A label at (x, y) millimetres from its block's corner. */
$label = static fn (string $text, float $x, float $y, float $size, string $font, Alignment $align = Alignment::LEFT, float $w = 60.0): TemplateText
    => new TemplateText($text, $x, $y, $font, $size, $align, width: $w);

/** A bound field at (x, y) millimetres from its block's corner. */
$field = static fn (string $key, float $x, float $y, float $size, string $font, Alignment $align = Alignment::LEFT, float $w = 60.0): DynamicBinding
    => new DynamicBinding($key, $x, $y, $font, $size, $align, width: $w);

$blocks = [
    // ── Title, drawn once ────────────────────────────────────────────────────
    Block::single(
        'title',
        new ElementSet(
            texts: [$label('請求書', 0, 0, 20.0, $gothic, w: 80.0)],
            bindings: [$field('invoice_no', 120, 2, 10.0, $mincho, Alignment::RIGHT)],
            height: 18.0,
        ),
        marginBottom: 6.0,
        name: '表題',
    ),

    // ── A seal box, pinned to the sheet rather than to the sequence ───────────
    // Absolute means page coordinates — but on *whichever page is current when
    // the block is reached*. The engine streams, so it cannot go back and stamp
    // page one after the table has run on. Anything that belongs on the first
    // page must therefore be declared before whatever paginates.
    Block::single(
        'seal',
        new ElementSet(texts: [$label('印', 0, 0, 9.0, $gothic, Alignment::CENTER, w: 20.0)], height: 20.0),
        positionMode: PositionMode::ABSOLUTE,
        x: 172.0,
        y: 22.0,
        name: '印鑑枠',
    ),

    // ── Two panels side by side ──────────────────────────────────────────────
    // The right-hand column drops out for a customer with no registered
    // address, and the left one widens to take its place.
    Block::columns('parties', [
        new BlockColumn(
            new ElementSet(
                texts: [$label('請求先', 0, 0, 9.0, $gothic)],
                bindings: [
                    $field('customer.name', 0, 6, 12.0, $mincho, w: 90.0),
                    $field('customer.address', 0, 14, 9.0, $mincho, w: 90.0),
                ],
                height: 26.0,
            ),
            width: '60%',
        ),
        new BlockColumn(
            new ElementSet(
                texts: [$label('発行元', 0, 0, 9.0, $gothic)],
                bindings: [$field('issuer.name', 0, 6, 10.0, $mincho, w: 66.0)],
                height: 26.0,
            ),
            width: '40%',
            condition: 'issuer.name',
        ),
    ], marginBottom: 8.0, name: '宛名'),

    // ── The detail table ─────────────────────────────────────────────────────
    // The heading is restated at the top of every continuation page, and the
    // subtotal is never left stranded above the rows it totals.
    Block::repeater(
        'items',
        dataSource: 'items',
        header: new ElementSet(
            texts: [
                $label('品名', 0, 0, 9.0, $gothic, w: 90.0),
                $label('数量', 100, 0, 9.0, $gothic, Alignment::RIGHT, w: 20.0),
                $label('金額', 130, 0, 9.0, $gothic, Alignment::RIGHT, w: 50.0),
            ],
            height: 8.0,
        ),
        item: new ElementSet(
            bindings: [
                $field('item.name', 0, 0, 9.0, $mincho, w: 90.0),
                $field('item.qty', 100, 0, 9.0, $mincho, Alignment::RIGHT, w: 20.0),
                $field('item.amount', 130, 0, 9.0, $mincho, Alignment::RIGHT, w: 50.0),
            ],
            height: 7.0,
        ),
        footer: new ElementSet(
            texts: [$label('合計', 100, 3, 10.0, $gothic, Alignment::RIGHT, w: 20.0)],
            bindings: [$field('total', 130, 3, 12.0, $gothic, Alignment::RIGHT, w: 50.0)],
            height: 14.0,
        ),
        marginBottom: 8.0,
        name: '明細',
    ),

    // ── A panel that appears only for bank transfers ─────────────────────────
    Block::single(
        'bank',
        new ElementSet(
            texts: [
                $label('お振込先', 0, 0, 9.0, $gothic),
                $label('メジロ銀行 本店営業部 普通 1234567', 0, 6, 9.0, $mincho, w: 120.0),
            ],
            height: 16.0,
        ),
        condition: "props.paymentMethod === 'bank' && props.showBankDetails",
        marginBottom: 6.0,
        name: '振込先',
    ),

    // ── Closing note, which moves up whenever the panel above is skipped ──────
    Block::single(
        'notes',
        new ElementSet(
            texts: [$label('本書は電子的に発行されたものです。', 0, 0, 8.0, $mincho, w: 120.0)],
            height: 10.0,
        ),
        name: '注記',
    ),
];

$a4 = PageSize::a4();
$template = new CompiledTemplate(
    version: 1,
    flags: 0,
    width: $a4->width,
    height: $a4->height,
    staticVectorOps: '',
    texts: [],
    bindings: [],
    blocks: $blocks,
    marginTop: 20.0,
    marginRight: 15.0,
    marginBottom: 20.0,
    marginLeft: 15.0,
);

/** 40 rows, so the table paginates and restates its heading. */
$items = [];
for ($i = 1; $i <= 40; $i++) {
    $items[] = [
        'name' => \sprintf('商品 %02d ― サンプル明細行', $i),
        'qty' => (string) (($i % 5) + 1),
        'amount' => \number_format($i * 1200),
    ];
}

$data = [
    'invoice_no' => 'INV-2026-0042',
    'customer' => ['name' => '株式会社メジロ', 'address' => '東京都千代田区丸の内 1-1-1'],
    'issuer' => ['name' => '2bo-velocity 合同会社'],
    'items' => $items,
    'total' => \number_format(\array_sum(\array_map(static fn (int $i): int => $i * 1200, \range(1, 40)))),
    'props' => ['paymentMethod' => 'bank', 'showBankDetails' => true],
];

$pdf = new Document(credit: CreditOptions::disabled());
$pdf->renderTemplate($template, $data);
$pdf->save(__DIR__ . '/blocks.pdf');
echo "Wrote blocks.pdf ({$pdf->pageCount()} pages: title, two-up header, paginated table, bank panel, notes).\n";

// The same template with the bank panel switched off. Nothing is repositioned
// by hand — the notes simply move up into the space the panel would have used.
$data['props']['showBankDetails'] = false;

$collapsed = new Document(credit: CreditOptions::disabled());
$collapsed->renderTemplate($template, $data);
$collapsed->save(__DIR__ . '/blocks_collapsed.pdf');
echo "Wrote blocks_collapsed.pdf (same template, bank panel skipped, notes moved up).\n";
