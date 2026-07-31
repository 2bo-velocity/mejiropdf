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

namespace MejiroPDF\Stream;

use MejiroPDF\Exception\StreamException;

/**
 * Byte-counting stream writer abstraction for zero-DOM, O(1)-memory PDF output.
 *
 * The writer is the single source of truth for "how many bytes have been
 * written so far" — this offset is what the PDF cross-reference (xref) table
 * requires for every object.
 */
interface StreamWriterInterface
{
    /**
     * Write raw bytes to the underlying stream and advance the byte offset.
     *
     * @return int Number of bytes written.
     *
     * @throws StreamException MP-SIO-3001 when the underlying write fails.
     */
    public function write(string $bytes): int;

    /**
     * Write a chunk followed by a single PDF newline (\n).
     *
     * @return int Number of bytes written, including the newline.
     *
     * @throws StreamException
     */
    public function writeLine(string $line = ''): int;

    /**
     * Current byte offset from the start of the stream.
     */
    public function getOffset(): int;

    /**
     * Close the underlying stream resource (idempotent).
     */
    public function close(): void;
}
