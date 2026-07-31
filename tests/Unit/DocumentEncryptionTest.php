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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentEncryptionTest extends TestCase
{
    #[Test]
    public function it_writes_an_encryption_dictionary_and_id(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), encryption: new Encryption('secret'));
        $pdf->addPage()->text(20, 30, 'hello');

        $out = $pdf->output();

        self::assertStringContainsString('/Filter /Standard', $out);
        self::assertStringContainsString('/CFM /AESV2', $out);
        self::assertStringContainsString('/Encrypt ', $out); // trailer reference
        self::assertMatchesRegularExpression('~/ID \[ <[0-9a-f]+> <[0-9a-f]+> \]~', $out);
    }

    #[Test]
    public function content_streams_are_not_stored_in_clear_text(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), encryption: new Encryption('secret'));
        $pdf->addPage()->text(20, 30, 'TopSecretPayload');

        $out = $pdf->output();

        // The page content stream is encrypted, so the drawn text never appears.
        self::assertStringNotContainsString('TopSecretPayload', $out);
        self::assertStringNotContainsString('(TopSecretPayload) Tj', $out);
    }

    #[Test]
    public function outline_titles_are_encrypted_too(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), encryption: new Encryption('secret'));
        $pdf->addPage()->bookmark('ConfidentialChapter');

        $out = $pdf->output();

        self::assertStringContainsString('/Type /Outlines', $out);
        self::assertStringNotContainsString('ConfidentialChapter', $out); // title is a hex ciphertext
    }

    #[Test]
    public function an_unencrypted_document_has_no_encrypt_entry(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 30, 'plain');

        $out = $pdf->output();

        self::assertStringNotContainsString('/Encrypt', $out);
        self::assertStringNotContainsString('/Filter /Standard', $out);
    }
}
