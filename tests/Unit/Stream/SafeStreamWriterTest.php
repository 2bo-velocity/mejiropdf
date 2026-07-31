<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Stream;

use MejiroPDF\Exception\MejiroPDFException;
use MejiroPDF\Exception\StreamException;
use MejiroPDF\Stream\SafeStreamWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SafeStreamWriter::class)]
final class SafeStreamWriterTest extends TestCase
{
    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    #[Test]
    public function it_tracks_byte_offset_accurately(): void
    {
        $writer = new SafeStreamWriter($this->stream);

        self::assertSame(0, $writer->getOffset());

        self::assertSame(5, $writer->write('Hello'));
        self::assertSame(5, $writer->getOffset());

        self::assertSame(6, $writer->write(' World'));
        self::assertSame(11, $writer->getOffset());
    }

    #[Test]
    public function it_appends_a_single_newline_on_write_line(): void
    {
        $writer = new SafeStreamWriter($this->stream);

        // "%PDF-1.7" is 8 bytes; writeLine appends "\n" for 9.
        self::assertSame(9, $writer->writeLine('%PDF-1.7'));
        self::assertSame(9, $writer->getOffset());

        \rewind($this->stream);
        self::assertSame("%PDF-1.7\n", \stream_get_contents($this->stream));
    }

    #[Test]
    public function it_counts_multibyte_utf8_by_byte_length(): void
    {
        $writer = new SafeStreamWriter($this->stream);

        // "日本語" is 9 bytes in UTF-8 (3 bytes per character).
        self::assertSame(9, $writer->write('日本語'));
        self::assertSame(9, $writer->getOffset());
    }

    #[Test]
    public function it_returns_zero_for_empty_writes(): void
    {
        $writer = new SafeStreamWriter($this->stream);

        self::assertSame(0, $writer->write(''));
        self::assertSame(0, $writer->getOffset());
    }

    #[Test]
    public function it_throws_stream_exception_for_invalid_resource(): void
    {
        $this->expectException(StreamException::class);
        $this->expectExceptionMessage('Invalid stream resource provided.');

        /** @phpstan-ignore-next-line intentional bad argument */
        new SafeStreamWriter(12345);
    }

    #[Test]
    public function its_exceptions_carry_the_error_code_contract(): void
    {
        try {
            /** @phpstan-ignore-next-line intentional bad argument */
            new SafeStreamWriter(12345);
            self::fail('Expected StreamException.');
        } catch (MejiroPDFException $e) {
            self::assertSame('MP-SIO-3001', $e->getErrorCode());
        }
    }

    #[Test]
    public function it_closes_the_underlying_stream(): void
    {
        $writer = new SafeStreamWriter($this->stream);
        $writer->close();

        self::assertTrue($writer->isClosed());
        self::assertFalse(\is_resource($this->stream));
    }
}
