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
 * Marker interface implemented by every exception MejiroPDF throws.
 *
 * Consumers can catch all engine-originated failures with a single
 * `catch (MejiroPDFException $e)`.
 */
interface MejiroPDFException extends \Throwable
{
    /**
     * The language-independent error code (e.g. "MP-SIO-3001").
     */
    public function getErrorCode(): string;

    /**
     * Raw diagnostic context (failing page number, offending string dump, …).
     *
     * @return array<string, mixed>
     */
    public function getContext(): array;
}
