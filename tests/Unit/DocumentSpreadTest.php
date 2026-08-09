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
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\BindingEdge;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Page\ReadingDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Facing pages: a page number belongs on the fore-edge, which changes sides
 * with every leaf, and a bound book's text block sits off-centre.
 */
#[CoversClass(Document::class)]
#[CoversClass(BindingEdge::class)]
#[CoversClass(FlowRegion::class)]
final class DocumentSpreadTest extends TestCase
{
    /**
     * The x of every page-number draw, in page order.
     *
     * @return list<float>
     */
    private static function numberPositions(string $pdf): array
    {
        // The footer positions with Td, not a text matrix.
        \preg_match_all("~([\\d.]+) [\\d.]+ Td\n\\((\\d+)\\) Tj~", $pdf, $m, \PREG_SET_ORDER);

        return \array_values(\array_map(static fn (array $hit): float => (float) $hit[1], $m));
    }

    private static function numbered(Alignment|BindingEdge $alignment, ReadingDirection $direction): string
    {
        $pdf = new Document(credit: CreditOptions::disabled(), direction: $direction);
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', alignment: $alignment));
        for ($page = 0; $page < 4; $page++) {
            $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);
        }

        return $pdf->output();
    }

    #[Test]
    public function a_fixed_side_stays_on_that_side(): void
    {
        $positions = self::numberPositions(self::numbered(Alignment::RIGHT, ReadingDirection::LEFT_TO_RIGHT));

        self::assertCount(4, $positions);
        self::assertCount(1, \array_unique($positions), 'RIGHT should not move between pages.');
    }

    #[Test]
    public function the_fore_edge_changes_sides_with_every_leaf(): void
    {
        $positions = self::numberPositions(self::numbered(BindingEdge::OUTER, ReadingDirection::LEFT_TO_RIGHT));

        self::assertCount(4, $positions);
        // Left-bound: page 1 is a recto with its gutter on the left, so the
        // fore-edge — and the number — is on the right.
        self::assertGreaterThan($positions[1], $positions[0]);
        self::assertSame($positions[0], $positions[2]);
        self::assertSame($positions[1], $positions[3]);
    }

    #[Test]
    public function a_right_bound_book_mirrors_that(): void
    {
        $leftBound = self::numberPositions(self::numbered(BindingEdge::OUTER, ReadingDirection::LEFT_TO_RIGHT));
        $rightBound = self::numberPositions(self::numbered(BindingEdge::OUTER, ReadingDirection::RIGHT_TO_LEFT));

        // Same pages, opposite sides: the gutter moved to the other edge.
        self::assertSame($leftBound[0], $rightBound[1]);
        self::assertSame($leftBound[1], $rightBound[0]);
    }

    #[Test]
    public function the_gutter_is_the_fore_edges_opposite(): void
    {
        $outer = self::numberPositions(self::numbered(BindingEdge::OUTER, ReadingDirection::LEFT_TO_RIGHT));
        $inner = self::numberPositions(self::numbered(BindingEdge::INNER, ReadingDirection::LEFT_TO_RIGHT));

        self::assertSame($outer[0], $inner[1]);
        self::assertSame($outer[1], $inner[0]);
    }

    #[Test]
    public function the_credit_follows_the_page_number_to_the_other_corner(): void
    {
        $pdf = new Document();
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', alignment: BindingEdge::OUTER));
        $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);
        $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);
        $out = $pdf->output();

        \preg_match_all('~([\d.]+) [\d.]+ Td\n\(Powered by MejiroPDF\) Tj~', $out, $m);
        self::assertCount(2, $m[1]);
        // It alternates too — otherwise it would land on the number's corner
        // every other page.
        self::assertNotSame($m[1][0], $m[1][1]);
    }

    #[Test]
    public function a_gutter_pushes_the_text_block_off_centre_and_alternates(): void
    {
        $body = \implode("\n\n", \array_fill(0, 12, \str_repeat('word ', 40)));

        $centred = new Document(credit: CreditOptions::disabled());
        $centred->addPage();
        $centred->flowInto(FlowRegion::horizontal(x: 25, topY: 25, width: 160, bottomY: 90), $body, font: StandardFont::HELVETICA);

        $bound = new Document(credit: CreditOptions::disabled());
        $bound->addPage();
        $bound->flowInto(
            FlowRegion::horizontal(x: 25, topY: 25, width: 160, bottomY: 90),
            $body,
            font: StandardFont::HELVETICA,
            gutter: 10.0,
        );

        self::assertGreaterThan(1, $centred->pageCount());
        self::assertSame($centred->pageCount(), $bound->pageCount(), 'A gutter must not change where the pages break.');

        // Page 1 (recto, left-bound) moves right by 10 mm; page 2 moves left.
        $centredX = self::firstGlyphXPerPage($centred->output());
        $boundX = self::firstGlyphXPerPage($bound->output());
        self::assertEqualsWithDelta($centredX[0] + 10.0 * 2.8346456692913, $boundX[0], 0.05);
        self::assertEqualsWithDelta($centredX[1] - 10.0 * 2.8346456692913, $boundX[1], 0.05);
    }

    #[Test]
    public function a_vertical_book_shifts_along_its_own_block_axis(): void
    {
        // The vertical block axis is the page's x, and blockAt() derives the
        // drawn position from the plan rather than from the region's edges — so
        // a shift applied to those edges would quietly do nothing here.
        $region = FlowRegion::vertical(rightX: 190, topY: 25, columnHeight: 240, leftX: 20);

        $recto = $region->shiftedForSpread(10.0, physicalPage: 1, rightBound: true);
        $verso = $region->shiftedForSpread(10.0, physicalPage: 2, rightBound: true);

        // Right-bound recto: gutter on the right, so the block moves left.
        self::assertSame($region->blockAt($region->planStart()) - 10.0, $recto->blockAt($region->planStart()));
        self::assertSame($region->blockAt($region->planStart()) + 10.0, $verso->blockAt($region->planStart()));
    }

    #[Test]
    public function no_gutter_leaves_the_region_exactly_as_it_was(): void
    {
        $region = FlowRegion::horizontal(x: 25, topY: 25, width: 160, bottomY: 275);

        self::assertSame($region, $region->shiftedForSpread(0.0, 1, false));
    }

    /** @return list<float> x of the first glyph drawn on each page. */
    private static function firstGlyphXPerPage(string $pdf): array
    {
        $found = [];
        foreach (\explode('/Type /Page', $pdf) as $chunk) {
            if (\preg_match('~1 0 0\.0000 1 ([\d.]+) ~', $chunk, $m) === 1) {
                $found[] = (float) $m[1];
            }
        }

        return $found;
    }
}
