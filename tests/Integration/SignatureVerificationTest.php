<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Integration;

use MejiroPDF\Document;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Security\Signature;
use MejiroPDF\Tests\Support\ExternalCommand;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Proves the embedded detached PKCS#7 signature is *cryptographically valid*
 * over the document's /ByteRange — not merely that the structure looks right —
 * by re-verifying it with the `openssl cms` command line, exactly as an
 * independent PDF reader would. Skips when the openssl CLI is unavailable.
 */
#[Group('integration')]
final class SignatureVerificationTest extends TestCase
{
    use ExternalCommand;

    private const string CERT = __DIR__ . '/../fixtures/certs/test.crt';
    private const string KEY = __DIR__ . '/../fixtures/certs/test.key';

    protected function setUp(): void
    {
        $this->requireCommand('openssl');
    }

    protected function tearDown(): void
    {
        $this->cleanupTempFiles();
    }

    #[Test]
    public function the_detached_signature_verifies_against_its_byte_range(): void
    {
        [$content, $der] = $this->signAndSplit();
        $contentFile = $this->tempFile($content);
        $sigFile = $this->tempFile($der);

        // Full chain: trust the (self-signed) signing certificate as the CA.
        [$trusted] = $this->runCommand(\sprintf(
            'openssl cms -verify -inform DER -in %s -content %s -binary -CAfile %s -out /dev/null',
            \escapeshellarg($sigFile),
            \escapeshellarg($contentFile),
            \escapeshellarg(self::CERT),
        ));
        self::assertSame(0, $trusted, 'CMS signature did not verify against the signing certificate.');

        // Cryptographic check alone, independent of any trust store.
        [$crypto] = $this->runCommand(\sprintf(
            'openssl cms -verify -inform DER -in %s -content %s -binary -noverify -out /dev/null',
            \escapeshellarg($sigFile),
            \escapeshellarg($contentFile),
        ));
        self::assertSame(0, $crypto, 'CMS signature is not cryptographically valid over the byte range.');
    }

    #[Test]
    public function page_text_that_mimics_the_placeholders_does_not_capture_the_signature(): void
    {
        // Content streams are uncompressed, and the literal-string escaper
        // leaves "/" and "<" alone, so this reaches the file verbatim — ahead
        // of the /Sig dictionary. The signature used to be spliced in at the
        // first "/Contents <", overwriting the page that followed it.
        [$content, $der] = $this->signAndSplit(
            'Customer: /Contents <0000> [0 0000000000 0000000000 0000000000]',
        );

        self::assertStringContainsString('Customer: /Contents <0000> [0 0000000000 0000000000 0000000000]', $content);

        [$code] = $this->runCommand(\sprintf(
            'openssl cms -verify -inform DER -in %s -content %s -binary -noverify -out /dev/null',
            \escapeshellarg($this->tempFile($der)),
            \escapeshellarg($this->tempFile($content)),
        ));
        self::assertSame(0, $code, 'The signature must still cover the document when page text mimics its placeholders.');
    }

    #[Test]
    public function tampering_with_the_signed_bytes_breaks_verification(): void
    {
        [$content, $der] = $this->signAndSplit();
        // Flip one byte inside the signed range.
        $content[100] = $content[100] === 'A' ? 'B' : 'A';

        $contentFile = $this->tempFile($content);
        $sigFile = $this->tempFile($der);

        [$code] = $this->runCommand(\sprintf(
            'openssl cms -verify -inform DER -in %s -content %s -binary -noverify -out /dev/null',
            \escapeshellarg($sigFile),
            \escapeshellarg($contentFile),
        ));
        self::assertNotSame(0, $code, 'A tampered document must fail signature verification.');
    }

    /**
     * Sign a document and return the bytes the /ByteRange covers together with
     * the detached DER blob taken from /Contents — the two inputs a verifier
     * feeds to CMS verification.
     *
     * @return array{string, string} [signed content, DER signature]
     */
    private function signAndSplit(string $text = 'Integration-signed document'): array
    {
        $signature = Signature::fromFiles(self::CERT, self::KEY, name: 'MejiroPDF Test Signer', reason: 'Approval');
        $pdf = new Document(credit: CreditOptions::disabled(), signature: $signature);
        $pdf->addPage()->text(20, 30, $text);
        $out = $pdf->output();

        if (\preg_match('~/ByteRange \[(\d+) (\d+) (\d+) (\d+)\]~', $out, $m) !== 1) {
            self::fail('No computed /ByteRange in the signed output.');
        }
        [$a0, $l0, $a1, $l1] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];

        $content = \substr($out, $a0, $l0) . \substr($out, $a1, $l1);
        $hex = \substr($out, $a0 + $l0, $a1 - ($a0 + $l0)); // between '<' and '>'
        $der = (string) \hex2bin(\rtrim($hex));

        return [$content, $this->trimToDerLength($der)];
    }

    /**
     * The /Contents blob is right-padded with zero bytes; trim it to the true
     * DER length declared in the outer ASN.1 SEQUENCE header.
     */
    private function trimToDerLength(string $der): string
    {
        $lengthByte = \ord($der[1]);
        if ($lengthByte < 0x80) {
            return \substr($der, 0, 2 + $lengthByte);
        }

        $count = $lengthByte - 0x80;
        $length = 0;
        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | \ord($der[2 + $i]);
        }

        return \substr($der, 0, 2 + $count + $length);
    }
}
