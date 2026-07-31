<?php

declare(strict_types=1);

/**
 * MejiroPDF — encrypted PDF example (AES-128).
 *
 * Open with the user password "secret"; full permissions require the owner
 * password "owner".
 *
 * Run on PHP 8.3+ (ext-openssl):  php examples/encrypted.php
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Security\Encryption;

$pdf = new Document(
    encryption: new Encryption(
        userPassword: 'secret',
        ownerPassword: 'owner',
        permissions: Encryption::NO_COPY, // openable, but text can't be copied
    ),
);

$pdf->addPage()
    ->text(20, 30, 'Confidential', size: 24.0, color: '#B00020')
    ->text(20, 42, 'This document is encrypted with AES-128.', size: 12.0)
    ->text(20, 50, 'User password: secret', size: 11.0);

$pdf->save(__DIR__ . '/encrypted.pdf');

echo "Wrote encrypted.pdf — open with password 'secret'.\n";
