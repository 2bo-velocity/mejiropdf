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
use MejiroPDF\Page\CreditOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The O(1) memory model, asserted where it is easiest to lose: a flow long
 * enough to span many pages.
 *
 * This guards a claim that was false for a long time without anything failing.
 * Both causes were invisible to every other test — line breaking materialised
 * every paragraph of the text up front, and smart quotes split the whole book
 * into one PHP string per character. Neither changes a byte of output, so only
 * a measurement can see them.
 */
#[CoversClass(Document::class)]
final class DocumentFlowMemoryTest extends TestCase
{
    private const string PARAGRAPH = 'MejiroPDF streams each page straight to the output, so this '
        . 'document stays flat in memory however many pages the text runs to. Smart quotes, '
        . "em--dashes, an apostrophe or two and \"a quotation\" all apply as the body flows.";

    #[Test]
    public function flowing_eight_times_the_text_does_not_cost_more_memory(): void
    {
        $small = self::peakOfFlow(100);
        $large = self::peakOfFlow(800);

        // Eight times the text, over eight times the pages. Anything holding
        // the document — lines, units, per-character strings — shows up here as
        // a multiple; the bound is loose enough that ordinary allocator noise
        // does not.
        self::assertLessThan(
            $small * 2.0,
            $large,
            \sprintf(
                'Flow memory should not follow the page count: %.2f MB for 100 paragraphs, %.2f MB for 800.',
                $small / 1048576,
                $large / 1048576,
            ),
        );
    }

    /** Peak bytes allocated while flowing $paragraphs paragraphs. */
    private static function peakOfFlow(int $paragraphs): int
    {
        $body = \implode("\n\n", \array_fill(0, $paragraphs, self::PARAGRAPH));

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        \gc_collect_cycles();
        \memory_reset_peak_usage();
        $before = \memory_get_usage();

        $pdf->flowInto(
            FlowRegion::horizontal(x: 25, topY: 25, width: 160, bottomY: 275),
            $body,
            font: StandardFont::HELVETICA,
            size: 11.0,
        );

        $peak = \memory_get_peak_usage() - $before;
        self::assertGreaterThan(1, $pdf->pageCount()); // the flow really did paginate

        return $peak;
    }
}
