<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\VerticalForm;
use MejiroPDF\Text\VerticalFormStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerticalForm::class)]
#[CoversClass(VerticalFormStyle::class)]
#[CoversClass(\MejiroPDF\Text\VerticalOrientation::class)]
final class VerticalFormTest extends TestCase
{
    /** @return iterable<string, array{string, VerticalForm}> */
    public static function characters(): iterable
    {
        yield 'prolonged sound mark' => ['ー', VerticalForm::ROTATE_90];
        yield 'opening corner bracket' => ['「', VerticalForm::ROTATE_90];
        yield 'closing corner bracket' => ['」', VerticalForm::ROTATE_90];
        yield 'full-width parenthesis' => ['（', VerticalForm::ROTATE_90];
        yield 'wave dash' => ['〜', VerticalForm::ROTATE_90];
        yield 'ellipsis' => ['…', VerticalForm::ROTATE_90];
        yield 'lenticular bracket' => ['【', VerticalForm::ROTATE_90];

        // Measured: the fonts' own vertical form of the colon is a turned one,
        // though the sampled generator left it upright.
        yield 'colon' => ['：', VerticalForm::ROTATE_90];
        yield 'semicolon' => ['；', VerticalForm::ROTATE_90];

        yield 'ideographic full stop' => ['。', VerticalForm::SHIFT_UPPER_RIGHT];
        yield 'ideographic comma' => ['、', VerticalForm::SHIFT_UPPER_RIGHT];
        yield 'full-width comma' => ['，', VerticalForm::SHIFT_UPPER_RIGHT];
        yield 'full-width full stop' => ['．', VerticalForm::SHIFT_UPPER_RIGHT];

        // `Tu`, like the full stop, but the transform is not a move: four
        // Japanese faces substitute neither, and both are drawn centred. The
        // full stop's displacement would put them in the cell above.
        yield 'full-width exclamation' => ['！', VerticalForm::UPRIGHT];
        yield 'full-width question' => ['？', VerticalForm::UPRIGHT];

        // Half-width punctuation turns rather than moving — its box is half an
        // em, so a full-em displacement would carry it out of its cell. The
        // hand-written table had it moving; UAX #50 says `R`.
        yield 'half-width full stop' => ['｡', VerticalForm::ROTATE_90];
        yield 'half-width comma' => ['､', VerticalForm::ROTATE_90];

        // Reached now that the table comes from the standard rather than from
        // whatever the sampled files happened to contain.
        yield 'double prime quotation' => ['〝', VerticalForm::ROTATE_90];
        yield 'double prime close' => ['〟', VerticalForm::ROTATE_90];
        yield 'full-width low line' => ['＿', VerticalForm::ROTATE_90];
        yield 'full-width vertical line' => ['｜', VerticalForm::ROTATE_90];
        yield 'em dash' => ['—', VerticalForm::ROTATE_90];
        yield 'arrow' => ['→', VerticalForm::ROTATE_90];
        yield 'parallel to' => ['∥', VerticalForm::ROTATE_90];

        // Two the hand-written table got wrong, in opposite directions: a
        // double vertical line is already vertical, and a presentation form
        // for a vertical em dash is already the vertical form. Turning either
        // would undo what it is.
        yield 'double vertical line' => ['‖', VerticalForm::UPRIGHT];
        yield 'vertical em dash presentation form' => ['︱', VerticalForm::UPRIGHT];

        yield 'small tsu' => ['っ', VerticalForm::SHIFT_SMALL_KANA];
        yield 'small ya' => ['ゃ', VerticalForm::SHIFT_SMALL_KANA];
        yield 'small katakana a' => ['ァ', VerticalForm::SHIFT_SMALL_KANA];
        yield 'small ke' => ['ヶ', VerticalForm::SHIFT_SMALL_KANA];

        // Measured as not moving at all: it is already centred in its box.
        yield 'katakana middle dot' => ['・', VerticalForm::UPRIGHT];

        yield 'kanji' => ['本', VerticalForm::UPRIGHT];
        yield 'full-size kana' => ['つ', VerticalForm::UPRIGHT];
        yield 'full-width latin' => ['Ａ', VerticalForm::UPRIGHT];

        // A script that reads sideways does so as a run, so letters are absent
        // from the table on purpose — LatinInVertical turns them, and turning
        // each glyph on its own would look nothing like the intent.
        yield 'latin letter' => ['A', VerticalForm::UPRIGHT];
        yield 'greek letter' => ['α', VerticalForm::UPRIGHT];
        yield 'cyrillic letter' => ['д', VerticalForm::UPRIGHT];
        yield 'digit' => ['7', VerticalForm::UPRIGHT];

        // A squared abbreviation is `Tu`, but the transform is a different
        // glyph rather than a displacement; inventing one would be worse than
        // leaving it be.
        yield 'squared abbreviation' => ['㍻', VerticalForm::UPRIGHT];
    }

