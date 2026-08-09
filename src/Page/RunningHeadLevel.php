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
 * Which of a book's two names a running head shows.
 *
 * An opened book presents two pages at once, and printing the same words on
 * both wastes one of them. Japanese books set the chapter on the verso and the
 * section on the recto, so that a reader who opens the book anywhere can see
 * both where they are and how they got there. Western books spend the verso on
 * the book's own title instead — the same mechanism, a different choice of what
 * to put where, which is why the two sides are settings rather than a rule.
 */
enum RunningHeadLevel
{
    /** The chapter, registered by {@see \MejiroPDF\Layout\Section}. */
    case CHAPTER;

    /**
     * The subheading, registered by a `.subhead` paragraph in a flow or by
     * {@see \MejiroPDF\Document::runningSection()}.
     */
    case SECTION;

    /** Nothing at all: one side of the spread carries no head. */
    case NONE;
}
