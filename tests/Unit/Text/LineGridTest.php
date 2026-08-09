<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\Typesetter;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Facing pages should put their lines at the same heights.
 *
 * Measured before it was built: uniform prose already lands on a grid of its
 * own line advance, so this earns its place only where a line is taller than
 * the rest — ruby above all.
 */
#[CoversClass(Typesetter::class)]
#[CoversClass(TypesetterOptions::class)]
#[CoversClass(Document::class)]
final class LineGridTest extends TestCase
{
    private const float SIZE = 9.5;
    private const float STEP = self::SIZE * 1.6;   // the default line advance, in points

    private const string PARAGRAPH = '　本文が続く。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。組版の話をする。組版の話をする。';

    /**
     * Every body baseline, in millimetres from the top of the page it is on.
     *
     * @return list<float>
     */
    private static function baselines(string $body, float $grid): array
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: 110),
            $body,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            options: new TypesetterOptions(lineGrid: $grid),
        );

        \preg_match_all(
            \sprintf("~/F\d+ %.2F Tf\n1 0 0\.0000 1 [\d.]+ ([\d.]+) Tm~", self::SIZE),
            $pdf->output(),
            $hits,
        );

        $found = [];
        foreach ($hits[1] as $y) {
            $found[\number_format((841.89 - (float) $y) / PageSize::MM_TO_PT, 3, '.', '')] = true;
        }
        $baselines = \array_map('floatval', \array_keys($found));
        \sort($baselines);

        return $baselines;
    }

    /** The furthest any baseline sits from a multiple of the step, in millimetres. */
    private static function worstDeparture(string $body, float $grid): float
    {
        $baselines = self::baselines($body, $grid);
        self::assertNotSame([], $baselines);

        $step = self::STEP / PageSize::MM_TO_PT;
        $first = $baselines[0];
        $worst = 0.0;
        foreach ($baselines as $y) {
            $off = \fmod($y - $first, $step);
            $worst = \max($worst, \min(\abs($off), \abs($step - $off)));
        }

        return $worst;
    }

    private static function withRuby(): string
    {
        return \implode("\n\n", [
            self::PARAGRAPH,
            '|漢字《かんじ》が出る。' . self::PARAGRAPH,
            ...\array_fill(0, 8, self::PARAGRAPH),
        ]);
    }

    #[Test]
    public function uniform_prose_already_lands_on_a_grid(): void
    {
        // This is why the setting is off by default: every step is the line
        // advance and a paragraph break is two of them, so there is nothing to
        // correct. Adding the grid must not move such a page.
        $body = \implode("\n\n", \array_fill(0, 10, self::PARAGRAPH));

        self::assertLessThan(0.01, self::worstDeparture($body, 0.0));
        self::assertSame(self::baselines($body, 0.0), self::baselines($body, self::STEP));
    }

    #[Test]
    public function ruby_throws_the_pages_out_of_step_and_the_grid_puts_them_back(): void
    {
        // A measured 2.2 mm: ruby raises the line it sits on, and every line
        // after it on the page inherits the shift.
        self::assertGreaterThan(2.0, self::worstDeparture(self::withRuby(), 0.0));
        self::assertLessThan(0.01, self::worstDeparture(self::withRuby(), self::STEP));
    }

    #[Test]
    public function the_room_a_grid_adds_goes_above_the_line(): void
    {
        // Rounding the slot up but leaving the text at the top of it would put
        // the slot on the grid and the baseline still off it — which is what
        // the first attempt did.
        $grid = self::baselines(self::withRuby(), self::STEP);
        $step = self::STEP / PageSize::MM_TO_PT;

        foreach ($grid as $y) {
            $off = \fmod($y - $grid[0], $step);
            self::assertLessThan(0.01, \min(\abs($off), \abs($step - $off)));
        }
    }

    #[Test]
    public function a_line_is_never_given_less_room_than_it_takes(): void
    {
        // Quantising rounds up, so a page holds no more lines than before and
        // may hold fewer.
        self::assertGreaterThanOrEqual(
            \count(self::baselines(self::withRuby(), self::STEP)),
            \count(self::baselines(self::withRuby(), 0.0)),
        );
    }

    #[Test]
    public function a_coarser_grid_is_honoured_too(): void
    {
        $double = self::STEP * 2.0;
        $baselines = self::baselines(self::withRuby(), $double);
        $step = $double / PageSize::MM_TO_PT;

        foreach ($baselines as $y) {
            $off = \fmod($y - $baselines[0], $step);
            self::assertLessThan(0.01, \min(\abs($off), \abs($step - $off)));
        }
    }

    #[Test]
    public function the_setting_survives_the_witherers(): void
    {
        // A new option that the withers forget is silently dropped on any path
        // that re-derives the options — which is exactly what happened here
        // before this test existed.
        $options = new TypesetterOptions(lineGrid: 12.0);

        self::assertSame(0.0, (new TypesetterOptions())->lineGrid);
        self::assertSame(12.0, $options->withAlign($options->align)->lineGrid);
    }
}
