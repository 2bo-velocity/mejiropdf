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
 * Produces a detached CMS/PKCS#7 signature (DER) over arbitrary bytes, using
 * ext-openssl. This is the blob embedded in a PDF /Sig dictionary's /Contents.
 */
final class Signer
{
    public static function sign(string $data, Signature $signature): string
    {
        $input = \tempnam(\sys_get_temp_dir(), 'mp_sig_in_');
        $output = \tempnam(\sys_get_temp_dir(), 'mp_sig_out_');
        if ($input === false || $output === false) {
            throw SecurityException::encryptionFailed('Could not create temporary files for signing.');
        }

        try {
            \file_put_contents($input, $data);

            $privateKey = $signature->keyPassword === ''
                ? $signature->privateKeyPem
                : [$signature->privateKeyPem, $signature->keyPassword];

            $ok = \openssl_cms_sign(
                $input,
                $output,
                $signature->certificatePem,
                $privateKey,
                [],
                \OPENSSL_CMS_DETACHED | \OPENSSL_CMS_BINARY,
                \OPENSSL_ENCODING_DER,
            );
            if (!$ok) {
                throw SecurityException::encryptionFailed('CMS signing failed: ' . (\openssl_error_string() ?: 'unknown error'));
            }

            $der = \file_get_contents($output);
            if ($der === false) {
                throw SecurityException::encryptionFailed('Could not read the produced signature.');
            }

            return $der;
        } finally {
            @\unlink($input);
            @\unlink($output);
        }
    }
}
