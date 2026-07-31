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
use MejiroPDF\Block\BlockKind;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Block\PositionMode;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MpbWriter::class)]
#[CoversClass(CompiledTemplateLoader::class)]
#[CoversClass(Block::class)]
#[CoversClass(ElementSet::class)]
final class BlockRoundTripTest extends TestCase
{
    /**
     * @param list<Block> $blocks
     */
    private function reload(array $blocks): CompiledTemplate
    {
        $a4 = PageSize::a4();

        return CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            blocks: $blocks,
            margin: ['top' => 20.0, 'right' => 15.0, 'bottom' => 25.0, 'left' => 12.0],
        ));
    }

    #[Test]
    public function a_single_block_survives_the_round_trip(): void
    {
        $template = $this->reload([
            Block::single(
                'header',
                new ElementSet(
                    texts: [new TemplateText('請求書', 5.0, 3.0, 'HeiseiKakuGo-W5', 18.0, width: 60.0)],
                    height: 30.0,
                ),
                condition: 'props.showHeader',
                x: 4.0,
                marginBottom: 6.0,
                name: '表題',
            ),
        ]);

        self::assertCount(1, $template->blocks);
        $block = $template->blocks[0];

        self::assertSame('header', $block->id);
        self::assertSame('表題', $block->name);
        self::assertSame(BlockKind::SINGLE, $block->kind);
        self::assertSame(PositionMode::FLOW, $block->positionMode);
        self::assertSame('props.showHeader', $block->condition);
        self::assertSame(4.0, $block->x);
        self::assertSame(6.0, $block->marginBottom);
        self::assertSame(30.0, $block->elements->height);
        self::assertSame('請求書', $block->elements->texts[0]->text);
        self::assertSame(60.0, $block->elements->texts[0]->width);
    }

    #[Test]
    public function page_margins_survive_the_round_trip(): void
    {
        $template = $this->reload([Block::single('a', new ElementSet(height: 10.0))]);

        self::assertSame(20.0, $template->marginTop);
        self::assertSame(15.0, $template->marginRight);
        self::assertSame(25.0, $template->marginBottom);
        self::assertSame(12.0, $template->marginLeft);
    }

    #[Test]
    public function a_repeater_keeps_its_three_templates(): void
    {
        $template = $this->reload([
            Block::repeater(
                'items',
                dataSource: 'invoice.items',
                item: new ElementSet(
                    bindings: [new DynamicBinding('item.name', 0.0, 0.0, 'Helvetica', 9.0, width: 80.0)],
                    height: 7.0,
                ),
                header: new ElementSet(texts: [new TemplateText('品名', 0, 0, 'HeiseiMin-W3', 9.0)], height: 8.0),
                footer: new ElementSet(texts: [new TemplateText('小計', 0, 0, 'HeiseiMin-W3', 9.0)], height: 12.0),
                headerPage2: new ElementSet(texts: [new TemplateText('続き', 0, 0, 'HeiseiMin-W3', 8.0)], height: 5.0),
                repeatHeaderOnPageBreak: false,
            ),
        ]);

        $block = $template->blocks[0];

        self::assertSame(BlockKind::REPEATER, $block->kind);
        self::assertSame('invoice.items', $block->dataSource);
        self::assertFalse($block->repeatHeaderOnPageBreak);
        self::assertSame(8.0, $block->header?->height);
        self::assertSame(5.0, $block->headerPage2?->height);
        self::assertSame(12.0, $block->footer?->height);
        // A repeater's bindings stay a list: keying them by field name would
        // collapse a row to a single element.
        self::assertCount(1, $block->item?->bindings ?? []);
        self::assertSame('item.name', $block->item?->bindings[0]->key);
    }

    #[Test]
    public function columns_keep_their_widths_and_expansion_rules(): void
    {
        $template = $this->reload([
            Block::columns('head', [
                new BlockColumn(new ElementSet(height: 20.0), width: '60%', id: 'left'),
                new BlockColumn(new ElementSet(height: 20.0), width: '80mm', autoExpand: false, condition: 'props.seal', id: 'seal'),
            ], equalHeight: false),
        ]);

        $block = $template->blocks[0];

        self::assertSame(BlockKind::COLUMNS, $block->kind);
        self::assertFalse($block->equalHeight);
        self::assertCount(2, $block->columns);
        self::assertSame('60%', $block->columns[0]->width);
        self::assertTrue($block->columns[0]->autoExpand);
        self::assertSame('80mm', $block->columns[1]->width);
        self::assertFalse($block->columns[1]->autoExpand);
        self::assertSame('props.seal', $block->columns[1]->condition);
    }

    #[Test]
    public function an_absolute_block_keeps_its_coordinates(): void
    {
        $template = $this->reload([
            Block::single('seal', new ElementSet(height: 25.0), positionMode: PositionMode::ABSOLUTE, x: 160.0, y: 240.0),
        ]);

        $block = $template->blocks[0];

        self::assertSame(PositionMode::ABSOLUTE, $block->positionMode);
        self::assertSame(160.0, $block->x);
        self::assertSame(240.0, $block->y);
    }

    #[Test]
    public function an_unknown_block_type_is_dropped_rather_than_guessed_at(): void
    {
        // Drawing a future block kind as a plain panel would be worse than
        // leaving a gap the designer can see.
        $a4 = PageSize::a4();
        $bytes = MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            blocks: [Block::single('ok', new ElementSet(height: 10.0))],
        );
        // Same length, so the header's segment lengths stay valid and the only
        // difference the loader sees is a type it does not know.
        $doctored = \str_replace('"type":"single"', '"type":"banner"', $bytes);

        self::assertSame(\strlen($bytes), \strlen($doctored));
        self::assertSame([], CompiledTemplateLoader::loadString($doctored)->blocks);
    }

    #[Test]
    public function a_template_without_blocks_reloads_with_none(): void
    {
        $a4 = PageSize::a4();
        $template = CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Legacy', 20.0, 30.0, 'Helvetica', 12.0)],
        ));

        self::assertSame([], $template->blocks);
        self::assertSame(0.0, $template->marginTop);
        self::assertCount(1, $template->texts);
    }

    #[Test]
    public function fromTemplate_carries_blocks_and_margins(): void
    {
        $original = $this->reload([
            Block::single('a', new ElementSet(height: 10.0), marginBottom: 4.0),
            Block::repeater('r', dataSource: 'rows', item: new ElementSet(height: 6.0)),
        ]);

        $again = CompiledTemplateLoader::loadString(MpbWriter::fromTemplate($original));

        self::assertCount(2, $again->blocks);
        self::assertSame(4.0, $again->blocks[0]->marginBottom);
        self::assertSame('rows', $again->blocks[1]->dataSource);
        self::assertSame(20.0, $again->marginTop);
    }
}
