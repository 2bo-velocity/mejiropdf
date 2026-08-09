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
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\JapaneseSpacing;
use MejiroPDF\Text\PunctuationClass;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JapaneseSpacing::class)]
#[CoversClass(PunctuationClass::class)]
#[CoversClass(Document::class)]
final class JapaneseSpacingTest extends TestCase
{
    private const float SIZE = 12.0;
    private const float EM = self::SIZE;

    /**
     * Where each character was drawn, in order.
     *
     * @return array<string, float> character (with an index suffix) => x
     */
    private static function positions(string $text, JapaneseSpacing $spacing): array
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            $text,
            20,
            30,
            160,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            options: new TypesetterOptions(japaneseSpacing: $spacing),
        );
        \preg_match_all("~1 0 0\.0000 1 ([\d.]+) [\d.]+ Tm\n<([0-9A-F]+)> Tj~", $pdf->output(), $hits, \PREG_SET_ORDER);

        $found = [];
        foreach ($hits as $index => $hit) {
            $character = (string) \mb_convert_encoding((string) \hex2bin($hit[2]), 'UTF-8', 'UTF-16BE');
            $found[$index . ':' . $character] = (float) $hit[1];
        }

        return $found;
    }

    /** The advance from the character at $index to the next one drawn. */
    private static function advance(string $text, JapaneseSpacing $spacing, int $index): float
    {
        $xs = \array_values(self::positions($text, $spacing));

        return \round($xs[$index + 1] - $xs[$index], 2);
    }

    #[Test]
    public function nothing_moves_when_the_setting_is_off(): void
    {
        self::assertSame(JapaneseSpacing::NONE, (new TypesetterOptions())->japaneseSpacing);
        self::assertSame(
            self::positions('和文とRailsの間。）。', JapaneseSpacing::NONE),
            self::positions('和文とRailsの間。）。', JapaneseSpacing::NONE),
        );
    }

    #[Test]
    public function a_quarter_em_opens_between_japanese_and_latin(): void
    {
        $text = 'とRails';

        // と → R: one em plus a quarter, where before it was one em flat.
        self::assertSame(self::EM, self::advance($text, JapaneseSpacing::NONE, 0));
        self::assertSame(self::EM + self::EM * 0.25, self::advance($text, JapaneseSpacing::JIS_X_4051, 0));
    }

    #[Test]
    public function it_opens_on_the_way_back_out_too(): void
    {
        $text = 'Rsの';
        // s → の: the Latin's own width plus a quarter em.
        $solid = self::advance($text, JapaneseSpacing::NONE, 1);
        self::assertSame($solid + self::EM * 0.25, self::advance($text, JapaneseSpacing::JIS_X_4051, 1));
    }

    #[Test]
    public function full_width_latin_asks_for_no_gap(): void
    {
        // Ａ already occupies a cell of its own; a quarter em beside it would
        // be a hole, not a separation.
        self::assertSame(self::EM, self::advance('とＡ', JapaneseSpacing::JIS_X_4051, 0));
        self::assertSame(self::EM, self::advance('とＡ', JapaneseSpacing::NONE, 0));
    }

    #[Test]
    public function an_existing_space_is_left_to_do_its_job(): void
    {
        // Neither side of the seam is a letter, so nothing is added: the author
        // already separated them.
        self::assertSame(
            self::advance('と R', JapaneseSpacing::NONE, 0),
            self::advance('と R', JapaneseSpacing::JIS_X_4051, 0),
        );
    }

    #[Test]
    public function closing_punctuation_gives_up_its_empty_half(): void
    {
        // 。） and ）。 and 。「 — each pair holds a full em of nothing between
        // two marks whose ink sits in one half of the box.
        foreach (['。）', '）。', '。「', '」「'] as $pair) {
            self::assertSame(self::EM, self::advance($pair, JapaneseSpacing::NONE, 0), $pair);
            self::assertSame(self::EM * 0.5, self::advance($pair, JapaneseSpacing::JIS_X_4051, 0), $pair);
        }
    }

    #[Test]
    public function punctuation_beside_ordinary_text_keeps_its_width(): void
    {
        // Only a run of marks closes up; a mark against a character does not.
        self::assertSame(self::EM, self::advance('」4', JapaneseSpacing::JIS_X_4051, 0));
        self::assertSame(self::EM, self::advance('。あ', JapaneseSpacing::JIS_X_4051, 0));
        self::assertSame(self::EM, self::advance('（あ', JapaneseSpacing::JIS_X_4051, 0));
    }

    /** @return iterable<string, array{string, int}> */
    public static function marks(): iterable
    {
        yield 'full-width open paren' => ['（', PunctuationClass::OPEN];
        yield 'corner bracket open' => ['「', PunctuationClass::OPEN];
        yield 'full-width close paren' => ['）', PunctuationClass::CLOSE];
        yield 'corner bracket close' => ['」', PunctuationClass::CLOSE];
        yield 'ideographic comma' => ['、', PunctuationClass::CLOSE];
        yield 'ideographic full stop' => ['。', PunctuationClass::CLOSE];
        yield 'full-width exclamation' => ['！', PunctuationClass::CLOSE];
        yield 'full-width question' => ['？', PunctuationClass::CLOSE];
        yield 'kanji' => ['本', 0];
        yield 'latin letter' => ['A', 0];
        yield 'katakana middle dot' => ['・', 0];
    }

    #[Test]
    #[DataProvider('marks')]
    public function the_bracket_classes_come_from_unicode(string $character, int $expected): void
    {
        self::assertSame($expected, PunctuationClass::of((int) \mb_ord($character, 'UTF-8')));
    }

    #[Test]
    public function the_gap_disappears_at_a_line_end(): void
    {
        // A gap separates two characters; at a line end there is no second one,
        // so the line's own width must not include it — otherwise centring and
        // justification would both sit a quarter em off.
        $narrow = new Document(credit: CreditOptions::disabled());
        $narrow->addPage()->typeset(
            'あいうえおかきくけこR',
            20,
            30,
            30, // forces a break right at the Japanese/Latin seam
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            options: new TypesetterOptions(japaneseSpacing: JapaneseSpacing::JIS_X_4051),
        );

        // Nothing is dropped and nothing overflows the box.
        $out = $narrow->output();
        self::assertStringContainsString('Tj', $out);
        \preg_match_all("~1 0 0\.0000 1 ([\d.]+) [\d.]+ Tm~", $out, $m);
        $xs = \array_map('floatval', $m[1]);
        self::assertLessThanOrEqual(20.0 + 30.0 + 0.01, \max($xs) / 2.8346456692913 + 0.0);
    }
}
