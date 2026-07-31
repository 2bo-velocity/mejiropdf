<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Block;

use MejiroPDF\Block\Block;
use MejiroPDF\Block\BlockColumn;
use MejiroPDF\Block\BlockRenderer;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Block\PositionMode;
use MejiroPDF\Document;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\ElementPainter;
use MejiroPDF\Template\TemplateText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockRenderer::class)]
#[CoversClass(Block::class)]
#[CoversClass(BlockColumn::class)]
#[CoversClass(ElementSet::class)]
#[CoversClass(ElementPainter::class)]
final class BlockRendererTest extends TestCase
{
    /** Millimetres → points, as the engine converts them. */
    private const float MM = 2.834645669;

    /**
     * An element set holding one label at the block's own origin.
     */
    private function label(string $text, float $height): ElementSet
    {
        return new ElementSet(
            texts: [new TemplateText($text, 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
            height: $height,
        );
    }

    /**
     * @param list<Block> $blocks
     */
    private function template(array $blocks, float $marginTop = 20.0, float $marginBottom = 20.0): CompiledTemplate
    {
        $a4 = PageSize::a4();

        return new CompiledTemplate(
            version: 1,
            flags: 0,
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [],
            bindings: [],
            blocks: $blocks,
            marginTop: $marginTop,
            marginRight: 15.0,
            marginBottom: $marginBottom,
            marginLeft: 15.0,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(CompiledTemplate $template, array $data = []): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($template, $data);

        return $pdf;
    }

    /**
     * Where each drawn run starts, in draw order, as [x mm, top mm].
     *
     * The typesetter positions every glyph individually, so the content stream
     * carries one `Tm` per character. A run therefore begins wherever the
     * baseline changes, the pen jumps back to the left, or it skips further
     * right than any single glyph of this size could advance — which is what
     * two columns sharing a baseline look like.
     *
     * @return list<array{float, float}>
     */
    private function runs(string $pdf): array
    {
        \preg_match_all('/1 0 [\d.-]+ 1 ([\d.-]+) ([\d.-]+) Tm/', $pdf, $m, \PREG_SET_ORDER);

        $runs = [];
        $lastX = null;
        $lastY = null;
        foreach ($m as $glyph) {
            $x = (float) $glyph[1];
            $y = (float) $glyph[2];
            $gap = $lastX === null ? 0.0 : $x - $lastX;
            if ($lastY === null || $y !== $lastY || $gap <= 0.0 || $gap > 2.0 * 10.0) {
                // Undo the 0.8 em ascent the typesetter places the baseline at.
                $runs[] = [$x / self::MM, (PageSize::a4()->height - $y - 10.0 * 0.8) / self::MM];
            }
            $lastX = $x;
            $lastY = $y;
        }

        return $runs;
    }

    /** The Y (mm from the page top) of the n-th drawn run. */
    private function topMm(string $pdf, int $index): float
    {
        $runs = $this->runs($pdf);
        self::assertArrayHasKey($index, $runs, 'Fewer runs were drawn than expected.');

        return $runs[$index][1];
    }

    /** The X (mm from the page left) of the n-th drawn run. */
    private function leftMm(string $pdf, int $index): float
    {
        $runs = $this->runs($pdf);
        self::assertArrayHasKey($index, $runs, 'Fewer runs were drawn than expected.');

        return $runs[$index][0];
    }

    #[Test]
    public function flow_blocks_stack_from_the_top_margin(): void
    {
        $pdf = $this->render($this->template([
            Block::single('a', $this->label('Alpha', 30.0)),
            Block::single('b', $this->label('Bravo', 25.0)),
            Block::single('c', $this->label('Charlie', 0.0)),
        ]));

        $out = $pdf->output();

        self::assertEqualsWithDelta(20.0, $this->topMm($out, 0), 0.1, 'The first block starts at the top margin.');
        self::assertEqualsWithDelta(50.0, $this->topMm($out, 1), 0.1, 'The second follows the first.');
        self::assertEqualsWithDelta(75.0, $this->topMm($out, 2), 0.1, 'The third follows the second.');
    }

    #[Test]
    public function a_hidden_block_collapses_and_the_rest_moves_up(): void
    {
        $blocks = [
            Block::single('header', $this->label('Header', 30.0)),
            Block::single('bank', $this->label('Bank', 40.0), condition: "props.method === 'bank'"),
            Block::single('notes', $this->label('Notes', 20.0)),
        ];

        $shown = $this->render($this->template($blocks), ['props' => ['method' => 'bank']])->output();
        $hidden = $this->render($this->template($blocks), ['props' => ['method' => 'card']])->output();

        self::assertEqualsWithDelta(50.0, $this->topMm($shown, 1), 0.1, 'Bank sits under the header.');
        self::assertEqualsWithDelta(90.0, $this->topMm($shown, 2), 0.1, 'Notes clears the bank panel.');

        // With the bank panel gone, Notes takes its place — no gap is left.
        self::assertCount(2, $this->runs($hidden));
        self::assertEqualsWithDelta(50.0, $this->topMm($hidden, 1), 0.1, 'Notes moved up into the vacated space.');
    }

    #[Test]
    public function an_absolute_block_does_not_move_the_cursor(): void
    {
        $pdf = $this->render($this->template([
            Block::single('header', $this->label('Header', 30.0)),
            Block::single('seal', $this->label('Seal', 25.0), positionMode: PositionMode::ABSOLUTE, x: 160.0, y: 240.0),
            Block::single('notes', $this->label('Notes', 20.0)),
        ]));

        $out = $pdf->output();

        self::assertEqualsWithDelta(240.0, $this->topMm($out, 1), 0.1, 'The seal sits at its own coordinates.');
        self::assertEqualsWithDelta(50.0, $this->topMm($out, 2), 0.1, 'Notes follows the header, not the seal.');
    }

    #[Test]
    public function a_repeater_draws_one_element_set_per_row(): void
    {
        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    item: new ElementSet(
                        bindings: [new DynamicBinding('item.name', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                ),
            ]),
            ['items' => [['name' => 'Alpha'], ['name' => 'Bravo'], ['name' => 'Charlie']]],
        );

        $out = $pdf->output();

        self::assertStringContainsString('(A) Tj', $out);
        self::assertStringContainsString('(B) Tj', $out);
        self::assertStringContainsString('(C) Tj', $out);
        self::assertEqualsWithDelta(20.0, $this->topMm($out, 0), 0.1);
        self::assertEqualsWithDelta(28.0, $this->topMm($out, 1), 0.1, 'Rows advance by the item height.');
        self::assertEqualsWithDelta(36.0, $this->topMm($out, 2), 0.1);
    }

    #[Test]
    public function a_repeater_row_can_address_its_record_without_the_item_prefix(): void
    {
        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'rows',
                    item: new ElementSet(
                        bindings: [new DynamicBinding('label', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                ),
            ]),
            ['rows' => [['label' => 'Zulu']]],
        );

        self::assertStringContainsString('(Z) Tj', $pdf->output());
    }

    #[Test]
    public function a_repeater_paginates_and_restates_its_heading(): void
    {
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $rows[] = ['name' => 'Row'];
        }

        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    header: $this->label('Heading', 10.0),
                    item: new ElementSet(
                        texts: [new TemplateText('Row', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                ),
            ]),
            ['items' => $rows],
        );

