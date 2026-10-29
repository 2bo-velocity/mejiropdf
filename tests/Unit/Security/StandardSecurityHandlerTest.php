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

    /** The /O entry, which depends only on the two passwords (Algorithm 3). */
    private static function ownerEntry(Encryption $encryption): string
    {
        $dictionary = StandardSecurityHandler::fromEncryption($encryption)->encryptionDictionary();
        if (\preg_match('~/O <([0-9a-f]{64})>~', $dictionary, $m) !== 1) {
            self::fail('No /O entry in the encryption dictionary.');
        }

        return $m[1];
    }

    #[Test]
    public function an_empty_owner_password_is_not_the_user_password(): void
    {
        // A viewer grants every permission to whoever supplies the owner
        // password. Falling back to the user password made a "no printing"
        // restriction void for everyone able to open the file.
        self::assertNotSame(
            self::ownerEntry(new Encryption('secret', 'secret', Encryption::NO_PRINT)),
            self::ownerEntry(new Encryption('secret', '', Encryption::NO_PRINT)),
        );
    }

    #[Test]
    public function an_empty_owner_password_is_a_fresh_random_one(): void
    {
        // Both empty used to make the owner password the empty string, which
        // every viewer tries first: the permissions bound nobody.
        $first = self::ownerEntry(new Encryption('', '', Encryption::NO_COPY));

        self::assertNotSame($first, self::ownerEntry(new Encryption('', '', Encryption::NO_COPY)));
    }

    #[Test]
    public function an_explicit_owner_password_stays_deterministic(): void
    {
        self::assertSame(
            self::ownerEntry(new Encryption('secret', 'owner')),
            self::ownerEntry(new Encryption('secret', 'owner')),
        );
    }
}
