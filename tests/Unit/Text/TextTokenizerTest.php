<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\TextStyle;
use MejiroPDF\Text\TextToken;
use MejiroPDF\Text\TextTokenizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextTokenizer::class)]
#[CoversClass(TextStyle::class)]
#[CoversClass(TextToken::class)]
final class TextTokenizerTest extends TestCase
{
    #[Test]
    public function it_returns_a_single_plain_token_for_plain_text(): void
    {
        $tokens = TextTokenizer::tokenize('Hello, 世界');

        self::assertCount(1, $tokens);
        self::assertSame('Hello, 世界', $tokens[0]->text);
        self::assertNull($tokens[0]->rubyText);
        self::assertEquals(new TextStyle(), $tokens[0]->style);
    }

    #[Test]
    public function it_returns_nothing_for_an_empty_string(): void
    {
        self::assertSame([], TextTokenizer::tokenize(''));
    }

    #[Test]
    public function it_splits_plain_runs_around_a_bold_span(): void
    {
        $tokens = TextTokenizer::tokenize('a **b** c');

        self::assertCount(3, $tokens);
        self::assertSame(['a ', 'b', ' c'], \array_map(static fn (TextToken $t): string => $t->text, $tokens));
        self::assertFalse($tokens[0]->style->isBold);
        self::assertTrue($tokens[1]->style->isBold);
        self::assertFalse($tokens[2]->style->isBold);
    }

    #[Test]
    public function it_reads_italic_and_strikethrough_and_highlight(): void
    {
        [$italic] = TextTokenizer::tokenize('*x*');
        self::assertTrue($italic->style->isItalic);

        [$strike] = TextTokenizer::tokenize('~~x~~');
        self::assertTrue($strike->style->isStrikethrough);

        [$high] = TextTokenizer::tokenize('==x==');
        self::assertSame('#FFF176', $high->style->backgroundColor);
    }

    #[Test]
    public function it_parses_both_ruby_syntaxes(): void
    {
        [$pipe] = TextTokenizer::tokenize('|漢字《かんじ》');
        self::assertSame('漢字', $pipe->text);
        self::assertSame('かんじ', $pipe->rubyText);

        [$brace] = TextTokenizer::tokenize('{漢字|かんじ}');
        self::assertSame('漢字', $brace->text);
        self::assertSame('かんじ', $brace->rubyText);
    }

    #[Test]
    public function it_parses_emphasis_dots(): void
    {
        [$token] = TextTokenizer::tokenize('《《傍点》》');

        self::assertSame('傍点', $token->text);
        self::assertTrue($token->style->isBouten);
    }

    #[Test]
    public function it_styles_a_link_blue_underlined_with_its_url(): void
    {
        [$token] = TextTokenizer::tokenize('[公式](https://mejiropdf.com)');

        self::assertSame('公式', $token->text);
        self::assertTrue($token->style->isUnderline);
        self::assertSame('#0066CC', $token->style->color);
        self::assertSame('https://mejiropdf.com', $token->style->linkUrl);
    }

    #[Test]
    public function it_parses_attribute_spans(): void
    {
        [$token] = TextTokenizer::tokenize('[見出し]{color=#FF0000 bg=#FFFF00 size=14pt .bold .underline}');

        self::assertSame('見出し', $token->text);
        self::assertSame('#FF0000', $token->style->color);
        self::assertSame('#FFFF00', $token->style->backgroundColor);
        self::assertSame(14.0, $token->style->fontSize);
        self::assertTrue($token->style->isBold);
        self::assertTrue($token->style->isUnderline);
    }

    #[Test]
    public function it_nests_styles_by_recursion(): void
    {
        // Bold wrapping a link: the inner run carries both.
        [$token] = TextTokenizer::tokenize('**[サイト](https://example.com)**');

        self::assertSame('サイト', $token->text);
        self::assertTrue($token->style->isBold);
        self::assertTrue($token->style->isUnderline);
        self::assertSame('https://example.com', $token->style->linkUrl);
    }

    #[Test]
    public function it_keeps_ruby_inside_a_styled_span(): void
    {
        // Ruby parent should survive with the surrounding bold style.
        $tokens = TextTokenizer::tokenize('**|絶対《ぜったい》に**');

        self::assertSame('絶対', $tokens[0]->text);
        self::assertSame('ぜったい', $tokens[0]->rubyText);
        self::assertTrue($tokens[0]->style->isBold);
        self::assertSame('に', $tokens[1]->text);
        self::assertTrue($tokens[1]->style->isBold);
    }

    #[Test]
    public function it_marks_tate_chu_yoko(): void
    {
        [$caret] = TextTokenizer::tokenize('^24^');
        self::assertSame('24', $caret->text);
        self::assertTrue($caret->style->isTcy);

        [$attr] = TextTokenizer::tokenize('[24]{.tcy}');
        self::assertTrue($attr->style->isTcy);
    }

    #[Test]
    public function it_treats_an_escaped_marker_as_a_literal(): void
    {
        $tokens = TextTokenizer::tokenize('price \\*5');

        self::assertSame('price ', $tokens[0]->text);
        self::assertSame('*', $tokens[1]->text);
        self::assertFalse($tokens[1]->style->isItalic);
        self::assertSame('5', $tokens[2]->text);
    }

    #[Test]
    public function it_leaves_a_lone_pipe_or_brace_as_plain_text(): void
    {
        // No matching ruby structure, so these stay literal.
        self::assertSame('a | b', TextTokenizer::tokenize('a | b')[0]->text);
        self::assertSame('{plain}', TextTokenizer::tokenize('{plain}')[0]->text);
    }

    #[Test]
    public function with_sets_but_never_clears_a_style_attribute(): void
    {
        $style = (new TextStyle())->with(isBold: true, color: '#111111');

        // Overriding one field keeps the others.
        $next = $style->with(isItalic: true);
        self::assertTrue($next->isBold);
        self::assertTrue($next->isItalic);
        self::assertSame('#111111', $next->color);
    }
}
