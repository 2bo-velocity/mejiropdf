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
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentFlowTextTest extends TestCase
{
    #[Test]
    public function it_paginates_text_that_overflows_the_content_area(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $body = \implode("\n\n", \array_fill(0, 6, \str_repeat('word ', 40)));
        $finalY = $pdf->flowText($body, 20, 20, 170, 55, font: StandardFont::HELVETICA, size: 11.0);

        self::assertGreaterThan(1, $pdf->pageCount());   // spilled onto further pages
        self::assertLessThanOrEqual(55.0, $finalY);      // ends within the last page's area
        self::assertStringContainsString('(w) Tj', $pdf->output());
    }

    #[Test]
    public function short_text_stays_on_one_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $pdf->flowText('A short paragraph.', 20, 20, 170, 280, font: StandardFont::HELVETICA, size: 11.0);

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function flow_into_a_horizontal_region_matches_the_scalar_spelling(): void
    {
        $body = \implode("\n\n", \array_fill(0, 4, \str_repeat('word ', 30)));

        // x and topY differ: when they match, a mapping that swaps them still
        // produces identical output and the mistake goes unseen.
        $scalars = new Document(credit: CreditOptions::disabled());
        $scalars->addPage();
        $viaScalars = $scalars->flowText($body, 25, 36, 160, 120, font: StandardFont::HELVETICA, size: 11.0);

        $region = new Document(credit: CreditOptions::disabled());
        $region->addPage();
        $viaRegion = $region->flowInto(
            FlowRegion::horizontal(x: 25, topY: 36, width: 160, bottomY: 120),
            $body,
            font: StandardFont::HELVETICA,
            size: 11.0,
        );

        self::assertSame($viaScalars, $viaRegion);
        self::assertSame($scalars->pageCount(), $region->pageCount());
        self::assertSame($scalars->output(), $region->output());
    }

    #[Test]
    public function the_first_line_lands_where_the_arguments_put_it(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage(); // A4: 595.28 x 841.89 pt

        $pdf->flowText('Mm', 25, 36, 160, 280, font: StandardFont::HELVETICA, size: 11.5);

        // x = 25 mm = 70.87 pt; the first baseline sits 36 mm below the top
        // edge less the 0.8 em ascent: 841.89 - 102.05 - 9.20 = 730.64 pt.
        self::assertStringContainsString('1 0 0.0000 1 70.87 730.64 Tm', $pdf->output());
    }

    #[Test]
    public function it_flows_vertical_text_leftward_across_pages(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        // The region holds 170 mm / (10.5 pt × 1.6) ≈ 28 columns; each paragraph
        // here runs to about two, so this needs a second page.
        $body = \implode("\n\n", \array_fill(0, 20, \str_repeat('吾輩は猫である。名前はまだ無い。', 6)));
        $finalX = $pdf->flowInto(
            FlowRegion::vertical(rightX: 190, topY: 25, columnHeight: 240, leftX: 20),
            $body,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 10.5,
            options: TypesetterOptions::novel(),
        );

        self::assertGreaterThan(1, $pdf->pageCount());       // spilled onto further pages
        self::assertGreaterThanOrEqual(20.0, $finalX);       // stopped within the region
        self::assertLessThan(190.0, $finalX);                // and moved leftward off the start
        self::assertMatchesRegularExpression('~<[0-9A-F]+> Tj~', $pdf->output());
    }

    #[Test]
    public function short_vertical_text_stays_on_one_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $finalX = $pdf->flowInto(
            FlowRegion::vertical(rightX: 190, topY: 25, columnHeight: 240, leftX: 20),
            '短い段落。',
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 10.5,
            options: TypesetterOptions::novel(),
        );

        self::assertSame(1, $pdf->pageCount());
        // One column of 10.5 pt text at the default 1.6 line height ≈ 5.9 mm.
        self::assertEqualsWithDelta(190.0 - 5.9, $finalX, 0.2);
    }

    #[Test]
    public function the_scalar_spelling_reads_its_arguments_along_the_reading_flow(): void
    {
        $body = \str_repeat('吾輩は猫である。', 20);
        $options = TypesetterOptions::novel();

        $region = new Document(credit: CreditOptions::disabled());
        $region->addPage();
        $viaRegion = $region->flowInto(
            FlowRegion::vertical(rightX: 190, topY: 25, columnHeight: 240, leftX: 20),
            $body,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 10.5,
            options: $options,
        );

        $scalars = new Document(credit: CreditOptions::disabled());
        $scalars->addPage();
        // (x, topY) is the top-RIGHT corner, width is the column height and
        // bottomY is the leftmost x the columns may reach.
        $viaScalars = $scalars->flowText(
            $body,
            190,
            25,
            240,
            20,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 10.5,
            options: $options,
        );

        self::assertSame($viaRegion, $viaScalars);
        self::assertSame($region->output(), $scalars->output());
    }

    #[Test]
    public function it_rejects_a_region_that_disagrees_with_the_writing_mode(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 170, bottomY: 280),
            'x',
            font: CidFontJapanese::HEISEI_MIN_W3,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
    }
}
