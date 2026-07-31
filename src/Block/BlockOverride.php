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
 * What the calling code decided about one block, overriding what the template
 * says.
 *
 * A template's `condition` is the designer's rule, written once and evaluated
 * per record. This is the other direction: the program has looked at the record
 * and knows something the expression cannot express — a customer flagged in
 * another system, a batch run in draft mode. Both are legitimate, so neither
 * replaces the other; an override simply wins where it is set.
 *
 * Null means "not overridden", which is why this is a nullable-field object
 * rather than a set of booleans: absent and false are different answers.
 */
final class BlockOverride
{
    /** True forces the block on, false forces it off, null defers to the template. */
    public ?bool $visible = null;

    /**
     * Rows for a repeater, replacing its `dataSource` lookup.
     *
     * @var list<mixed>|null
     */
    public ?array $rows = null;

    public function isEmpty(): bool
    {
        return $this->visible === null && $this->rows === null;
    }
}
