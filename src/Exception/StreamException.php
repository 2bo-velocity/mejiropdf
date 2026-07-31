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

namespace MejiroPDF\Exception;

/**
 * Stream / IO failures (MP-SIO-*): invalid resource, failed write, disk full.
 */
final class StreamException extends AbstractPdfException
{
    public static function invalidResource(): self
    {
        return new self(
            ErrorCode::STREAM_WRITE_FAILED->message('Invalid stream resource provided.'),
            ErrorCode::STREAM_WRITE_FAILED,
        );
    }

    public static function writeFailed(int $offset): self
    {
        return new self(
            ErrorCode::STREAM_WRITE_FAILED->message('Failed to write bytes to the output stream.'),
            ErrorCode::STREAM_WRITE_FAILED,
            context: ['offset' => $offset],
        );
    }

    public static function seekFailed(int $offset): self
    {
        return new self(
            ErrorCode::STREAM_WRITE_FAILED->message("Failed to seek stream to offset {$offset}."),
            ErrorCode::STREAM_WRITE_FAILED,
            context: ['offset' => $offset],
        );
    }

    public static function diskFull(int $offset): self
    {
        return new self(
            ErrorCode::DISK_FULL->message('No space left on device while writing PDF output.'),
            ErrorCode::DISK_FULL,
            context: ['offset' => $offset],
        );
    }
}
