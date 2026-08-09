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

use MejiroPDF\Font\StandardFont;

/**
 * Configuration for the automatic page-number footer.
 *
 * Because the total page count is only known once every page has been produced,
 * the engine renders the footer as a deferred per-page content stream at
 * finalization — so {TOTAL} is always exact (no fixed-width placeholder). All
 * margins are in millimetres; the font size is in points.
 */
final readonly class PageNumberOptions
{
    /**
     * @param string        $format          Template with {PAGE} / {TOTAL} placeholders.
     * @param Alignment      $alignment       Horizontal placement of the number.
     * @param float          $marginBottom    Distance from the page bottom (mm).
     * @param float          $marginSide      Left/right inset for LEFT/RIGHT (mm).
     * @param StandardFont   $font            Font face for the number.
     * @param float          $fontSize        Font size (pt).
     * @param int            $skipPages       Leading pages to leave un-numbered (e.g. a cover).
     * @param int            $startPageNumber Logical number printed on the first numbered page.
     * @param string|null    $color           Hex "#RRGGBB" (default black).
     * @param NumberStyle    $style           How the number is spelled. A document
     *                                        that changes style partway through
     *                                        uses {@see \MejiroPDF\Document::restartPageNumbering()}.
     */
    public function __construct(
        public string $format = '{PAGE} / {TOTAL}',
        public Alignment|BindingEdge $alignment = Alignment::RIGHT,
        public float $marginBottom = 12.0,
        public float $marginSide = 15.0,
        public StandardFont $font = StandardFont::HELVETICA,
        public float $fontSize = 9.0,
        public int $skipPages = 0,
        public int $startPageNumber = 1,
        public ?string $color = null,
        public NumberStyle $style = NumberStyle::ARABIC,
    ) {
    }

    /**
     * Whether the given 1-based physical page should carry a number.
     */
    public function shouldRender(int $physicalPage): bool
    {
        return $physicalPage > $this->skipPages;
    }

    /**
     * The logical (printed) number for a 1-based physical page.
     */
    public function logicalNumber(int $physicalPage): int
    {
        return ($physicalPage - $this->skipPages) + ($this->startPageNumber - 1);
    }

    /**
     * Resolve the format string for a given page/total.
     */
    public function resolve(int $physicalPage, int $total): string
    {
        $logicalTotal = $total - $this->skipPages + ($this->startPageNumber - 1);

        return $this->fill($this->logicalNumber($physicalPage), \max(0, $logicalTotal), $this->style);
    }

    /**
     * The format string with a number and a total written in the given style —
     * shared with {@see \MejiroPDF\Document::restartPageNumbering()}, where the
     * sequence rather than the whole document decides both.
     */
    public function fill(int $number, int $total, NumberStyle $style): string
    {
        return \strtr($this->format, [
            '{PAGE}' => $style->format($number),
            '{TOTAL}' => $style->format($total),
        ]);
    }
}
