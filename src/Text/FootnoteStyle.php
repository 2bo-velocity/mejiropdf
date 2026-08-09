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

namespace MejiroPDF\Text;

/**
 * Footnotes: how the marks look, how the notes are set at the foot of the
 * page, and how much room is kept for them.
 *
 * A note is written into the body as `((…))`, and lands on whichever page its
 * mark did — the reference and the note are never parted. {@see $placement}
 * decides whether that is the foot of the page or the fore-edge margin beside
 * the line itself.
 *
 * **{@see $reserve} is stated rather than worked out.** Deciding it
 * automatically means filling the page, discovering what notes accrued, and
 * laying the page out again against a shorter foot — with a paragraph that
 * straddles the break re-splitting each time. That is worth doing and is the
 * next step; until then the caller says how deep the band is, and the engine
 * keeps exactly that much and reports when the notes do not fit.
 */
final readonly class FootnoteStyle
{
    /**
     * @param float|null $reserve Depth of the band at the foot of the page, in
     *                            millimetres. `null` works it out per page;
     *                            a number keeps exactly that much on every
     *                            page and reports notes that outgrow it; zero
     *                            draws no notes at all.
     * @param float  $fontSize    Points; notes are set smaller than the body.
     * @param float  $gapAbove    Space between the body and the rule (mm).
     * @param float  $gapBelow    Space between the rule and the first note (mm).
     * @param float  $ruleLength  Length of the rule (mm); zero draws none.
     * @param float  $ruleWeight  Thickness of the rule (pt).
     * @param float  $noteGap     Space between one note and the next (mm).
     * @param float  $markerScale The mark's size as a fraction of the body's.
     * @param float  $markerRaise How far the mark rides above the baseline, in
     *                            ems of its own size.
     * @param NotePlacement $placement Foot of the page, or the fore-edge margin.
     * @param float  $marginWidth How wide a margin note may be (mm). Only read
     *                            when {@see $placement} is the margin.
     * @param float  $marginGap   Space between the body and the margin note (mm).
     */
    public function __construct(
        public ?float $reserve = null,
        public float $fontSize = 8.0,
        public float $gapAbove = 3.0,
        public float $gapBelow = 1.5,
        public float $ruleLength = 30.0,
        public float $ruleWeight = 0.3,
        public float $noteGap = 0.8,
        public float $markerScale = 0.7,
        public float $markerRaise = 0.4,
        public NotePlacement $placement = NotePlacement::PAGE_FOOT,
        public float $marginWidth = 25.0,
        public float $marginGap = 5.0,
    ) {
        if ($reserve !== null && $reserve < 0.0) {
            throw new \InvalidArgumentException('A footnote reserve cannot be negative.');
        }
        if ($marginWidth <= 0.0) {
            throw new \InvalidArgumentException('A margin note needs a positive width.');
        }
    }

    /** Whether notes are set beside the body rather than under it. */
    public function inMargin(): bool
    {
        return $this->placement === NotePlacement::SIDE_MARGIN;
    }

    /** How a note is labelled, both at the mark and at the foot. */
    public function label(int $number): string
    {
        return (string) $number;
    }
}
