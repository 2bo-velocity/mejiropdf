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
 * How the first page should be framed when the document is opened
 * (`/OpenAction`), or {@see VIEWER_DEFAULT} to say nothing and leave the choice
 * to the reader's own preference.
 */
enum OpenFit
{
    case VIEWER_DEFAULT;

    /** The whole page, both dimensions. */
    case PAGE;

    /** Full width, top of the page — what a book of running text usually wants. */
    case WIDTH;

    /** Full height. */
    case HEIGHT;

    /**
     * The destination array's tail, after the page reference, or null when no
     * `/OpenAction` should be written.
     */
    public function destination(): ?string
    {
        return match ($this) {
            self::VIEWER_DEFAULT => null,
            self::PAGE => '/Fit',
            self::WIDTH => '/FitH null',
            self::HEIGHT => '/FitV null',
        };
    }
}
