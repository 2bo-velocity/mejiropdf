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
 * The heart of MejiroPDF's stream-first design.
 *
 * Writes bytes straight to an underlying PHP stream while tracking the exact
 * cumulative byte offset with zero double-bookkeeping. No layout logic lives
 * here; it only writes and counts. Any failure is Fail-Fast (MP-SIO-3001).
 */
final class SafeStreamWriter implements StreamWriterInterface
{
    /** @var resource */
    private $stream;

    private int $offset = 0;

    private bool $closed = false;

    /**
     * @param resource $stream A valid, writable PHP stream (e.g. the result of
     *                         fopen('php://temp', 'r+b') or fopen($file, 'wb')).
     *
     * @throws StreamException MP-SIO-3001 when $stream is not a stream resource.
     */
    public function __construct(mixed $stream)
    {
        if (!\is_resource($stream) || \get_resource_type($stream) !== 'stream') {
            throw StreamException::invalidResource();
        }

        $this->stream = $stream;
    }

    /**
     * Convenience factory writing to an in-memory buffer (php://temp).
     */
    public static function toMemory(): self
    {
        $stream = \fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw StreamException::invalidResource();
        }

        return new self($stream);
    }

    /**
     * Convenience factory writing directly to a file (truncating, binary).
     *
     * @throws StreamException
     */
    public static function toFile(string $path): self
    {
        $stream = @\fopen($path, 'wb');
        if ($stream === false) {
            throw StreamException::writeFailed(0);
        }

        return new self($stream);
    }

    public function write(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        if ($this->closed) {
            throw StreamException::writeFailed($this->offset);
        }

        $total = \strlen($bytes);
        $remaining = $total;

        // fwrite may perform a partial write; loop until everything is flushed.
        while ($remaining > 0) {
            $chunk = $remaining === $total ? $bytes : \substr($bytes, $total - $remaining);
            $written = @\fwrite($this->stream, $chunk, $remaining);

            if ($written === false || $written === 0) {
                $error = \error_get_last();
                if ($error !== null && \str_contains(\strtolower($error['message']), 'no space')) {
                    throw StreamException::diskFull($this->offset);
                }
                throw StreamException::writeFailed($this->offset);
            }

            $remaining -= $written;
            $this->offset += $written;
        }

        return $total;
    }

    public function writeLine(string $line = ''): int
    {
        return $this->write($line . "\n");
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Move the write pointer to an absolute offset (used by the 2-pass total
     * page / TOC finalization). The byte counter is realigned to the new head.
     *
     * @throws StreamException
     */
    public function seek(int $offset): void
    {
        if (\fseek($this->stream, $offset, \SEEK_SET) !== 0) {
            throw StreamException::seekFailed($offset);
        }

        $this->offset = $offset;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function close(): void
    {
        if (!$this->closed && \is_resource($this->stream)) {
            \fclose($this->stream);
        }

        $this->closed = true;
    }
}
