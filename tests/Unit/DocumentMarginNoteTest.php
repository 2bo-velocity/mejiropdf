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
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\FootnoteStyle;
use MejiroPDF\Text\NotePlacement;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 傍注: short notes in the fore-edge margin, beside the line that refers to
 * them. Where a note is set is a decision about the book, so it lives in the
 * style — the author writes `((…))` either way.
 */
#[CoversClass(Document::class)]
#[CoversClass(FootnoteStyle::class)]
#[CoversClass(NotePlacement::class)]
final class DocumentMarginNoteTest extends TestCase
{
    private const string PARAGRAPH = '　本文が続く。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。';

    private const float SIZE = 10.0;

    /**
     * The notes on each page — they are the only text set at the note size.
     *
     * @return list<list<array{0: float, 1: float}>> x, y in millimetres, page by page
     */
    private static function notesByPage(string $pdf): array
    {
        \preg_match_all("~stream\n(.*?)\nendstream~s", $pdf, $streams);

        $pages = [];
        foreach ($streams[1] as $stream) {
            if (!\str_contains($stream, \sprintf('%.2F Tf', self::SIZE))) {
                continue;   // not a page of body text
            }
            \preg_match_all(
                "~/F\d+ 8\.00 Tf\n1 0 0\.0000 1 ([\d.]+) ([\d.]+) Tm~",
                $stream,
                $hits,
                \PREG_SET_ORDER,
            );
            $pages[] = \array_map(static fn (array $hit): array => [
                (float) $hit[1] / PageSize::MM_TO_PT,
                297.0 - (float) $hit[2] / PageSize::MM_TO_PT,
            ], $hits);
        }

        return $pages;
    }

    private static function style(): FootnoteStyle
    {
        return new FootnoteStyle(
            placement: NotePlacement::SIDE_MARGIN,
            marginWidth: 28.0,
            marginGap: 4.0,
        );
    }

    private static function flowed(string $body, ?FootnoteStyle $style = null): string
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 35, topY: 20, width: 120, bottomY: 120),
            $body,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            footnotes: $style ?? self::style(),
        );

        return $pdf->output();
    }

    #[Test]
    public function a_note_is_set_in_the_margin_beside_the_line_that_calls_it(): void
    {
        $body = \implode("\n\n", [
            self::PARAGRAPH . '用語((傍注はここ。))を説明する。',
            ...\array_fill(0, 4, self::PARAGRAPH),
        ]);
        $pages = self::notesByPage(self::flowed($body));

        self::assertNotSame([], $pages[0]);
        // 35 + 120 + 4: past the fore edge of the body, not inside it.
        foreach ($pages[0] as [$x, $y]) {
            self::assertGreaterThanOrEqual(159.0, $x);
            self::assertLessThanOrEqual(159.0 + 28.0, $x);
            // Beside the body it refers from, not under it.
            self::assertLessThan(120.0, $y);
        }
    }

    #[Test]
    public function notes_close_together_are_pushed_apart_rather_than_overprinted(): void
    {
        $body = \implode("\n\n", [
            '一((最初の注。))と二((次の注。))が続く。' . self::PARAGRAPH,
            ...\array_fill(0, 4, self::PARAGRAPH),
        ]);
        $ys = \array_column(self::notesByPage(self::flowed($body))[0], 1);

        self::assertNotSame([], $ys);
        // Two notes from one line would otherwise share a baseline exactly.
        self::assertGreaterThan(1, \count(\array_unique(\array_map(
            static fn (float $y): string => \number_format($y, 1, '.', ''),
            $ys,
        ))));
    }

    #[Test]
    public function the_margin_is_the_fore_edge_and_so_changes_side_with_the_page(): void
    {
        // A left-bound book: an odd page is a recto and its fore edge is the
        // right, an even page a verso and its fore edge the left.
        $filler = \implode("\n\n", \array_fill(0, 6, self::PARAGRAPH));
        $body = \implode("\n\n", [
            "一((表の注。))。{$filler}",
            "二((次の注。))。{$filler}",
            "三((その次。))。{$filler}",
            $filler,
        ]);

        $pages = self::notesByPage(self::flowed($body));
        self::assertGreaterThan(1, \count($pages));

        $seen = 0;
        foreach ($pages as $index => $page) {
            if ($page === []) {
                continue;
            }
            $seen++;
            $xs = \array_column($page, 0);
            if (($index + 1) % 2 === 1) {
                self::assertGreaterThanOrEqual(159.0, \min($xs), \sprintf('recto %d', $index + 1));
            } else {
                self::assertLessThan(35.0, \max($xs), \sprintf('verso %d', $index + 1));
            }
        }
        // Both sides were actually exercised.
        self::assertGreaterThan(1, $seen);
    }

    #[Test]
    public function the_body_gives_up_nothing_for_them(): void
    {
        // A band at the foot is taken out of the body; a margin is not there to
        // begin with. The same prose sets identically with the notes stripped.
        $body = \implode("\n\n", [
            self::PARAGRAPH . '用語((傍注はここ。))を説明する。',
            ...\array_fill(0, 4, self::PARAGRAPH),
        ]);

        $withNotes = self::flowed($body);
        $withoutNotes = self::flowed(\str_replace('((傍注はここ。))', '', $body));

        $bodyOf = static function (string $pdf): array {
            \preg_match_all(
                \sprintf("~/F\d+ %.2F Tf\n1 0 0\.0000 1 ([\d.]+) ([\d.]+) Tm~", self::SIZE),
                $pdf,
                $hits,
            );

            return $hits[2];
        };
        // The mark itself adds a glyph, so compare the last line's baseline:
        // it must not have moved up to make room for anything.
        self::assertSame(\min($bodyOf($withoutNotes)), \min($bodyOf($withNotes)));
    }

    #[Test]
    public function the_foot_of_the_page_is_still_the_default(): void
    {
        self::assertSame(NotePlacement::PAGE_FOOT, (new FootnoteStyle())->placement);
        self::assertFalse((new FootnoteStyle())->inMargin());
        self::assertTrue(self::style()->inMargin());
    }

    #[Test]
    public function vertical_writing_is_refused_rather_than_guessed_at(): void
    {
        // Vertical text stacks its columns across the very margin a side note
        // would occupy, so where it should go is undecided, not merely unbuilt.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $pdf->flowInto(
            FlowRegion::vertical(rightX: 180, topY: 20, columnHeight: 200, leftX: 30),
            '本((注記))と',
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
            footnotes: self::style(),
        );
    }

    #[Test]
    public function margin_notes_sit_happily_beside_columns(): void
    {
        // Unlike a band at the foot, they take nothing from the region, so the
        // reason columns were refused does not apply.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 35, topY: 20, width: 120, bottomY: 120)->withColumns(2, 6.0),
            '一((注記。))と続く。' . self::PARAGRAPH,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: self::SIZE,
            footnotes: self::style(),
        );

        self::assertNotSame([], self::notesByPage($pdf->output())[0]);
    }

    #[Test]
    public function a_margin_note_needs_a_width(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FootnoteStyle(placement: NotePlacement::SIDE_MARGIN, marginWidth: 0.0);
    }
}
