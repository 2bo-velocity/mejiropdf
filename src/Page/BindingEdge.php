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
 * A side of the page named by where the binding is rather than by left and
 * right — which side that turns out to be depends on the page.
 *
 * A page number belongs on the fore-edge, where a thumb finds it. On a recto
 * that is one side and on a verso the other, so a book asked for `RIGHT`
 * throughout gets it right half the time and buried in the gutter for the rest.
 * Saying {@see OUTER} once says it for every page.
 *
 * Which physical side each edge lands on also depends on how the book is bound,
 * and that follows the reading direction: a left-bound (Western) book has its
 * gutter on the left of a recto, a right-bound one — the Japanese vertical
 * convention — on the right.
 *
 * Kept apart from {@see Alignment} on purpose. That enum places table cells and
 * template text too, where a binding has no meaning.
 */
enum BindingEdge
{
    /** The fore-edge — 小口. Where page numbers and running heads belong. */
    case OUTER;

    /** The gutter — ノド. Where the pages are held together. */
    case INNER;

    /**
     * The physical side for a given page.
     *
     * @param int  $physicalPage 1-based; odd pages are rectos.
     * @param bool $rightBound   True for a right-bound book (vertical Japanese).
     */
    public function resolve(int $physicalPage, bool $rightBound): Alignment
    {
        $recto = $physicalPage % 2 === 1;
        $gutterOnLeft = $rightBound ? !$recto : $recto;
        $gutter = $gutterOnLeft ? Alignment::LEFT : Alignment::RIGHT;

        return $this === self::INNER ? $gutter : $gutter->opposite();
    }
}
