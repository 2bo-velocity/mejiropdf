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
 * Compiled template (.mpb) loading / validation failures (MP-VAL-*).
 */
final class TemplateException extends AbstractPdfException
{
    public static function notFound(string $path): self
    {
        return new self(
            ErrorCode::TEMPLATE_BROKEN->message("Template file not found or unreadable: {$path}"),
            ErrorCode::TEMPLATE_BROKEN,
            context: ['path' => $path],
        );
    }

    public static function invalidHeader(string $detail): self
    {
        return new self(
            ErrorCode::TEMPLATE_BROKEN->message($detail),
            ErrorCode::TEMPLATE_BROKEN,
        );
    }

    public static function corrupted(string $detail): self
    {
        return new self(
            ErrorCode::LAYOUT_SYNTAX->message($detail),
            ErrorCode::LAYOUT_SYNTAX,
        );
    }

    /**
     * A value exceeded the `maxLength` its binding declares.
     *
     * Raised before the run is laid out, so the caller learns which field of
     * which record was too long instead of discovering it as a clipped or
     * overflowing box in a finished document.
     */
    public static function maxLengthExceeded(string $key, int $limit, int $actual): self
    {
        return new self(
            ErrorCode::MAX_LENGTH_EXCEEDED->message(\sprintf(
                'Field "%s" accepts at most %d characters but was given %d.',
                $key,
                $limit,
                $actual,
            )),
            ErrorCode::MAX_LENGTH_EXCEEDED,
            context: ['field' => $key, 'limit' => $limit, 'length' => $actual],
        );
    }

    /**
     * A value wrapped past the `maxLines` its binding declares.
     *
     * Shares `MP-VAL-MAX_LENGTH` with {@see maxLengthExceeded()} on purpose:
     * both say "this value is too big for the box it was drawn for", and the
     * caller's remedy is the same either way — shorten the value or widen the
     * field. A separate code would add a row to every error table without
     * changing anyone's response. The context says which limit was hit: a
     * `length` key for characters, a `lines` key for wrapped lines.
     */
    public static function maxLinesExceeded(string $key, int $limit, int $actual): self
    {
        return new self(
            ErrorCode::MAX_LENGTH_EXCEEDED->message(\sprintf(
                'Field "%s" accepts at most %d line(s) in its box but wrapped to %d.',
                $key,
                $limit,
                $actual,
            )),
            ErrorCode::MAX_LENGTH_EXCEEDED,
            context: ['field' => $key, 'limit' => $limit, 'lines' => $actual],
        );
    }
}
