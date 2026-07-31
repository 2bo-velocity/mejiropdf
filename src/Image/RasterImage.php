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

namespace MejiroPDF\Image;

use MejiroPDF\Writer\DocumentWriter;

/**
 * A raster image that can embed itself as a PDF image XObject.
 */
interface RasterImage
{
    public function width(): int;

    public function height(): int;

    /**
     * Write the image (and any soft-mask) as indirect objects, returning the
     * object id of the paintable image XObject.
     */
    public function embed(DocumentWriter $writer): int;
}
