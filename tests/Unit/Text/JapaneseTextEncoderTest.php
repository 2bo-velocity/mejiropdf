<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\JapaneseTextEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JapaneseTextEncoder::class)]
final class JapaneseTextEncoderTest extends TestCase
{
    #[Test]
    public function cid_hex_has_no_bom(): void
    {
        // 日 = U+65E5, 本 = U+672C, 語 = U+8A9E in UTF-16BE.
        self::assertSame('<65E5672C8A9E>', JapaneseTextEncoder::toCidHex('日本語'));
    }

    #[Test]
    public function cid_hex_encodes_ascii_as_two_bytes(): void
    {
        self::assertSame('<0041>', JapaneseTextEncoder::toCidHex('A'));
    }

    #[Test]
    public function empty_cid_hex_is_empty_string(): void
    {
        self::assertSame('<>', JapaneseTextEncoder::toCidHex(''));
    }

    #[Test]
    public function pdf_hex_has_a_bom(): void
    {
        self::assertSame('<FEFF65E5>', JapaneseTextEncoder::toPdfHex('日'));
    }

    #[Test]
    public function it_detects_multibyte_text(): void
    {
        self::assertTrue(JapaneseTextEncoder::isMultibyte('日'));
        self::assertFalse(JapaneseTextEncoder::isMultibyte('abc'));
    }
}
