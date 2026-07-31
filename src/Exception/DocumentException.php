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
 * Core document-level failures (MP-CORE-*) that no more specific component
 * owns.
 */
final class DocumentException extends AbstractPdfException
{
    /**
     * The page limit set with {@see \MejiroPDF\Document::setMaxPageLimit()} was
     * reached.
     *
     * The limit is a safety valve, not a licence check: a report driven by a
     * runaway query would otherwise stream pages until the request times out,
     * and the failure would surface far from its cause. Stopping at a declared
     * ceiling turns that into one exception naming the page that crossed it.
     */
    public static function pageLimitExceeded(int $limit): self
    {
        return new self(
            ErrorCode::PAGE_OVERFLOW->message(\sprintf(
                'Document exceeded its %d-page limit; raise or clear it with setMaxPageLimit().',
                $limit,
            )),
            ErrorCode::PAGE_OVERFLOW,
            context: ['limit' => $limit, 'page' => $limit + 1],
        );
    }
}
