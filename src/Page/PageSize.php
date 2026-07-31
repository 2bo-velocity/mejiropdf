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

namespace MejiroPDF\Page;

/**
 * An immutable page size in PDF points (1 pt = 1/72 inch).
 *
 * The public API of MejiroPDF speaks millimetres (the report-authoring unit);
 * this value object holds the points that the PDF /MediaBox requires and offers
 * mm <-> pt conversion helpers.
 */
final readonly class PageSize
{
    /** Points per millimetre (72 / 25.4). */
    public const float MM_TO_PT = 72.0 / 25.4;

    public function __construct(
        public float $width,
        public float $height,
    ) {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new \InvalidArgumentException('Page dimensions must be positive.');
        }
    }

    /**
     * Build from millimetres (e.g. custom sizes).
     */
    public static function fromMillimeters(float $widthMm, float $heightMm): self
    {
        return new self($widthMm * self::MM_TO_PT, $heightMm * self::MM_TO_PT);
    }

    public static function a3(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(297.0, 420.0)->orient($orientation);
    }

    public static function a4(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(210.0, 297.0)->orient($orientation);
    }

    public static function a5(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(148.0, 210.0)->orient($orientation);
    }

    /**
     * JIS B5 (182 x 257 mm) — the B-series size used for Japanese business
     * forms and books. Note this is JIS, not ISO B5 (176 x 250 mm).
     */
    public static function b5(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(182.0, 257.0)->orient($orientation);
    }

    public static function letter(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(215.9, 279.4)->orient($orientation);
    }

    /**
     * JIS B6 / 四六判 (128 x 182 mm) — the common trim for Japanese novels.
     */
    public static function b6(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(128.0, 182.0)->orient($orientation);
    }

    /**
     * 文庫判 (105 x 148 mm) — the pocket paperback trim, A6 sized.
     */
    public static function bunko(Orientation $orientation = Orientation::PORTRAIT): self
    {
        return self::fromMillimeters(105.0, 148.0)->orient($orientation);
    }

    /**
     * Return this size forced to the given orientation.
     */
    public function orient(Orientation $orientation): self
    {
        $isLandscape = $this->width > $this->height;
        $wantLandscape = $orientation === Orientation::LANDSCAPE;

        if ($isLandscape === $wantLandscape) {
            return $this;
        }

        return new self($this->height, $this->width);
    }

    /**
     * Convert a millimetre length into points.
     */
    public static function mm(float $millimeters): float
    {
        return $millimeters * self::MM_TO_PT;
    }
}
