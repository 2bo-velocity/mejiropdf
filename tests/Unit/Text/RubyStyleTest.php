<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. This program is distributed WITHOUT ANY WARRANTY; see the GNU
 * Affero General Public License <https://www.gnu.org/licenses/> for details.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\EmphasisMarkStyle;
use MejiroPDF\Text\RubyAlignment;
use MejiroPDF\Text\RubyStyle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RubyStyleTest extends TestCase
{
    #[Test]
    public function ruby_defaults_to_half_the_parent_size(): void
    {
        // JIS X 4051 puts furigana at half the parent's size.
        self::assertSame(6.0, (new RubyStyle())->sizeFor(12.0));
    }

    #[Test]
    public function an_explicit_ruby_size_wins_over_the_derived_one(): void
    {
        self::assertSame(5.0, (new RubyStyle(fontSize: 5.0))->sizeFor(12.0));
    }

    #[Test]
    public function the_reserved_band_covers_the_ruby_and_its_gap(): void
    {
        // What the line must grow by so ruby cannot touch the line above.
        $style = new RubyStyle(fontSize: 5.0, offset: 2.0);

        self::assertSame(7.0, $style->bandFor(12.0));
    }

    #[Test]
    public function a_larger_gap_reserves_more_room(): void
    {
        $tight = new RubyStyle(offset: 0.0);
        $loose = new RubyStyle(offset: 4.0);

        self::assertGreaterThan($tight->bandFor(12.0), $loose->bandFor(12.0));
    }

    #[Test]
    public function ruby_is_evenly_distributed_by_default(): void
    {
        self::assertSame(RubyAlignment::JIS_EQUAL_SPACE, (new RubyStyle())->alignment);
    }

    #[Test]
    public function a_non_positive_ruby_size_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RubyStyle(fontSize: 0.0);
    }

    #[Test]
    public function a_negative_gap_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RubyStyle(offset: -0.1);
    }

    #[Test]
    public function emphasis_marks_default_to_a_filled_circle_at_a_third_of_the_parent(): void
    {
        $style = new EmphasisMarkStyle();

        self::assertSame(EmphasisMarkStyle::FILLED_CIRCLE, $style->mark);
        self::assertEqualsWithDelta(4.2, $style->sizeFor(12.0), 0.001);
    }

    #[Test]
    public function an_unrecognised_mark_is_reported_rather_than_rejected(): void
    {
        // Construction must not fail: an unknown mark degrades to a filled
        // disc at draw time instead of taking down a whole document.
        $style = new EmphasisMarkStyle(mark: '★');

        self::assertFalse($style->isKnownMark());
        self::assertTrue((new EmphasisMarkStyle())->isKnownMark());
    }
}
