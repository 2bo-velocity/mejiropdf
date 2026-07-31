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

namespace MejiroPDF\Import;

/**
 * A page extracted from an existing PDF, ready to embed as a Form XObject.
 *
 * Indirect references inside {@see $resources} and {@see $objects} still use the
 * *source* PDF's object numbers; the importer remaps them when copying into the
 * destination document.
 */
final readonly class ImportedPage
{
    /**
     * @param string                                              $bbox      MediaBox as "x0 y0 x1 y1" (points).
     * @param string                                              $content   Decompressed page content operators.
     * @param string                                              $resources The page /Resources dictionary.
     * @param array<int, array{dict: string, stream: string|null}> $objects  Reachable objects, by source object number.
     */
    public function __construct(
        public string $bbox,
        public string $content,
        public string $resources,
        public array $objects,
    ) {
    }
}
