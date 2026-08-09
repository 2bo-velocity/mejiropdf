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
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TextStyle;
use MejiroPDF\Text\TextTokenizer;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A subheading left alone at the foot of a page announces nothing: the reader
 * turns the leaf to find what it announced. It holds on to the opening lines of
 * what follows, and travels with them if they will not fit.
 */
#[CoversClass(Document::class)]
#[CoversClass(TextStyle::class)]
#[CoversClass(TextTokenizer::class)]
final class DocumentKeepWithNextTest extends TestCase
{
    private const string PARAGRAPH = '　本文が続く。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。組版の話をする。';

    /** @return list<array{0: string, 1: float}> */
    private static function glyphs(string $pdf): array
    {
        \preg_match_all("~1 0 0\.0000 1 [\d.]+ ([\d.]+) Tm\n<([0-9A-F]+)> Tj~", $pdf, $hits, \PREG_SET_ORDER);

        return \array_map(static fn (array $hit): array => [
            (string) \mb_convert_encoding((string) \hex2bin($hit[2]), 'UTF-8', 'UTF-16BE'),
            (float) $hit[1],
        ], $hits);
    }

    /**
     * How many lines of anything are drawn below the heading on the heading's
     * own page. Baselines climb again at a page break, which is where counting
     * stops.
     */
    private static function linesUnderTheHeading(string $pdf): int
    {
        $glyphs = self::glyphs($pdf);

        $at = null;
        foreach ($glyphs as $index => [$text]) {
            if ($text === '節') {
                $at = $index;
                break;
            }
        }
        self::assertNotNull($at, 'the heading was not drawn at all');

        $heading = $glyphs[$at][1];
        $baselines = [];
        foreach (\array_slice($glyphs, $at + 1) as [, $y]) {
            if ($y > $heading) {
                break; // a new page
            }
            if ($y < $heading - 1.0) {
                $baselines[\number_format($y, 1, '.', '')] = true;
            }
        }

        return \count($baselines);
    }

    /** @param list<string> $paragraphs */
    private static function flowed(array $paragraphs, float $bottomY, ?TypesetterOptions $options = null): string
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: $bottomY),
            \implode("\n\n", $paragraphs),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
            options: $options,
        );

        return $pdf->output();
    }

    /** @return list<string> */
    private static function chapter(bool $keep): array
    {
        $heading = $keep ? '[第一節　見出し]{.bold .keepnext}' : '[第一節　見出し]{.bold}';

        return [
            ...\array_fill(0, 5, self::PARAGRAPH),
            $heading,
            ...\array_fill(0, 4, self::PARAGRAPH),
        ];
    }

    #[Test]
    public function the_tokenizer_reads_the_attribute(): void
    {
        self::assertTrue(TextTokenizer::tokenize('[見出し]{.keepnext}')[0]->style->keepWithNext);
        self::assertFalse(TextTokenizer::tokenize('見出し')[0]->style->keepWithNext);
    }

    #[Test]
    public function a_heading_stranded_at_the_foot_moves_to_the_next_page(): void
    {
        // 120 mm of depth puts the heading last on its page — verified by the
        // plain run below, which is the same text without the attribute.
        self::assertSame(0, self::linesUnderTheHeading(self::flowed(self::chapter(false), 120.0)));
        self::assertGreaterThanOrEqual(2, self::linesUnderTheHeading(self::flowed(self::chapter(true), 120.0)));
    }

    #[Test]
    public function a_heading_with_room_below_it_does_not_move(): void
    {
        // Nothing is owed here, so the attribute must cost nothing: same bytes.
        foreach ([88.0, 104.0, 116.0] as $depth) {
            self::assertSame(
                self::glyphs(self::flowed(self::chapter(false), $depth)),
                self::glyphs(self::flowed(self::chapter(true), $depth)),
                \sprintf('%.0f mm', $depth),
            );
        }
    }

    #[Test]
    public function how_many_lines_it_holds_on_to_is_settable(): void
    {
        self::assertSame(2, (new TypesetterOptions())->keepWithNextLines);

        // Raising it only matters where the paragraph splits: a following
        // paragraph that fits whole has stranded nothing, however short it is.
        $options = new TypesetterOptions(keepWithNextLines: 4);
        self::assertSame(4, $options->withAlign($options->align)->keepWithNextLines);

        $held = self::linesUnderTheHeading(self::flowed(self::chapter(true), 120.0, $options));
        self::assertGreaterThanOrEqual(4, $held);
    }

    #[Test]
    public function a_short_paragraph_is_enough_on_its_own(): void
    {
        // The rule is "the opening of what follows", not "two lines": a one-line
        // paragraph that fits whole leaves nothing behind to turn the leaf for.
        $out = self::flowed(
            [...\array_fill(0, 5, self::PARAGRAPH), '[第一節　見出し]{.bold .keepnext}', '短い。'],
            120.0,
        );

        self::assertSame(1, self::linesUnderTheHeading($out));
    }

    #[Test]
    public function a_page_too_shallow_to_satisfy_the_rule_still_terminates(): void
    {
        // Moving a heading off a page it opens would put it at the foot of the
        // page before — the very thing being avoided — and then back again,
        // forever. Where the rule cannot be met it is dropped, not looped on.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: 30.0), // ~2 lines a page
            \implode("\n\n", self::chapter(true)),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );

        $glyphs = self::glyphs($pdf->output());
        self::assertContains('節', \array_column($glyphs, 0));
        self::assertLessThan(200, $pdf->pageCount());
    }

    #[Test]
    public function text_that_asks_for_nothing_is_laid_out_exactly_as_before(): void
    {
        $plain = self::chapter(false);

        self::assertSame(
            self::glyphs(self::flowed($plain, 120.0)),
            self::glyphs(self::flowed($plain, 120.0, new TypesetterOptions(keepWithNextLines: 6))),
        );
    }
}
