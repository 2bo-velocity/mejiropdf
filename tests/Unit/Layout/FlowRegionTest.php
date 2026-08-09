<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Layout;

use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowRegion::class)]
final class FlowRegionTest extends TestCase
{
    #[Test]
    public function horizontal_maps_the_block_axis_downward(): void
    {
        $region = FlowRegion::horizontal(x: 20.0, topY: 30.0, width: 170.0, bottomY: 280.0);

        self::assertFalse($region->isVertical());
        self::assertSame(250.0, $region->depth());
        // Horizontal already increases downward, so the planner sees the raw
        // millimetres and its arithmetic is untouched.
        self::assertSame(30.0, $region->planStart());
        self::assertSame(280.0, $region->planLimit());
        self::assertSame(45.0, $region->blockAt(45.0));
        self::assertSame([20.0, 45.0], $region->originAt(45.0));
        self::assertSame([20.0, 45.0, 170.0, 60.0], $region->boxAt(45.0, 60.0));
    }

    #[Test]
    public function vertical_maps_the_block_axis_leftward(): void
    {
        $region = FlowRegion::vertical(rightX: 190.0, topY: 25.0, columnHeight: 240.0, leftX: 20.0);

        self::assertTrue($region->isVertical());
        self::assertSame(170.0, $region->depth());
        // Negated, so the planner still sees an axis that grows from start to
        // limit even though the page coordinate is shrinking.
        self::assertSame(-190.0, $region->planStart());
        self::assertSame(-20.0, $region->planLimit());
        self::assertGreaterThan($region->planStart(), $region->planLimit());
        self::assertSame(175.0, $region->blockAt(-175.0));   // leftward = smaller x
        // A column run is anchored at its right edge, and each line starts at
        // the top of the column.
        self::assertSame([175.0, 25.0], $region->originAt(-175.0));
        // …so its box begins one extent further left than where drawing began.
        self::assertSame([115.0, 25.0, 60.0, 240.0], $region->boxAt(-175.0, 60.0));
    }

    #[Test]
    public function signing_the_block_axis_is_its_own_inverse(): void
    {
        foreach ([
            FlowRegion::horizontal(20.0, 30.0, 170.0, 280.0),
            FlowRegion::vertical(190.0, 25.0, 240.0, 20.0),
        ] as $region) {
            self::assertSame($region->blockStart, $region->blockAt($region->planStart()));
            self::assertSame($region->blockLimit, $region->blockAt($region->planLimit()));
        }
    }

    #[Test]
    public function the_generic_constructor_agrees_with_the_named_ones(): void
    {
        // Distinct values throughout: with x == topY a swapped mapping passes.
        self::assertEquals(
            FlowRegion::horizontal(20.0, 30.0, 170.0, 280.0),
            FlowRegion::for(WritingMode::HORIZONTAL_TB, 20.0, 30.0, 170.0, 280.0),
        );
        self::assertEquals(
            FlowRegion::vertical(190.0, 25.0, 240.0, 20.0),
            FlowRegion::for(WritingMode::VERTICAL_RL, 190.0, 25.0, 240.0, 20.0),
        );
    }

    #[Test]
    public function the_generic_constructor_keeps_x_and_top_y_apart(): void
    {
        $region = FlowRegion::for(WritingMode::HORIZONTAL_TB, 25.0, 36.0, 160.0, 280.0);

        self::assertSame(25.0, $region->inlineStart);   // x — where a line begins
        self::assertSame(36.0, $region->blockStart);    // topY — where lines stack from
    }

    #[Test]
    public function it_rejects_a_region_with_no_room_to_stack_lines(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowRegion::horizontal(x: 20.0, topY: 280.0, width: 170.0, bottomY: 30.0);
    }

    #[Test]
    public function it_rejects_a_vertical_region_whose_limit_is_right_of_its_start(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowRegion::vertical(rightX: 20.0, topY: 25.0, columnHeight: 240.0, leftX: 190.0);
    }

    #[Test]
    public function it_rejects_a_line_with_no_length(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowRegion::horizontal(x: 20.0, topY: 30.0, width: 0.0, bottomY: 280.0);
    }
}
