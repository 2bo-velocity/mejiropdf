<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Security\Encryption;
use MejiroPDF\Security\Signature;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(Signature::class)]
final class DocumentSignatureTest extends TestCase
{
    private function signature(): Signature
    {
        return Signature::fromFiles(
            __DIR__ . '/../fixtures/certs/test.crt',
            __DIR__ . '/../fixtures/certs/test.key',
            name: 'MejiroPDF Test Signer',
            reason: 'Approval',
        );
    }

    #[Test]
    public function it_embeds_a_signature_field_and_acroform(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), signature: $this->signature());
        $pdf->addPage()->text(20, 30, 'signed document');

        $out = $pdf->output();

        self::assertStringContainsString('/Type /Sig', $out);
        self::assertStringContainsString('/SubFilter /adbe.pkcs7.detached', $out);
        self::assertStringContainsString('/FT /Sig', $out);
        self::assertStringContainsString('/SigFlags 3', $out);
        self::assertStringContainsString('/AcroForm', $out);
        self::assertStringContainsString('(Approval)', $out); // the reason
    }

    #[Test]
    public function it_fills_the_byte_range_and_signature(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), signature: $this->signature());
        $pdf->addPage()->text(20, 30, 'x');

        $out = $pdf->output();

        // ByteRange is computed (not the zero placeholder).
        self::assertMatchesRegularExpression('~/ByteRange \[0 \d{10} \d{10} \d{10}\]~', $out);
        self::assertStringNotContainsString('/ByteRange [0 0000000000', $out);
        // A real DER PKCS#7 begins with an ASN.1 SEQUENCE (0x30).
        self::assertMatchesRegularExpression('~/Contents <30[0-9a-f]{20}~', $out);
    }

    #[Test]
    public function signing_and_encryption_are_mutually_exclusive(): void
    {
        $this->expectException(\LogicException::class);

        new Document(
            encryption: new Encryption('secret'),
            signature: $this->signature(),
        );
    }
}
