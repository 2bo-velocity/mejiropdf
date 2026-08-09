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

use MejiroPDF\Font\FontFace;

/**
 * The 柱 — the chapter's name repeated at the top of each of its pages, so a
 * reader who opens the book in the middle knows where they are.
 *
 * The text is not set here: it comes from whichever {@see \MejiroPDF\Layout\Section}
 * the page belongs to, and changes as the chapters do. This only says how and
 * where to draw it.
 */
final readonly class RunningHeadOptions
{
    /**
     * @param Alignment|BindingEdge $alignment  Where along the head margin it sits.
     *                                          Defaults to the fore-edge, opposite
     *                                          the gutter, as most books set it.
     * @param float                 $marginTop  Distance from the page's top edge (mm).
     * @param float                 $marginSide Distance from the left/right edge (mm).
     * @param FontFace|null         $font       Defaults to the document's own
     *                                          face. A running head carries the
     *                                          author's words, so it has to be
     *                                          set in a face that can spell
     *                                          them — Helvetica would silently
     *                                          turn a Japanese chapter title
     *                                          into rubble.
     * @param bool                  $skipChapterOpenings Leave it off the page a
     *                                          chapter starts on, where the heading
     *                                          already names it — the usual practice.
     * @param RunningHeadLevel      $verso      What the even page shows. Japanese
     *                                          books put the chapter here.
     * @param RunningHeadLevel      $recto      What the odd page shows. Japanese
     *                                          books put the section here; a book
     *                                          with no subheadings falls back to
     *                                          the chapter, so this costs nothing
     *                                          until there is something to say.
     */
    public function __construct(
        public Alignment|BindingEdge $alignment = BindingEdge::OUTER,
        public float $marginTop = 12.0,
        public float $marginSide = 15.0,
        public ?FontFace $font = null,
        public float $fontSize = 8.0,
        public ?string $color = null,
        public bool $skipChapterOpenings = true,
        public RunningHeadLevel $verso = RunningHeadLevel::CHAPTER,
        public RunningHeadLevel $recto = RunningHeadLevel::SECTION,
    ) {
    }
}
