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
 * The PDF standard security handler using AES-128 (encryption /V 4, /R 4,
 * crypt filter method AESV2).
 *
 * Implements the classic RC4/MD5 key schedule (Algorithms 2, 3, 5) to derive
 * the /O, /U and encryption-key values, then encrypts each string and stream
 * with a per-object AES-128-CBC key (Algorithm 1). Strings and streams are the
 * only things encrypted; the /ID, the /Encrypt dictionary and /O /U are not.
 */
final class StandardSecurityHandler
{
    /** The 32-byte password padding string from the PDF specification. */
    private const string PAD =
        "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08" .
        "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function __construct(
        private readonly string $encryptionKey, // 16 bytes
        private readonly string $ownerEntry,    // 32 bytes (/O)
        private readonly string $userEntry,     // 32 bytes (/U)
        private readonly int $permissions,
        private readonly string $documentId,    // 16 bytes
    ) {
    }

    public static function fromEncryption(Encryption $encryption): self
    {
        $documentId = \random_bytes(16);

        $userPadded = \substr($encryption->userPassword . self::PAD, 0, 32);
        // No owner password means "nobody may lift the restrictions", not
        // "the user password lifts them": a viewer given a password that
        // matches the owner entry grants every permission, so reusing the user
        // password would hand that to everyone who can open the file, and
        // leaving it empty would hand it to everyone at all. A random one that
        // is never recorded makes the permissions binding.
        $ownerSource = $encryption->ownerPassword !== ''
            ? $encryption->ownerPassword
            : \bin2hex(\random_bytes(16));
        $ownerPadded = \substr($ownerSource . self::PAD, 0, 32);

        // /O entry (Algorithm 3).
        $ownerKey = self::md5Loop($ownerPadded);
        $owner = self::rc4($ownerKey, $userPadded);
        for ($i = 1; $i <= 19; $i++) {
            $owner = self::rc4(self::xorKey($ownerKey, $i), $owner);
        }

        // Encryption key (Algorithm 2).
        $keyInput = $userPadded . $owner . \pack('V', $encryption->permissions & 0xFFFFFFFF) . $documentId;
        $encryptionKey = self::md5Loop($keyInput);

        // /U entry (Algorithm 5, R3/R4).
        $user = self::rc4($encryptionKey, \md5(self::PAD . $documentId, true));
        for ($i = 1; $i <= 19; $i++) {
            $user = self::rc4(self::xorKey($encryptionKey, $i), $user);
        }
        $user = \substr($user . \str_repeat("\x00", 16), 0, 32);

        return new self($encryptionKey, $owner, $user, $encryption->permissions, $documentId);
    }

    /**
     * Encrypt a string or stream belonging to the given object with AES-128-CBC
     * (a fresh IV is prepended, per the AESV2 crypt filter).
     */
    public function encrypt(int $objectId, string $data): string
    {
        $iv = \random_bytes(16);
        $ciphertext = \openssl_encrypt($data, 'aes-128-cbc', $this->objectKey($objectId), \OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw SecurityException::encryptionFailed('AES-128-CBC encryption failed.');
        }

        return $iv . $ciphertext;
    }

    /**
     * The body of the /Encrypt dictionary (not itself encrypted).
     */
    public function encryptionDictionary(): string
    {
        return \sprintf(
            '<< /Filter /Standard /V 4 /R 4 /Length 128 '
            . '/CF << /StdCF << /Type /CryptFilter /CFM /AESV2 /Length 16 >> >> /StmF /StdCF /StrF /StdCF '
            . '/O <%s> /U <%s> /P %d >>',
            \bin2hex($this->ownerEntry),
            \bin2hex($this->userEntry),
            $this->permissions,
        );
    }

    /**
     * The file identifier as a hex string (used for /ID in the trailer).
     */
    public function idHex(): string
    {
        return \bin2hex($this->documentId);
    }

    /**
     * Per-object AES key (Algorithm 1, AESV2 salt).
     */
    private function objectKey(int $objectId): string
    {
        $extended = \substr(\pack('V', $objectId), 0, 3) . "\x00\x00" . 'sAlT';

        return \substr(\md5($this->encryptionKey . $extended, true), 0, 16);
    }

    /**
     * MD5 followed by 50 re-hashings of the first 16 bytes (R3/R4).
     */
    private static function md5Loop(string $seed): string
    {
        $hash = \md5($seed, true);
        for ($i = 0; $i < 50; $i++) {
            $hash = \md5(\substr($hash, 0, 16), true);
        }

        return \substr($hash, 0, 16);
    }

    private static function xorKey(string $key, int $value): string
    {
        $out = '';
        for ($b = 0; $b < 16; $b++) {
            $out .= \chr(\ord($key[$b]) ^ $value);
        }

        return $out;
    }

    private static function rc4(string $key, string $data): string
    {
        $state = \range(0, 255);
        $keyLength = \strlen($key);

        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $state[$i] + \ord($key[$i % $keyLength])) & 0xFF;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }

        $i = $j = 0;
        $out = '';
        $length = \strlen($data);
        for ($k = 0; $k < $length; $k++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $state[$i]) & 0xFF;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $out .= \chr(\ord($data[$k]) ^ $state[($state[$i] + $state[$j]) & 0xFF]);
        }

        return $out;
    }
}
