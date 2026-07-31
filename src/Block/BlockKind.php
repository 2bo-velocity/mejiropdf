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
 * What a {@see Block} is: the three primitives a report is built from.
 *
 * Deliberately three and not seven. The report tools this engine competes with
 * grew a concept per situation — page header, group header, detail, group
 * footer, page footer, trailer — until nobody could hold the model in their
 * head. "Draws once" and "draws per row", plus a container for putting two of
 * them side by side, expresses all of it: a heading that only appears from page
 * two is a `SINGLE` with a condition, a subtotal is a `SINGLE` after a
 * `REPEATER`, a category break is a `SINGLE` between two of them.
 */
enum BlockKind: string
{
    /** Drawn once, or not at all when its condition is false. */
    case SINGLE = 'single';

    /** Drawn once per row of its data source, growing downward. */
    case REPEATER = 'repeater';

    /** Places its columns side by side; the container itself rides the flow. */
    case COLUMNS = 'columns';
}
