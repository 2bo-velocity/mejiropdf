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
use MejiroPDF\Text\LatinInVertical;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LatinInVertical::class)]
#[CoversClass(Document::class)]
final class LatinInVerticalTest extends TestCase
{
    /**
     * The text of every run drawn, decoded from its UTF-16BE operand.
     *
     * @return array{runs: list<string>, rotated: int}
     */
    private static function draw(string $text, LatinInVertical $mode, bool $autoTcy = true): array
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            $text,
            190,
            24,
            140,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
            options: new TypesetterOptions(
                writingMode: WritingMode::VERTICAL_RL,
                autoTcy: $autoTcy,
                latinInVertical: $mode,
            ),
        );
        $out = $pdf->output();
        \preg_match_all('~<([0-9A-F]+)> Tj~', $out, $hits);

        return [
            'runs' => \array_map(
                static fn (string $hex): string => (string) \mb_convert_encoding((string) \hex2bin($hex), 'UTF-8', 'UTF-16BE'),
                $hits[1],
            ),
            'rotated' => \substr_count($out, '0 -1 1 0'),
        ];
    }

    #[Test]
    public function upright_is_the_default_and_changes_nothing(): void
    {
        self::assertSame(LatinInVertical::UPRIGHT, (new TypesetterOptions())->latinInVertical);

        $drawn = self::draw('PC は https です', LatinInVertical::UPRIGHT);
        self::assertSame(['PC は https です'], $drawn['runs']);
        self::assertSame(0, $drawn['rotated']);
    }

    #[Test]
    public function full_width_gives_every_character_its_own_cell(): void
    {
        $drawn = self::draw('PC は https です', LatinInVertical::FULLWIDTH);

        self::assertSame(['ＰＣ は ｈｔｔｐｓ です'], $drawn['runs']);
        self::assertSame(0, $drawn['rotated']);
    }

    #[Test]
    public function rotate_turns_each_run_as_one_piece(): void
    {
        $drawn = self::draw('PC は https です', LatinInVertical::ROTATE);

        self::assertSame(['PC', ' は ', 'https', ' です'], $drawn['runs']);
        self::assertSame(2, $drawn['rotated'], 'Both Latin runs turn; the Japanese does not.');
    }

    #[Test]
    public function tcy_stands_short_runs_up_and_turns_long_ones(): void
    {
        $drawn = self::draw('PC は https です', LatinInVertical::TCY);

        self::assertSame(['PC', ' は ', 'https', ' です'], $drawn['runs']);
        // Only "https" turns; "PC" is two characters and fits one cell upright.
        self::assertSame(1, $drawn['rotated']);
    }

    #[Test]
    public function auto_tcy_keeps_its_say_over_a_bare_pair_of_digits(): void
    {
        // An author who left autoTcy on has already decided about "24"; the
        // broader setting must not overrule the narrower one.
        foreach ([LatinInVertical::FULLWIDTH, LatinInVertical::ROTATE, LatinInVertical::TCY] as $mode) {
            $drawn = self::draw('平成24年', $mode);
            self::assertContains('24', $drawn['runs'], "autoTcy should still hold under {$mode->name}.");
        }

        // With it off, the mode decides.
        $widened = self::draw('平成24年', LatinInVertical::FULLWIDTH, autoTcy: false);
        self::assertSame(['平成２４年'], $widened['runs']);
    }

    #[Test]
    public function horizontal_setting_is_untouched(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            'PC は https です',
            20,
            24,
            140,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
            options: new TypesetterOptions(latinInVertical: LatinInVertical::FULLWIDTH),
        );

        self::assertStringNotContainsString('ＰＣ', $pdf->output());
    }

    #[Test]
    public function widening_leaves_alone_what_is_already_wide(): void
    {
        self::assertSame('ＡＢ１２', LatinInVertical::toFullWidth('AB12'));
        self::assertSame("\u{3000}", LatinInVertical::toFullWidth(' '));
        self::assertSame('あ', LatinInVertical::toFullWidth('あ'));
    }
}
