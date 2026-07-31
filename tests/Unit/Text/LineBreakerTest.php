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
use MejiroPDF\Text\FormattedLine;
use MejiroPDF\Text\LineBreaker;
use MejiroPDF\Text\LineUnit;
use MejiroPDF\Text\TextStyle;
use MejiroPDF\Text\TextToken;
use MejiroPDF\Text\TextTokenizer;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Uses a fixed 500-unit (half-em) advance for every glyph, so at 10 pt each
 * character is exactly 5 pt wide and break positions are fully deterministic.
 */
#[CoversClass(LineBreaker::class)]
#[CoversClass(FormattedLine::class)]
#[CoversClass(LineUnit::class)]
final class LineBreakerTest extends TestCase
{
    private const float SIZE = 10.0;   // → 5 pt per glyph
    private const float FIVE = 25.0;   // width of five glyphs

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

    private function breaker(?TypesetterOptions $options = null): LineBreaker
    {
        return new LineBreaker($this->metrics(), self::SIZE, $options ?? new TypesetterOptions());
    }

    /**
     * @param list<FormattedLine> $lines
     * @return list<string>
     */
    private function texts(array $lines): array
    {
        return \array_map(
            static fn (FormattedLine $line): string => \implode('', \array_map(
                static fn (LineUnit $u): string => $u->text,
                $line->units,
            )),
            $lines,
        );
    }

    #[Test]
    public function it_wraps_cjk_per_character(): void
    {
        $tokens = TextTokenizer::tokenize('あいうえおかきくけこ');

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertSame(['あいうえお', 'かきくけこ'], $this->texts($lines));
    }

    #[Test]
    public function it_keeps_latin_words_whole_and_drops_the_wrap_space(): void
    {
        $tokens = TextTokenizer::tokenize('aa bb cc dd');

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertSame(['aa bb', 'cc dd'], $this->texts($lines));
    }

    #[Test]
    public function an_over_long_word_is_hard_broken(): void
    {
        $tokens = TextTokenizer::tokenize('abcdefghij');

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertSame(['abcde', 'fghij'], $this->texts($lines));
    }

    #[Test]
    public function kinsoku_pushes_a_prohibited_char_off_the_line_start(): void
    {
        // Burasage off so the leading-punctuation rule is exercised in isolation.
        $options = new TypesetterOptions(enableBurasage: false);
        $tokens = TextTokenizer::tokenize('あいうえお。かき');

        $lines = $this->breaker($options)->break($tokens, self::FIVE);

        // Naive wrap would start line 2 with 。; push-out moves お down instead.
        self::assertSame(['あいうえ', 'お。かき'], $this->texts($lines));
    }

    #[Test]
    public function burasage_hangs_punctuation_past_the_edge(): void
    {
        $tokens = TextTokenizer::tokenize('あいうえお、か');

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertSame(['あいうえお、', 'か'], $this->texts($lines));
        // The hung 、 makes the first line one glyph wider than the box.
        self::assertEqualsWithDelta(self::FIVE + 5.0, $lines[0]->width, 0.001);
    }

    #[Test]
    public function a_ruby_group_is_one_unbreakable_unit_sized_to_its_wider_side(): void
    {
        $tokens = [new TextToken('漢', rubyText: 'かんじ')];

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertCount(1, $lines);
        self::assertCount(1, $lines[0]->units);
        self::assertTrue($lines[0]->hasRuby());
        // Ruby "かんじ" at half size = 3 × 2.5 = 7.5 pt, wider than the 5 pt parent.
        self::assertEqualsWithDelta(7.5, $lines[0]->units[0]->width, 0.001);
    }

    #[Test]
    public function explicit_newlines_start_new_lines(): void
    {
        $lines = $this->breaker()->break(TextTokenizer::tokenize("ab\ncd"), self::FIVE);

        self::assertSame(['ab', 'cd'], $this->texts($lines));
    }

    #[Test]
    public function a_blank_paragraph_yields_an_empty_line(): void
    {
        $lines = $this->breaker()->break(TextTokenizer::tokenize("a\n\nb"), self::FIVE);

        self::assertSame(['a', '', 'b'], $this->texts($lines));
        self::assertTrue($lines[1]->isEmpty());
    }

