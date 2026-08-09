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
 * Which way the pages run when a reader shows two at a time.
 *
 * This is `/ViewerPreferences << /Direction >>`, and it decides nothing about
 * how the document is drawn — only which side of a spread page one falls on. A
 * vertically-set Japanese book reads right to left, and without saying so its
 * spreads come out reversed: the wrong page turns up under the reader's thumb,
 * and a facing-page design lands inside out.
 *
 * The failure is invisible while proofing single pages, which is why
 * {@see AUTO} is the default. An author who set the text vertically has already
 * said everything needed to know the answer.
 */
enum ReadingDirection
{
    /** Right-to-left once anything has been set vertically; otherwise left-to-right. */
    case AUTO;

    case LEFT_TO_RIGHT;

    case RIGHT_TO_LEFT;

    /**
     * The `/Direction` value to write, or null to leave the entry out — which
     * is what left-to-right means, that being the PDF default.
     */
    public function resolve(bool $documentIsVertical): ?string
    {
        return match ($this) {
            self::LEFT_TO_RIGHT => null,
            self::RIGHT_TO_LEFT => 'R2L',
            self::AUTO => $documentIsVertical ? 'R2L' : null,
        };
    }
}
