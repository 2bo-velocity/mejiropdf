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

namespace MejiroPDF\Security;

/**
 * Encryption settings for a document (AES-128, PDF standard security handler).
 *
 * The **user password** is required to open the document; the **owner
 * password** unlocks full permissions. Leave the user password empty to allow
 * anyone to open the file while still restricting permissions with the owner
 * password.
 */
final readonly class Encryption
{
    /** All permission bits granted. */
    public const int ALLOW_ALL = -4;

    /** Deny printing (clears the print bits). */
    public const int NO_PRINT = -2620;

    /** Deny copy/extract of text and graphics. */
    public const int NO_COPY = -20;

    public function __construct(
        public string $userPassword = '',
        public string $ownerPassword = '',
        public int $permissions = self::ALLOW_ALL,
    ) {
    }

    /**
     * Encrypt with a user password (required to open) and an optional owner
     * password (defaults to the user password).
     */
    public static function withUserPassword(
        string $userPassword,
        string $ownerPassword = '',
        int $permissions = self::ALLOW_ALL,
    ): self {
        return new self($userPassword, $ownerPassword, $permissions);
    }

    /**
     * Openable by anyone, but permissions locked behind the owner password.
     */
    public static function ownerOnly(string $ownerPassword, int $permissions = self::ALLOW_ALL): self
    {
        return new self('', $ownerPassword, $permissions);
    }
}
