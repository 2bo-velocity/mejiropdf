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
    public function placeholder_look_alikes_in_signed_fields_leave_the_signature_where_it_belongs(): void
    {
        $decoy = '/Contents < [0 0000000000 0000000000 0000000000]';
        $signature = Signature::fromFiles(
            __DIR__ . '/../fixtures/certs/test.crt',
            __DIR__ . '/../fixtures/certs/test.key',
            name: $decoy,      // inside the /Sig dictionary itself, ahead of the placeholders
            reason: $decoy,
        );
        $pdf = new Document(credit: CreditOptions::disabled(), signature: $signature);
        $pdf->addPage()->text(20, 30, $decoy); // in the page content, ahead of the /Sig dictionary

        $out = $pdf->output();

        // Every decoy survives byte for byte: nothing was written over them.
        self::assertSame(3, \substr_count($out, $decoy));

        if (\preg_match('~/ByteRange \[0 (\d{10}) (\d{10}) (\d{10})\]~', $out, $m) !== 1) {
            self::fail('No computed /ByteRange in the signed output.');
        }
        [$gapStart, $gapEnd] = [(int) $m[1], (int) $m[2]];
        // The excluded gap is exactly the /Contents hex string of the /Sig
        // dictionary: opened by its '<', closed by its '>', holding a DER blob.
        self::assertSame('/Contents <', \substr($out, $gapStart - 11, 11));
        self::assertSame('>', $out[$gapEnd]);
        self::assertStringStartsWith('30', \substr($out, $gapStart, 2));
        self::assertGreaterThan(\strrpos($out, '/Type /Sig'), $gapStart);
        // The ByteRange covers through to the end of the file.
        self::assertSame(\strlen($out), $gapEnd + (int) $m[3]);
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
