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
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Layout\Section;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\RunningHeadOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(Section::class)]
#[CoversClass(RunningHeadOptions::class)]
final class DocumentSectionTest extends TestCase
{
    private const string BODY_PARAGRAPH = 'MejiroPDF streams each page straight to the output, so a chapter '
        . 'of any length stays flat in memory. This paragraph is repeated until the section runs over.';

    private static function body(int $paragraphs = 10): string
    {
        return \implode("\n\n", \array_fill(0, $paragraphs, self::BODY_PARAGRAPH));
    }

    private static function region(): FlowRegion
    {
        return FlowRegion::horizontal(x: 25, topY: 25, width: 160, bottomY: 120);
    }

    #[Test]
    public function it_sets_the_heading_then_the_body_and_records_a_bookmark(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->section(new Section('Chapter One', self::body(2)), self::region(), font: StandardFont::HELVETICA);
        $out = $pdf->output();

        self::assertStringContainsString('(C) Tj', $out);            // the heading is drawn
        self::assertStringContainsString('/Outlines', $out);         // and bookmarked
        self::assertStringContainsString('/Title (Chapter One)', $out);
    }

    #[Test]
    public function the_body_starts_below_the_heading_and_at_the_top_of_later_pages(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        // A distinct heading size, so the body's own first line can be picked
        // out — on page 1 the first thing drawn is the heading, not the body.
        $pdf->section(
            new Section('Chapter One', self::body(), titleSize: 16.0),
            self::region(),
            font: StandardFont::HELVETICA,
            size: 11.0,
        );

        $tops = self::segmentStarts($pdf->output(), 11.0);
        self::assertGreaterThan(1, \count($tops), 'The chapter should run past one page.');

        // PDF y grows upward, so "lower on the page" is a smaller number. The
        // body on page 1 sits below the heading and its gap; from page 2 on it
        // starts at the region's own top, leaving no room for a heading that is
        // not there.
        self::assertLessThan($tops[1], $tops[0], 'Page 1 should start lower than page 2.');
        for ($page = 2, $last = \count($tops); $page < $last; $page++) {
            self::assertEqualsWithDelta($tops[1], $tops[$page], 0.01, 'Every page after the first starts alike.');
        }
    }

    #[Test]
    public function a_chapter_opens_on_a_fresh_page_but_does_not_waste_a_blank_one(): void
    {
        $onFreshPage = new Document(credit: CreditOptions::disabled());
        $onFreshPage->addPage();
        $onFreshPage->section(new Section('One', self::body(1)), self::region(), font: StandardFont::HELVETICA);
        self::assertSame(1, $onFreshPage->pageCount(), 'The page was blank; no need to turn it.');

        $afterText = new Document(credit: CreditOptions::disabled());
        $afterText->addPage()->text(20, 20, 'front matter', font: StandardFont::HELVETICA);
        $afterText->section(new Section('One', self::body(1)), self::region(), font: StandardFont::HELVETICA);
        self::assertSame(2, $afterText->pageCount(), 'The page was written on, so the chapter turns it.');
    }

    #[Test]
    public function a_chapter_can_be_asked_to_continue_on_the_same_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 20, 'front matter', font: StandardFont::HELVETICA);
        $pdf->section(
            new Section('One', self::body(1), startsOnNewPage: false),
            self::region(),
            font: StandardFont::HELVETICA,
        );

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function a_chapter_can_decline_a_bookmark(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->section(
            new Section('Untitled', self::body(1), bookmarkLevel: null),
            self::region(),
            font: StandardFont::HELVETICA,
        );

        self::assertStringNotContainsString('/Outlines', $pdf->output());
    }

    #[Test]
    public function the_running_head_names_the_chapter_the_page_belongs_to(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setDefaultFont(StandardFont::HELVETICA);
        $pdf->setRunningHead(new RunningHeadOptions());
        $pdf->addPage();
        $pdf->section(new Section('One', self::body()), self::region(), font: StandardFont::HELVETICA);
        $pdf->section(new Section('Two', self::body()), self::region(), font: StandardFont::HELVETICA);
        $out = $pdf->output();

        // Drawn on continuation pages, not on the openings, where the heading
        // already says it.
        self::assertGreaterThan(0, \substr_count($out, '(One) Tj'));
        self::assertGreaterThan(0, \substr_count($out, '(Two) Tj'));
    }

    #[Test]
    public function no_running_head_is_drawn_before_the_first_chapter(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setDefaultFont(StandardFont::HELVETICA);
        $pdf->setRunningHead(new RunningHeadOptions(skipChapterOpenings: false));
        $pdf->addPage()->text(20, 20, 'front matter', font: StandardFont::HELVETICA);
        $pdf->section(new Section('One', self::body(1)), self::region(), font: StandardFont::HELVETICA);

        // Page 1 is front matter: it belongs to no chapter.
        $pages = \explode('/Type /Page', $pdf->output());
        self::assertStringNotContainsString('(One) Tj', $pages[1] ?? '');
    }

    #[Test]
    public function a_chapter_can_suppress_its_own_running_head(): void
    {
        self::assertNull((new Section('One', 'x', runningHead: ''))->runningHeadText());
        self::assertSame('Short', (new Section('A Very Long Title', 'x', runningHead: 'Short'))->runningHeadText());
        self::assertSame('One', (new Section('One', 'x'))->runningHeadText());
    }

    #[Test]
    public function a_negative_gap_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Section('One', 'x', gap: -1.0);
    }

    /**
     * Where the body starts on each page, found by watching the baseline jump
     * back up.
     *
     * Splitting the file on `/Type /Page` would be the obvious approach and is
     * wrong: content streams are written as pages are committed, so they all
     * precede the page dictionaries and land in one lump. Within a page the
     * baseline only descends, so a rise is a page break.
     *
     * @param  float $size the body's size, to skip a heading set at another
     * @return list<float>
     */
    private static function segmentStarts(string $pdf, float $size): array
    {
        \preg_match_all(\sprintf('~/F\d+ %.2F Tf\n1 0 0\.0000 1 [\d.]+ ([\d.]+) Tm~', $size), $pdf, $m);

        $starts = [];
        $previous = null;
        foreach ($m[1] as $raw) {
            $y = (float) $raw;
            if ($previous === null || $y > $previous) {
                $starts[] = $y;
            }
            $previous = $y;
        }

        return $starts;
    }
}
