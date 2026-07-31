<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Security;

use MejiroPDF\Security\Encryption;
use MejiroPDF\Security\StandardSecurityHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardSecurityHandler::class)]
#[CoversClass(Encryption::class)]
final class StandardSecurityHandlerTest extends TestCase
{
    private function handler(): StandardSecurityHandler
    {
        return StandardSecurityHandler::fromEncryption(new Encryption('secret', 'owner'));
    }

    #[Test]
    public function it_encrypts_data_to_ciphertext(): void
    {
        $handler = $this->handler();
        $cipher = $handler->encrypt(4, 'plaintext');

        self::assertNotSame('plaintext', $cipher);
        self::assertStringNotContainsString('plaintext', $cipher);
        // A 16-byte IV plus at least one 16-byte AES block.
        self::assertGreaterThanOrEqual(32, \strlen($cipher));
    }

    #[Test]
    public function encryption_is_non_deterministic(): void
    {
        $handler = $this->handler();

        // A fresh random IV each time.
        self::assertNotSame($handler->encrypt(4, 'x'), $handler->encrypt(4, 'x'));
    }

    #[Test]
    public function it_advertises_aes_128_in_its_dictionary(): void
    {
        $dict = $this->handler()->encryptionDictionary();

        self::assertStringContainsString('/Filter /Standard', $dict);
        self::assertStringContainsString('/V 4', $dict);
        self::assertStringContainsString('/CFM /AESV2', $dict);
        self::assertMatchesRegularExpression('~/O <[0-9a-f]{64}>~', $dict);
        self::assertMatchesRegularExpression('~/U <[0-9a-f]{64}>~', $dict);
    }

    #[Test]
    public function it_exposes_a_16_byte_file_id(): void
    {
        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $this->handler()->idHex());
    }

    #[Test]
    public function permission_presets_differ(): void
    {
        self::assertNotSame(Encryption::ALLOW_ALL, Encryption::NO_PRINT);
        self::assertNotSame(Encryption::ALLOW_ALL, Encryption::NO_COPY);
    }
}
