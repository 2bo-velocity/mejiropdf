<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\EmphasisMarkStyle;
use MejiroPDF\Text\RubyAlignment;
use MejiroPDF\Text\RubyStyle;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentTypesetTest extends TestCase
{
    #[Test]
    public function it_typesets_latin_decoration_and_links(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            'Hello **bold** *slanted* and [site](https://example.com) end',
            20,
            30,
            120,
            font: StandardFont::HELVETICA,
            size: 12.0,
        );

        $out = $pdf->output();

        self::assertStringContainsString('2 Tr', $out);                 // faux bold
        self::assertStringContainsString('1 0 0.2126 1', $out);         // italic shear
        self::assertStringContainsString('0.000 0.400 0.800 rg', $out); // link colour #0066CC
        self::assertStringContainsString('(H) Tj', $out);               // one Tj per glyph
    }

    #[Test]
    public function it_typesets_japanese_ruby_and_highlight(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》に《《傍点》》と ==強調==',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
        );

        $out = $pdf->output();

        // Ruby is drawn at half size (6 pt); the highlight paints its rect.
        self::assertMatchesRegularExpression('~/F\d+ 6\.00 Tf~', $out);
        self::assertStringContainsString('1.000 0.945 0.463 rg', $out); // #FFF176 highlight
        self::assertStringContainsString("re\nf", $out);
        // CJK is emitted as UTF-16BE hex strings.
        self::assertMatchesRegularExpression('~<[0-9A-F]+> Tj~', $out);
    }

    #[Test]
    public function it_applies_smart_quotes_and_dashes_by_default(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            'He said "yes" -- ok',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
        );

        $out = $pdf->output();

        // CID glyphs are UTF-16BE hex: “ = 201C, ” = 201D, — = 2014.
        self::assertStringContainsString('<201C>', $out);
        self::assertStringContainsString('<201D>', $out);
        self::assertStringContainsString('<2014>', $out);
    }

    #[Test]
    public function plain_options_keep_straight_quotes(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '"x"',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: TypesetterOptions::plain(),
        );

        $out = $pdf->output();

        self::assertStringContainsString('<0022>', $out);       // straight "
        self::assertStringNotContainsString('<201C>', $out);
    }

    #[Test]
    public function it_typesets_vertical_japanese_with_ruby(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|吾輩《わがはい》は|猫《ねこ》である。',
            190, // right edge (mm)
            20,
            100, // column height (mm)
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: TypesetterOptions::novel(),
        );

        $out = $pdf->output();

        self::assertStringContainsString('1 0.0000 0 1', $out);          // vertical text matrix
        self::assertMatchesRegularExpression('~/F\d+ 6\.00 Tf~', $out);  // ruby at half size
        self::assertMatchesRegularExpression('~<[0-9A-F]+> Tj~', $out);  // CJK hex glyphs
    }

    #[Test]
    public function vertical_auto_tcy_sets_two_digits_horizontally(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '平成24年',
            190,
            20,
            100,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: TypesetterOptions::novel(),
        );

        $out = $pdf->output();

        // "24" is one run drawn with a horizontal matrix; UTF-16BE of 2,4.
        self::assertStringContainsString('<00320034> Tj', $out);
        self::assertMatchesRegularExpression("~1 0 0 1 [\\d.]+ [\\d.]+ Tm\n<00320034> Tj~", $out);
    }

    #[Test]
    public function flow_returns_the_consumed_height_in_mm(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        // One line at 12 pt with the 1.6 line-height factor, expressed in mm.
        $height = $pdf->flow('Hello world', 20, 20, 200, font: StandardFont::HELVETICA, size: 12.0);

        self::assertEqualsWithDelta(12.0 * 1.6 / (72.0 / 25.4), $height, 0.01);
    }

    #[Test]
    public function flow_lets_blocks_stack_below_one_another(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $y = 20.0;
        $y += $pdf->flow('Heading', 20, $y, 200, font: StandardFont::HELVETICA, size: 16.0);
        $pdf->flow('Body', 20, $y, 200, font: StandardFont::HELVETICA, size: 11.0);

        $out = $pdf->output();

        self::assertStringContainsString('(H) Tj', $out);
        self::assertStringContainsString('(B) Tj', $out);
    }

    #[Test]
    public function plain_options_skip_markup_parsing(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            'literal **stars** stay',
            20,
            30,
            200,
            font: StandardFont::HELVETICA,
            size: 12.0,
            options: TypesetterOptions::plain(),
        );

        $out = $pdf->output();

        // The asterisks are drawn as literal glyphs (one Tj each), no faux-bold.
        self::assertGreaterThanOrEqual(4, \substr_count($out, '(*) Tj'));
        self::assertStringNotContainsString('2 Tr', $out);
    }

    #[Test]
    public function ruby_size_and_gap_follow_the_configured_style(): void
    {
        // 5pt ruby instead of the derived half-size, so the Tf operand pins it.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(rubyStyle: new RubyStyle(fontSize: 5.0, offset: 3.0)),
        );

        self::assertStringContainsString('5.00 Tf', $pdf->output());
    }

    #[Test]
    public function a_taller_ruby_band_pushes_the_following_line_further_down(): void
    {
        // The reserved band is what stops one line's ruby colliding with the
        // line above, so a bigger gap must consume more height.
        $tight = $this->rubyBlockHeight(new RubyStyle(offset: 0.0));
        $loose = $this->rubyBlockHeight(new RubyStyle(offset: 6.0));

        self::assertGreaterThan($tight, $loose);
    }

    #[Test]
    public function evenly_distributed_ruby_spreads_with_character_spacing(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(
                rubyStyle: new RubyStyle(alignment: RubyAlignment::JIS_EQUAL_SPACE),
            ),
        );

        // Non-zero Tc is how the run is spread across the parent.
        self::assertMatchesRegularExpression('/[1-9]\d*\.\d{3} Tc/', $pdf->output());
    }

    #[Test]
    public function centred_ruby_is_drawn_as_one_solid_run(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(rubyStyle: new RubyStyle(alignment: RubyAlignment::CENTER)),
        );

        self::assertStringContainsString('0.000 Tc', $pdf->output());
    }

    #[Test]
    public function an_open_emphasis_mark_is_stroked_rather_than_filled(): void
    {
        // Marks are vector geometry, never glyphs, so they cannot turn to tofu
        // in a font that lacks the character.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '《《傍点》》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(
                emphasisMarkStyle: new EmphasisMarkStyle(mark: EmphasisMarkStyle::OPEN_CIRCLE),
            ),
        );

        $out = $pdf->output();

        self::assertStringContainsString("c\nS\n", $out, 'An open circle must be stroked.');
    }

    #[Test]
    public function a_triangular_emphasis_mark_is_drawn_as_a_filled_path(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '《《傍点》》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(
                emphasisMarkStyle: new EmphasisMarkStyle(mark: EmphasisMarkStyle::TRIANGLE),
            ),
        );

        self::assertMatchesRegularExpression('/m\n[-\d. ]+l\n[-\d. ]+l\nf/', $pdf->output());
    }

    /**
     * Height (mm) consumed by one ruby-bearing block under the given style.
     */
    private function rubyBlockHeight(RubyStyle $style): float
    {
        $pdf = new Document(credit: CreditOptions::disabled());

        return $pdf->addPage()->flow(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(rubyStyle: $style),
        );
    }

    #[Test]
    public function ruby_can_be_set_in_a_different_face_from_the_text(): void
    {
        // Gothic body with Mincho furigana: the ruby must reference its own
        // font resource, not the parent's.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_KAKU_GO,
            size: 12.0,
            options: new TypesetterOptions(
                rubyStyle: new RubyStyle(font: CidFontJapanese::HEISEI_MIN_W3),
            ),
        );

        $out = $pdf->output();

        self::assertStringContainsString('HeiseiKakuGo-W5', $out);
        self::assertStringContainsString('HeiseiMin-W3', $out, 'The ruby font must be embedded too.');
        self::assertNotSame(
            $this->resourceAtSize($out, 12.0),
            $this->resourceAtSize($out, 6.0),
            'The furigana must reference its own face, not the body one.',
        );
    }

    #[Test]
    public function ruby_falls_back_to_the_body_face_when_its_own_has_no_glyphs(): void
    {
        // Helvetica is a single-byte face with no kana at all. Asked to set
        // furigana in it, the engine must use the Japanese body face instead of
        // emitting a run that renders as 豆腐.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '|漢字《かんじ》',
            20,
            30,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: new TypesetterOptions(
                rubyStyle: new RubyStyle(font: StandardFont::HELVETICA),
            ),
        );

        $out = $pdf->output();

        self::assertSame(
            $this->resourceAtSize($out, 12.0),
            $this->resourceAtSize($out, 6.0),
            'The furigana must fall back to the body face.',
        );
        self::assertStringNotContainsString('(かんじ)', $out, 'Kana must never be emitted as a single-byte literal.');
    }

    /**
     * The /Fn resource the content stream selects at the given point size — the
     * body is set at its own size and the furigana at the ruby size, so the two
     * lookups say which face actually drew each.
     */
    private function resourceAtSize(string $pdf, float $size): string
    {
        self::assertSame(
            1,
            \preg_match(\sprintf('~/(F\d+) %.2F Tf~', $size), $pdf, $m),
            \sprintf('No font is selected at %.2F pt.', $size),
        );

        return $m[1];
    }
}
