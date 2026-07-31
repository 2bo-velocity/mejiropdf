<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Font\FontMetricsInterface;
use MejiroPDF\Text\LineBreaker;
use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TextTokenizer;
use MejiroPDF\Text\Typesetter;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Typesetter::class)]
final class TypesetterTest extends TestCase
{
    private const float SIZE = 10.0; // 5 pt per glyph with the double below

    private function metrics(): FontMetricsInterface
    {
        return new class implements FontMetricsInterface {
            public function charWidth(string $char): int
            {
                return 500;
            }

            public function textWidth(string $text, float $fontSize): float
            {
                return \mb_strlen($text, 'UTF-8') * 500.0 / 1000.0 * $fontSize;
            }
        };
    }

    private function render(string $markup, ?TypesetterOptions $options = null, float $boxWidth = 100.0): string
    {
        $options ??= new TypesetterOptions();
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize($markup), $boxWidth);

        return (new Typesetter($options))->render(
            $lines,
            0.0,
            100.0,
            $boxWidth,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;
    }

    #[Test]
    public function it_emits_a_text_object_per_run(): void
    {
        $ops = $this->render('ab');

        self::assertStringContainsString('BT', $ops);
        self::assertStringContainsString('/F1 10.00 Tf', $ops);
        self::assertStringContainsString('(a) Tj', $ops);
        self::assertStringContainsString('(b) Tj', $ops);
    }

    #[Test]
    public function bold_uses_faux_stroke_fill(): void
    {
        self::assertStringContainsString('2 Tr', $this->render('**a**'));
    }

    #[Test]
    public function italic_slants_the_text_matrix(): void
    {
        self::assertStringContainsString('1 0 0.2126 1', $this->render('*a*'));
    }

    #[Test]
    public function highlight_paints_a_background_rectangle(): void
    {
        $ops = $this->render('==a==');

        self::assertStringContainsString('1.000 0.945 0.463 rg', $ops); // #FFF176
        self::assertStringContainsString("re\nf", $ops);
    }

    #[Test]
    public function underline_and_strikethrough_stroke_a_line(): void
    {
        self::assertStringContainsString("l\nS", $this->render('[a]{.underline}'));
        self::assertStringContainsString("l\nS", $this->render('~~a~~'));
    }

    #[Test]
    public function ruby_is_drawn_smaller_above_the_parent(): void
    {
        $ops = $this->render('|漢《かん》');

        self::assertStringContainsString('(漢) Tj', $ops);
        self::assertStringContainsString('(かん) Tj', $ops);
        self::assertStringContainsString('/F1 5.00 Tf', $ops); // ruby at half size
    }

    #[Test]
    public function emphasis_dots_draw_a_disc_per_character(): void
    {
        // Two parent chars → two bezier discs (the only source of "c" curves).
        $ops = $this->render('《《傍点》》');

        self::assertSame(8, \substr_count($ops, ' c'));
    }

    #[Test]
    public function centering_offsets_the_first_run(): void
    {
        $start = $this->render('abc');
        $center = $this->render('abc', new TypesetterOptions(align: TextAlign::CENTER));

        self::assertStringContainsString('1 0 0.0000 1 0.00 ', $start);
        // (100 − 15) / 2 = 42.5
        self::assertStringContainsString('1 0 0.0000 1 42.50 ', $center);
    }

    #[Test]
    public function justify_spreads_inner_lines_but_not_the_last(): void
    {
        // 6 glyphs in a 27 pt box: line 1 = "あいうえお" (25 pt), line 2 = "か".
        $justified = $this->render('あいうえおか', new TypesetterOptions(align: TextAlign::JUSTIFY), 27.0);
        $ragged = $this->render('あいうえおか', new TypesetterOptions(), 27.0);

        // Extra 2 pt over 4 gaps = 0.5 pt each, so the 2nd glyph shifts to 5.50.
        self::assertStringContainsString('1 0 0.0000 1 5.50 ', $justified);
        self::assertStringContainsString('1 0 0.0000 1 5.00 ', $ragged);
        // The last line ("か") is a single glyph and is never stretched.
        self::assertStringContainsString('1 0 0.0000 1 0.00 ', $justified);
    }

    #[Test]
    public function vertical_stacks_glyphs_down_and_columns_left(): void
    {
        $options = new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL);
        // 25 pt column holds two em-advanced glyphs, so 5 chars → 3 columns.
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize('あいうえお'), 25.0);

        $ops = (new Typesetter($options))->render(
            $lines,
            100.0, // right edge
            100.0, // top
            25.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;

        // Column 1: あ then い stacked downward at the same x.
        self::assertStringContainsString('1 0.0000 0 1 90.00 91.50', $ops);
        self::assertStringContainsString('1 0.0000 0 1 90.00 81.50', $ops);
        // Column 2 sits to the left (smaller x), back at the top.
        self::assertStringContainsString('1 0.0000 0 1 74.00 91.50', $ops);
    }

    #[Test]
    public function vertical_full_stop_shifts_to_the_upper_right(): void
    {
        $options = new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL);
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize('本。'), 100.0);

        $ops = (new Typesetter($options))->render(
            $lines,
            100.0,
            100.0,
            100.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;

        // 本 sits centred; 。 is nudged right (+4.5) and up (+5.0) in its cell.
        self::assertStringContainsString("1 0.0000 0 1 90.00 91.50 Tm\n(本) Tj", $ops);
        self::assertStringContainsString("1 0.0000 0 1 94.50 86.50 Tm\n(。) Tj", $ops);
    }

    #[Test]
    public function vertical_ruby_is_drawn_at_half_size(): void
    {
        $options = new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL);
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize('|漢《かん》'), 100.0);

        $ops = (new Typesetter($options))->render(
            $lines,
            100.0,
            100.0,
            100.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;

        self::assertStringContainsString('(漢) Tj', $ops);
        self::assertStringContainsString('(か) Tj', $ops); // ruby stacked per char
        self::assertStringContainsString('/F1 5.00 Tf', $ops);
    }

    #[Test]
    public function vertical_tcy_draws_the_run_upright_and_horizontal(): void
    {
        $options = new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL);
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize('あ24い'), 100.0);

        $ops = (new Typesetter($options))->render(
            $lines,
            100.0,
            100.0,
            100.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;

        // The digits are one run with a plain (horizontal) matrix, unlike the
        // "1 b 0 1" matrix used for the stacked CJK glyphs.
        self::assertMatchesRegularExpression("~1 0 0 1 [\\d.]+ [\\d.]+ Tm\n\\(24\\) Tj~", $ops);
    }

    #[Test]
    public function an_indented_line_starts_further_right(): void
    {
        $options = new TypesetterOptions();
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize('あい'), 100.0, indent: 20.0, indentLines: 1);

        $ops = (new Typesetter($options))->render(
            $lines,
            0.0,
            100.0,
            100.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        )->operators;

        self::assertStringContainsString('1 0 0.0000 1 20.00 ', $ops);
    }

    #[Test]
    public function it_reports_height_and_line_count(): void
    {
        $options = new TypesetterOptions();
        $lines = (new LineBreaker($this->metrics(), self::SIZE, $options))
            ->break(TextTokenizer::tokenize("ab\ncd"), 100.0);

        $result = (new Typesetter($options))->render(
            $lines,
            0.0,
            100.0,
            100.0,
            self::SIZE,
            'F1',
            static fn (string $run): string => '(' . $run . ')',
        );

        self::assertSame(2, $result->lineCount);
        self::assertEqualsWithDelta(2 * self::SIZE * 1.6, $result->height, 0.001);
    }
}
