<?php

declare(strict_types=1);

/**
 * MejiroPDF — digitally signed PDF (detached PKCS#7).
 *
 * Signs with a certificate + private key (PEM). A throwaway self-signed pair is
 * used by default; pass your own as arguments.
 *
 *   php examples/signed.php [cert.pem] [key.pem] [keyPassword]
 *
 * Verify the result with:  pdfsig signed.pdf
 */

require __DIR__ . '/../vendor/autoload.php';

use MejiroPDF\Document;
use MejiroPDF\Security\Signature;

$cert = $argv[1] ?? __DIR__ . '/../tests/fixtures/certs/test.crt';
$key = $argv[2] ?? __DIR__ . '/../tests/fixtures/certs/test.key';
$password = $argv[3] ?? '';

$signature = Signature::fromFiles(
    $cert,
    $key,
    keyPassword: $password,
    name: 'MejiroPDF Test Signer',
    reason: 'Document approval',
    location: 'Miyazaki, JP',
);

$pdf = new Document(signature: $signature);
$pdf->addPage()
    ->text(20, 30, 'Signed document', size: 22.0, color: '#1a3a7a')
    ->text(20, 42, 'This file carries a detached PKCS#7 digital signature.', size: 12.0);

$pdf->save(__DIR__ . '/signed.pdf');

echo "Wrote signed.pdf — verify with: pdfsig examples/signed.pdf\n";
