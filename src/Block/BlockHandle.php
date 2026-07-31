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

namespace MejiroPDF\Block;

/**
 * A named block, addressed from the calling code.
 *
 * ```php
 * $pdf->block('bank_details')->hide();
 * $pdf->block('items')->bindData($invoice->lines);
 * $pdf->renderTemplate($template, $data);
 * ```
 *
 * The handle is a reference, not a copy: it names a block by id and records
 * what the caller decided about it. Nothing is looked up when the handle is
 * made, so naming a block that does not exist is silent — the template is the
 * designer's file, and code that guards a panel which was later renamed should
 * not take the whole batch down with it.
 *
 * Every method returns the handle, so a block can be configured in one line.
 */
final readonly class BlockHandle
{
    public function __construct(private BlockOverride $override)
    {
    }

    /** Keep this block out of the document, whatever its condition says. */
    public function hide(): self
    {
        $this->override->visible = false;

        return $this;
    }

    /** Draw this block, whatever its condition says. */
    public function show(): self
    {
        $this->override->visible = true;

        return $this;
    }

    /**
     * Set a block's visibility from a value the program already has.
     *
     * Saves the `if` around {@see hide()} when the answer is a variable.
     */
    public function visible(bool $visible): self
    {
        $this->override->visible = $visible;

        return $this;
    }

    /**
     * Feed a repeater its rows directly, instead of having it look them up.
     *
     * The template names a path into the render data; this hands over the rows
     * as they already are. Useful when the rows come from somewhere the data
     * array does not model — a query result, a generator drained into a list —
     * and inventing a key just to point at them would be ceremony.
     *
     * @param iterable<mixed> $rows
     */
    public function bindData(iterable $rows): self
    {
        $this->override->rows = \is_array($rows) ? \array_values($rows) : \iterator_to_array($rows, false);

        return $this;
    }

    /** Drop every override on this block, returning it to what the template says. */
    public function reset(): self
    {
        $this->override->visible = null;
        $this->override->rows = null;

        return $this;
    }
}
