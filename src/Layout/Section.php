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

namespace MejiroPDF\Layout;

use MejiroPDF\Font\FontFace;

/**
 * A chapter: a heading, the prose under it, and the several small obligations
 * that come with both.
 *
 * Every generator measured for this work treats the same five things as one
 * act — open a page, set the heading at the start of it, leave a line clear,
 * flow the body, and register a bookmark — and an engine with `bookmark()`,
 * `tableOfContents()` and `flowInto()` still made the author perform all five
 * and keep them in step. This is the missing noun.
 *
 * One part genuinely needs the engine rather than sugar: **the body begins
 * below the heading on the first page and at the top of every page after it**.
 * Flowing into a single region cannot express that, and a region shortened to
 * fit the heading would leave the gap on every page.
 */
final readonly class Section
{
    /**
     * @param string        $title           The heading, and the bookmark's text.
     * @param string        $body            Prose, paragraphs separated by blank lines.
     * @param FontFace|null $titleFont       Defaults to the body's face.
     * @param float|null    $titleSize       Points; defaults to the body size.
     * @param float         $gap             Clear space between heading and body,
     *                                       in multiples of the body's line advance.
     * @param bool          $startsOnNewPage Whether to break before the heading.
     * @param int|null      $bookmarkLevel   Outline depth, or null for no bookmark.
     * @param string|null   $runningHead     Overrides {@see $title} in the running
     *                                       head; an empty string suppresses it.
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?FontFace $titleFont = null,
        public ?float $titleSize = null,
        public float $gap = 1.0,
        public bool $startsOnNewPage = true,
        public ?int $bookmarkLevel = 0,
        public ?string $runningHead = null,
    ) {
        if ($gap < 0.0) {
            throw new \InvalidArgumentException('A section gap cannot be negative.');
        }
    }

    /** The text a running head should show, or null when it should show none. */
    public function runningHeadText(): ?string
    {
        $text = $this->runningHead ?? $this->title;

        return $text === '' ? null : $text;
    }
}