    #[Test]
    public function bold_style_is_carried_onto_the_units(): void
    {
        $lines = $this->breaker()->break(TextTokenizer::tokenize('**ab**'), self::FIVE);

        self::assertTrue($lines[0]->units[0]->style->isBold);
    }

    #[Test]
    public function only_a_paragraphs_final_line_is_marked_last(): void
    {
        $lines = $this->breaker()->break(TextTokenizer::tokenize('あいうえおかきくけこ'), self::FIVE);

        self::assertFalse($lines[0]->isLastInParagraph);
        self::assertTrue($lines[1]->isLastInParagraph);
    }

    #[Test]
    public function each_hard_newline_paragraph_ends_its_own_line(): void
    {
        $lines = $this->breaker()->break(TextTokenizer::tokenize("ab\ncd"), self::FIVE);

        self::assertTrue($lines[0]->isLastInParagraph);
        self::assertTrue($lines[1]->isLastInParagraph);
    }

    #[Test]
    public function vertical_auto_tcy_groups_exactly_two_digits(): void
    {
        $breaker = new LineBreaker($this->metrics(), self::SIZE, new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL));

        $units = $breaker->break(TextTokenizer::tokenize('あ24い'), 100.0)[0]->units;

        self::assertCount(3, $units); // あ, [24], い
        self::assertSame('24', $units[1]->text);
        self::assertTrue($units[1]->isTcy);
        self::assertEqualsWithDelta(self::SIZE, $units[1]->width, 0.001); // one em cell
    }

    #[Test]
    public function three_digits_are_not_auto_tcy(): void
    {
        $breaker = new LineBreaker($this->metrics(), self::SIZE, new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL));

        $units = $breaker->break(TextTokenizer::tokenize('123'), 100.0)[0]->units;

        self::assertCount(3, $units);
        self::assertFalse($units[0]->isTcy);
    }

    #[Test]
    public function horizontal_does_not_group_tcy(): void
    {
        $units = $this->breaker()->break(TextTokenizer::tokenize('24'), 100.0)[0]->units;

        self::assertCount(2, $units);
        self::assertFalse($units[0]->isTcy);
    }

    #[Test]
    public function indent_reserves_a_narrower_first_line(): void
    {
        // 25 pt column, but the first line reserves 10 pt (→ 3 glyphs) beside a
        // drop cap; later lines use the full width (5 glyphs).
        $lines = $this->breaker()->break(TextTokenizer::tokenize('あいうえおかきくけこ'), self::FIVE, indent: 10.0, indentLines: 1);

        self::assertSame(['あいう', 'えおかきく', 'けこ'], $this->texts($lines));
        self::assertEqualsWithDelta(10.0, $lines[0]->indent, 0.001);
        self::assertEqualsWithDelta(0.0, $lines[1]->indent, 0.001);
    }

    #[Test]
    public function empty_input_produces_no_lines(): void
    {
        self::assertSame([], $this->breaker()->break([], self::FIVE));
    }

    #[Test]
    public function vertical_mode_advances_one_em_per_character(): void
    {
        // 全角送り: every glyph advances one em (10 pt), so a 25 pt column holds
        // two full-width characters regardless of the metrics' proportional width.
        $breaker = new LineBreaker($this->metrics(), self::SIZE, new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL));

        $lines = $breaker->break(TextTokenizer::tokenize('あいうえお'), self::FIVE);

        self::assertSame(['あい', 'うえ', 'お'], $this->texts($lines));
        self::assertEqualsWithDelta(self::SIZE, $lines[0]->units[0]->width, 0.001);
    }

    #[Test]
    public function a_per_span_font_size_override_changes_measured_width(): void
    {
        // size=20pt → 10 pt per glyph → only two fit in the 25 pt box.
        $tokens = TextTokenizer::tokenize('[ちいさな]{size=20pt}');

        $lines = $this->breaker()->break($tokens, self::FIVE);

        self::assertSame(['ちい', 'さな'], $this->texts($lines));
    }
}
