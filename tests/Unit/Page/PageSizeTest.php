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

namespace MejiroPDF\Tests\Unit\Page;

use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageSizeTest extends TestCase
{
    /**
     * The preset lineup shared with MejiroPDF Studio, in millimetres.
     *
     * @return array<string, array{PageSize, float, float}>
     */
    public static function presets(): array
    {
        return [
            'A3' => [PageSize::a3(), 297.0, 420.0],
            'A4' => [PageSize::a4(), 210.0, 297.0],
            'A5' => [PageSize::a5(), 148.0, 210.0],
            'B5 (JIS)' => [PageSize::b5(), 182.0, 257.0],
            'Letter' => [PageSize::letter(), 215.9, 279.4],
            'B6 / 四六判' => [PageSize::b6(), 128.0, 182.0],
            '文庫判' => [PageSize::bunko(), 105.0, 148.0],
        ];
    }

    #[Test]
    #[DataProvider('presets')]
    public function presets_measure_their_documented_size(PageSize $size, float $widthMm, float $heightMm): void
    {
        self::assertEqualsWithDelta(PageSize::mm($widthMm), $size->width, 0.01);
        self::assertEqualsWithDelta(PageSize::mm($heightMm), $size->height, 0.01);
    }

    #[Test]
    public function b5_is_the_jis_size_not_the_iso_one(): void
    {
        // JIS B5 is 182x257mm; ISO B5 (176x250mm) is a different paper and the
        // wrong one for Japanese business forms and books.
        self::assertEqualsWithDelta(PageSize::mm(182.0), PageSize::b5()->width, 0.01);
        self::assertEqualsWithDelta(PageSize::mm(257.0), PageSize::b5()->height, 0.01);
    }

    #[Test]
    #[DataProvider('presets')]
    public function presets_swap_their_axes_when_landscape(PageSize $portrait, float $widthMm, float $heightMm): void
    {
        $landscape = $portrait->orient(Orientation::LANDSCAPE);

        self::assertEqualsWithDelta($portrait->height, $landscape->width, 0.01);
        self::assertEqualsWithDelta($portrait->width, $landscape->height, 0.01);
        self::assertGreaterThan($landscape->height, $landscape->width);
    }

    #[Test]
    public function a_custom_size_can_be_given_in_millimetres(): void
    {
        $size = PageSize::fromMillimeters(100.0, 200.0);

        self::assertEqualsWithDelta(PageSize::mm(100.0), $size->width, 0.01);
        self::assertEqualsWithDelta(PageSize::mm(200.0), $size->height, 0.01);
    }

    #[Test]
    public function a_custom_size_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PageSize::fromMillimeters(0.0, 200.0);
    }
}