    #[Test]
    #[DataProvider('characters')]
    public function it_classifies_by_what_the_character_needs(string $character, VerticalForm $expected): void
    {
        self::assertSame($expected, VerticalForm::of($character));
    }

    #[Test]
    public function only_the_shifting_forms_have_an_offset(): void
    {
        self::assertSame([0.0, 0.0], VerticalForm::UPRIGHT->offset());
        self::assertSame([0.0, 0.0], VerticalForm::ROTATE_90->offset());
        self::assertSame([0.64, 0.65], VerticalForm::SHIFT_UPPER_RIGHT->offset());
        self::assertSame([0.12, 0.11], VerticalForm::SHIFT_SMALL_KANA->offset());
    }

    /**
     * The one the sample PDFs were reported for: a full-width exclamation mark
     * set through an embedded TrueType stood two thirds of an em above and to
     * the right of its column, overlapping the character before it.
     */
    #[Test]
    public function an_exclamation_mark_stays_in_its_cell(): void
    {
        foreach (['！', '？'] as $character) {
            self::assertSame(VerticalForm::UPRIGHT, VerticalForm::of($character));
            self::assertSame([0.0, 0.0], VerticalForm::of($character)->offset());
        }

        // …while the character it was being confused with still moves.
        self::assertNotSame([0.0, 0.0], VerticalForm::of('。')->offset());
    }

    #[Test]
    public function the_displacements_can_be_set_for_a_face_that_disagrees(): void
    {
        // Two faces measured 0.03 em apart on the small kana, so a fixed number
        // can only ever be the middle of a range.
        $style = new VerticalFormStyle(upperRightDx: 0.55, upperRightDy: 0.58, smallKanaDx: 0.18, smallKanaDy: 0.10);

        self::assertSame([0.55, 0.58], VerticalForm::SHIFT_UPPER_RIGHT->offset($style));
        self::assertSame([0.18, 0.10], VerticalForm::SHIFT_SMALL_KANA->offset($style));
        self::assertSame([0.0, 0.0], VerticalForm::ROTATE_90->offset($style));
    }

    #[Test]
    public function the_table_is_the_standards_and_covers_more_than_the_samples(): void
    {
        // Ranges the sampled novels never contained, classified without anyone
        // having to notice them: Latin-1, CJK compatibility, small extensions.
        self::assertSame(VerticalForm::ROTATE_90, VerticalForm::of("\u{00AB}"));        // « Latin-1
        self::assertSame(VerticalForm::ROTATE_90, VerticalForm::of("\u{FE59}"));        // ﹙ small form
        self::assertSame(VerticalForm::SHIFT_SMALL_KANA, VerticalForm::of("\u{31F0}")); // ㇰ katakana extension
        self::assertSame(VerticalForm::UPRIGHT, VerticalForm::of("\u{4E00}"));          // 一 ideograph
    }

    #[Test]
    public function a_full_stop_moves_further_than_a_small_kana(): void
    {
        // Both go to the upper right; the punctuation goes most of a cell, the
        // kana barely a sixth of one.
        [$punctuationX, $punctuationY] = VerticalForm::SHIFT_UPPER_RIGHT->offset();
        [$kanaX, $kanaY] = VerticalForm::SHIFT_SMALL_KANA->offset();

        self::assertGreaterThan($kanaX, $punctuationX);
        self::assertGreaterThan($kanaY, $punctuationY);
    }
}