        $out = $pdf->output();

        self::assertGreaterThan(1, $pdf->pageCount());
        self::assertSame(
            $pdf->pageCount(),
            \substr_count($out, '(H) Tj'),
            'The heading is restated exactly once per page.',
        );
    }

    #[Test]
    public function the_heading_can_be_told_not_to_repeat(): void
    {
        $rows = \array_fill(0, 60, ['name' => 'Row']);

        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    header: $this->label('Heading', 10.0),
                    item: new ElementSet(
                        texts: [new TemplateText('Row', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                    repeatHeaderOnPageBreak: false,
                ),
            ]),
            ['items' => $rows],
        );

        self::assertGreaterThan(1, $pdf->pageCount());
        self::assertSame(1, \substr_count($pdf->output(), '(H) Tj'));
    }

    #[Test]
    public function continuation_pages_can_use_a_compact_heading(): void
    {
        $rows = \array_fill(0, 60, ['name' => 'Row']);

        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    header: $this->label('Full', 10.0),
                    item: new ElementSet(
                        texts: [new TemplateText('Row', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                    headerPage2: $this->label('Compact', 6.0),
                ),
            ]),
            ['items' => $rows],
        );

        $out = $pdf->output();

        self::assertSame(1, \substr_count($out, '(F) Tj'), 'The decorated heading appears once.');
        self::assertSame(
            $pdf->pageCount() - 1,
            \substr_count($out, '(C) Tj'),
            'Every continuation page gets the compact one.',
        );
    }

    #[Test]
    public function a_subtotal_is_never_orphaned_at_the_top_of_a_page(): void
    {
        // Sized so the last row lands exactly at the bottom margin: without the
        // Look-Ahead the footer alone would be pushed to the next page.
        $usable = 297.0 - 20.0 - 20.0;              // 257 mm
        $rows = \array_fill(0, (int) ($usable / 8.0), ['name' => 'Row']);

        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    item: new ElementSet(
                        texts: [new TemplateText('Row', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)],
                        height: 8.0,
                    ),
                    footer: $this->label('Subtotal', 12.0),
                ),
            ]),
            ['items' => $rows],
        );

        $out = $pdf->output();
        $runs = $this->runs($out);
        $footerIndex = \count($runs) - 1;

        self::assertGreaterThan(1, $pdf->pageCount());
        // The final row and the subtotal are adjacent, so they share a page.
        self::assertEqualsWithDelta(
            $this->topMm($out, $footerIndex - 1) + 8.0,
            $this->topMm($out, $footerIndex),
            0.1,
            'The subtotal sits directly under the last row it totals.',
        );
    }

    #[Test]
    public function a_repeater_with_no_rows_draws_nothing_but_its_footer(): void
    {
        $pdf = $this->render(
            $this->template([
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    item: new ElementSet(texts: [new TemplateText('Row', 0.0, 0.0, 'Helvetica', 10.0, width: 40.0)], height: 8.0),
                    footer: $this->label('Subtotal', 12.0),
                ),
                Block::single('after', $this->label('After', 10.0)),
            ]),
            ['items' => []],
        );

        $out = $pdf->output();

        self::assertStringNotContainsString('(R) Tj', $out);
        self::assertEqualsWithDelta(20.0, $this->topMm($out, 0), 0.1, 'The subtotal starts at the top margin.');
        self::assertEqualsWithDelta(32.0, $this->topMm($out, 1), 0.1, 'What follows clears it.');
    }

    #[Test]
    public function columns_sit_side_by_side(): void
    {
        $pdf = $this->render($this->template([
            Block::columns('head', [
                new BlockColumn($this->label('Left', 20.0), width: '60%'),
                new BlockColumn($this->label('Right', 20.0), width: '40%'),
            ]),
            Block::single('after', $this->label('After', 10.0)),
        ]));

        $out = $pdf->output();

        // The usable width is 210 − 15 − 15 = 180 mm, so the right column
        // starts 60% of that past the left margin: 15 + 108 = 123 mm.
        self::assertEqualsWithDelta(15.0, $this->leftMm($out, 0), 0.1, 'The first column starts at the left margin.');
        self::assertEqualsWithDelta(123.0, $this->leftMm($out, 1), 0.1, 'The second starts 60% of the usable width in.');
        self::assertEqualsWithDelta($this->topMm($out, 0), $this->topMm($out, 1), 0.1, 'Both share a baseline.');
    }

    #[Test]
    public function the_container_is_as_tall_as_its_tallest_column(): void
    {
        $pdf = $this->render($this->template([
            Block::columns('head', [
                new BlockColumn($this->label('Left', 45.0), width: '50%'),
                new BlockColumn($this->label('Right', 20.0), width: '50%'),
            ]),
            Block::single('after', $this->label('After', 10.0)),
        ]));

        // Without equalHeight the next block would start 20 mm down and collide
        // with the long left-hand column.
        self::assertEqualsWithDelta(65.0, $this->topMm($pdf->output(), 2), 0.1);
    }

    #[Test]
    public function a_hidden_column_hands_its_width_to_an_expanding_sibling(): void
    {
        $blocks = [
            Block::columns('head', [
                new BlockColumn($this->label('Left', 20.0), width: '50%', condition: 'props.showLeft'),
                new BlockColumn($this->label('Right', 20.0), width: '50%'),
            ]),
            Block::single('after', $this->label('After', 10.0)),
        ];

        $hidden = $this->render($this->template($blocks), ['props' => ['showLeft' => false]])->output();

        // The right-hand column is the only survivor, so it takes the left
        // edge that the hidden one vacated.
        self::assertCount(2, $this->runs($hidden), 'Only the survivor and the block after it are drawn.');
        self::assertEqualsWithDelta(15.0, $this->leftMm($hidden, 0), 0.1);
    }

    #[Test]
    public function a_column_that_opts_out_of_expanding_keeps_its_place(): void
    {
        $blocks = [
            Block::columns('head', [
                new BlockColumn($this->label('Left', 20.0), width: '50%', condition: 'props.showLeft'),
                new BlockColumn($this->label('Seal', 20.0), width: '50%', autoExpand: false),
            ]),
        ];

        $hidden = $this->render($this->template($blocks), ['props' => ['showLeft' => false]])->output();

        // With its neighbour gone the seal still starts at the container
        // origin; what it must not do is absorb the vacated width.
        self::assertCount(1, $this->runs($hidden));
        self::assertEqualsWithDelta(15.0, $this->leftMm($hidden, 0), 0.1);
    }

    #[Test]
    public function a_template_without_blocks_renders_exactly_as_before(): void
    {
        $a4 = PageSize::a4();
        $template = new CompiledTemplate(
            version: 1,
            flags: 0,
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Legacy', 20.0, 30.0, 'Helvetica', 12.0)],
            bindings: [],
        );

        $pdf = $this->render($template);

        self::assertSame(1, $pdf->pageCount());
        self::assertStringContainsString('(Legacy) Tj', $pdf->output());
    }
}
