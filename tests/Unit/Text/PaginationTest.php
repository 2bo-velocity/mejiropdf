<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Uniform 10-unit lines in a [0, 100] area → 10 lines per page, minLines = 2.
 */
#[CoversClass(Pagination::class)]
final class PaginationTest extends TestCase
{
    /**
     * @param  list<int> $paragraphSizes lines per paragraph, in order
     * @return array{list<float>, list<bool>}
     */
    private function build(array $paragraphSizes): array
    {
        $heights = [];
        $ends = [];
        foreach ($paragraphSizes as $size) {
            for ($i = 0; $i < $size; $i++) {
                $heights[] = 10.0;
                $ends[] = $i === $size - 1;
            }
        }

        return [$heights, $ends];
    }

    #[Test]
    public function a_paragraph_that_fits_stays_on_one_page(): void
    {
        [$heights, $ends] = $this->build([5]);

        $plan = Pagination::plan($heights, $ends, 0.0, 100.0, 2);

        self::assertSame(0, $plan[4]['page']);
        self::assertEqualsWithDelta(40.0, $plan[4]['y'], 0.001);
    }

    #[Test]
    public function a_long_paragraph_splits_across_pages(): void
    {
        [$heights, $ends] = $this->build([15]);

        $plan = Pagination::plan($heights, $ends, 0.0, 100.0, 2);

        self::assertSame(0, $plan[9]['page']);
        self::assertSame(1, $plan[10]['page']);
        self::assertEqualsWithDelta(0.0, $plan[10]['y'], 0.001); // continues at the top
    }

    #[Test]
    public function an_orphan_paragraph_is_moved_to_the_next_page(): void
    {
        // Paragraph A fills 9 lines (bottom line at y=80); B would start at y=90
        // with room for only one line — fewer than minLines, so B moves whole.
        [$heights, $ends] = $this->build([9, 5]);

        $plan = Pagination::plan($heights, $ends, 0.0, 100.0, 2);

        self::assertSame(0, $plan[8]['page']);   // A's last line
        self::assertSame(1, $plan[9]['page']);   // B's first line, pushed on
    }

    #[Test]
    public function a_widow_line_is_pulled_back_to_keep_min_lines_together(): void
    {
        // A = 7 lines (last at y=60); B = 4 lines from y=70 where 3 would fit.
        // Naively B splits 3 + 1 (a widow); control makes it 2 + 2.
        [$heights, $ends] = $this->build([7, 4]);

        $plan = Pagination::plan($heights, $ends, 0.0, 100.0, 2);

        // B is lines 7..10. Line 9 is pulled to page 1 to avoid the lone widow.
        self::assertSame(0, $plan[8]['page']);
        self::assertSame(1, $plan[9]['page']);
        self::assertSame(1, $plan[10]['page']);
    }
}
