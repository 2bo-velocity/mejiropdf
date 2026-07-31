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
use MejiroPDF\Block\BlockHandle;
use MejiroPDF\Block\BlockOverride;
use MejiroPDF\Block\ElementSet;
use MejiroPDF\Document;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\TemplateText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockHandle::class)]
#[CoversClass(BlockOverride::class)]
#[CoversClass(Document::class)]
final class BlockHandleTest extends TestCase
{
    /**
     * A panel holding one point-anchored label.
     *
     * Width zero keeps it on the legacy anchored path, so the run reaches the
     * content stream as a single `(Bank) Tj` rather than one operator per
     * glyph — which is what makes these assertions readable.
     */
    private function label(string $text): ElementSet
    {
        return new ElementSet(
            texts: [new TemplateText($text, 0.0, 4.0, 'Helvetica', 10.0)],
            height: 12.0,
        );
    }

    /** A template with a conditional panel and a repeater. */
    private function template(): CompiledTemplate
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
            blocks: [
                Block::single('bank', $this->label('Bank'), condition: 'props.showBank'),
                Block::repeater(
                    'items',
                    dataSource: 'items',
                    item: new ElementSet(
                        bindings: [new DynamicBinding('name', 0.0, 0.0, 'Helvetica', 10.0, width: 60.0)],
                        height: 8.0,
                    ),
                ),
            ],
            marginTop: 20.0,
            marginRight: 15.0,
            marginBottom: 20.0,
            marginLeft: 15.0,
        );
    }

    private function document(): Document
    {
        return new Document(credit: CreditOptions::disabled());
    }

    #[Test]
    public function without_an_override_the_templates_condition_decides(): void
    {
        $shown = $this->document();
        $shown->renderTemplate($this->template(), ['props' => ['showBank' => true]]);
        self::assertStringContainsString('(Bank) Tj', $shown->output());

        $hidden = $this->document();
        $hidden->renderTemplate($this->template(), ['props' => ['showBank' => false]]);
        self::assertStringNotContainsString('(Bank) Tj', $hidden->output());
    }

    #[Test]
    public function hide_wins_over_a_condition_that_would_have_shown_it(): void
    {
        $pdf = $this->document();
        $pdf->block('bank')->hide();
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        self::assertStringNotContainsString('(Bank) Tj', $pdf->output());
    }

    #[Test]
    public function show_wins_over_a_condition_that_would_have_hidden_it(): void
    {
        // The program knows something the expression cannot reach, so a call is
        // not a hint — it decides.
        $pdf = $this->document();
        $pdf->block('bank')->show();
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => false]]);

        self::assertStringContainsString('(Bank) Tj', $pdf->output());
    }

    #[Test]
    public function visible_takes_the_answer_from_a_variable(): void
    {
        $pdf = $this->document();
        $pdf->block('bank')->visible(false);
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        self::assertStringNotContainsString('(Bank) Tj', $pdf->output());
    }

    #[Test]
    public function a_hidden_block_still_collapses_so_the_rest_moves_up(): void
    {
        // Overriding must go through the same path as a condition, not paint
        // the block in white — everything below has to move up.
        $shown = $this->document();
        $shown->renderTemplate($this->template(), [
            'props' => ['showBank' => true],
            'items' => [['name' => 'Row']],
        ]);

        $hidden = $this->document();
        $hidden->block('bank')->hide();
        $hidden->renderTemplate($this->template(), [
            'props' => ['showBank' => true],
            'items' => [['name' => 'Row']],
        ]);

        self::assertGreaterThan(
            $this->firstRowY($shown->output()),
            $this->firstRowY($hidden->output()),
            'With the panel gone the row sits higher on the page (a larger PDF y).',
        );
    }

    /** The PDF-space y of the first typeset run. */
    private function firstRowY(string $pdf): float
    {
        \preg_match('/1 0 [\d.-]+ 1 [\d.-]+ ([\d.-]+) Tm/', $pdf, $m);
        $y = $m[1] ?? '';
        self::assertNotSame('', $y, 'No typeset run was drawn.');

        return (float) $y;
    }

    #[Test]
    public function bindData_feeds_a_repeater_directly(): void
    {
        // No key in the data array points at these rows; the program simply
        // hands them over.
        $pdf = $this->document();
        $pdf->block('items')->bindData([['name' => 'Alpha'], ['name' => 'Bravo']]);
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => false]]);

        $out = $pdf->output();
        self::assertStringContainsString('(A) Tj', $out);
        self::assertStringContainsString('(B) Tj', $out);
    }

    #[Test]
    public function bindData_wins_over_the_data_source(): void
    {
        $pdf = $this->document();
        $pdf->block('items')->bindData([['name' => 'Override']]);
        $pdf->renderTemplate($this->template(), [
            'props' => ['showBank' => false],
            'items' => [['name' => 'FromData'], ['name' => 'AlsoFromData']],
        ]);

        $out = $pdf->output();
        self::assertStringContainsString('(O) Tj', $out);
        self::assertStringNotContainsString('(F) Tj', $out);
    }

    #[Test]
    public function bindData_accepts_any_iterable(): void
    {
        $rows = (static function (): \Generator {
            yield ['name' => 'Gen'];
        })();

        $pdf = $this->document();
        $pdf->block('items')->bindData($rows);
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => false]]);

        self::assertStringContainsString('(G) Tj', $pdf->output());
    }

    #[Test]
    public function naming_a_block_the_template_does_not_have_is_silent(): void
    {
        // A batch must not die because a panel was renamed in the designer.
        $pdf = $this->document();
        $pdf->block('no_such_block')->hide()->bindData([['name' => 'x']]);
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        self::assertSame(1, $pdf->pageCount());
        self::assertStringContainsString('(Bank) Tj', $pdf->output());
    }

    #[Test]
    public function the_handle_chains(): void
    {
        $pdf = $this->document();
        $handle = $pdf->block('items');

        self::assertSame($handle, $handle->show()->bindData([])->visible(true)->reset());
    }

    #[Test]
    public function reset_returns_a_block_to_what_the_template_says(): void
    {
        $pdf = $this->document();
        $pdf->block('bank')->hide()->reset();
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        self::assertStringContainsString('(Bank) Tj', $pdf->output());
    }

    #[Test]
    public function overrides_can_be_cleared_between_records(): void
    {
        // Rendering twice from one Document would otherwise carry the first
        // record's decisions into the second.
        $pdf = $this->document();
        $pdf->block('bank')->hide();
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        $pdf->clearBlockOverrides();
        $pdf->renderTemplate($this->template(), ['props' => ['showBank' => true]]);

        self::assertSame(2, $pdf->pageCount());
        self::assertSame(1, \substr_count($pdf->output(), '(Bank) Tj'), 'Only the second page has it.');
    }

    #[Test]
    public function the_same_block_can_be_addressed_twice(): void
    {
        $pdf = $this->document();
        $pdf->block('bank')->hide();
        $pdf->block('bank')->show();

        self::assertTrue($pdf->blockOverrides()['bank']->visible);
    }
}
