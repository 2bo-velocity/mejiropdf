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

use MejiroPDF\Exception\SecurityException;

/**
 * A digital-signature request: the signing certificate and private key (PEM),
 * plus optional visible metadata. The signature is a detached PKCS#7/CMS blob
 * (SubFilter adbe.pkcs7.detached) over the document's byte range.
 */
final readonly class Signature
{
    /**
     * @param string      $certificatePem Signing certificate in PEM.
     * @param string      $privateKeyPem  Private key in PEM.
     * @param string      $keyPassword    Password for an encrypted key (or '').
     * @param string      $name           Signer name (/Name).
     * @param string|null $reason         Reason for signing (/Reason).
     * @param string|null $location       Signing location (/Location).
     * @param string|null $contactInfo    Signer contact (/ContactInfo).
     * @param int         $reservedBytes  Bytes reserved for the signature blob.
     */
    public function __construct(
        public string $certificatePem,
        public string $privateKeyPem,
        public string $keyPassword = '',
        public string $name = '',
        public ?string $reason = null,
        public ?string $location = null,
        public ?string $contactInfo = null,
        public int $reservedBytes = 8192,
    ) {
    }

    /**
     * Build from PEM files on disk.
     */
    public static function fromFiles(
        string $certificatePath,
        string $privateKeyPath,
        string $keyPassword = '',
        string $name = '',
        ?string $reason = null,
        ?string $location = null,
        ?string $contactInfo = null,
    ): self {
        $cert = @\file_get_contents($certificatePath);
        $key = @\file_get_contents($privateKeyPath);
        if ($cert === false) {
            throw SecurityException::encryptionFailed("Certificate not readable: {$certificatePath}");
        }
        if ($key === false) {
            throw SecurityException::encryptionFailed("Private key not readable: {$privateKeyPath}");
        }

        return new self($cert, $key, $keyPassword, $name, $reason, $location, $contactInfo);
    }
}
