<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Log;

use MejiroPDF\Log\FileLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/mejiro-log-' . \bin2hex(\random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->directory);
    }

    private function contents(FileLogger $logger): string
    {
        $path = $logger->currentPath();

        return \is_file($path) ? (string) \file_get_contents($path) : '';
    }

    #[Test]
    public function it_is_a_psr3_logger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new FileLogger($this->directory));
    }

    #[Test]
    public function it_writes_a_dated_file_creating_the_directory(): void
    {
        $logger = new FileLogger($this->directory);
        self::assertDirectoryDoesNotExist($this->directory);

        $logger->info('Finalized the document');

        self::assertSame($this->directory . '/mp_' . \date('Ymd') . '.log', $logger->currentPath());
        self::assertStringContainsString('INFO: Finalized the document', $this->contents($logger));
    }

    #[Test]
    public function it_appends_rather_than_truncating(): void
    {
        $logger = new FileLogger($this->directory);
        $logger->warning('first');
        $logger->warning('second');

        $contents = $this->contents($logger);
        self::assertStringContainsString('first', $contents);
        self::assertStringContainsString('second', $contents);
        self::assertSame(2, \substr_count($contents, "\n"));
    }

    #[Test]
    public function it_interpolates_placeholders_and_appends_the_context(): void
    {
        $logger = new FileLogger($this->directory);
        $logger->info('Finalized {pages} page(s)', ['pages' => 12, 'bytes' => 3456]);

        $line = $this->contents($logger);
        self::assertStringContainsString('Finalized 12 page(s)', $line);
        self::assertStringContainsString('{"pages":12,"bytes":3456}', $line);
    }

    #[Test]
    public function it_drops_anything_below_the_minimum_level(): void
    {
        $logger = new FileLogger($this->directory, LogLevel::WARNING);

        $logger->debug('noise');
        $logger->info('also noise');
        self::assertSame('', $this->contents($logger), 'nothing is even created');

        $logger->warning('kept');
        $logger->emergency('also kept');

        $contents = $this->contents($logger);
        self::assertStringNotContainsString('noise', $contents);
        self::assertStringContainsString('kept', $contents);
        self::assertStringContainsString('also kept', $contents);
    }

    #[Test]
    public function the_prefix_is_configurable_for_the_studio(): void
    {
        $logger = new FileLogger($this->directory, LogLevel::DEBUG, 'mps');

        self::assertStringEndsWith('/mps_' . \date('Ymd') . '.log', $logger->currentPath());
    }

    #[Test]
    public function an_unwritable_destination_is_survivable(): void
    {
        // A path under a file cannot become a directory, so every write fails.
        $blocker = \sys_get_temp_dir() . '/mejiro-log-blocker-' . \bin2hex(\random_bytes(4));
        \file_put_contents($blocker, 'not a directory');

        try {
            $logger = new FileLogger($blocker . '/nested');
            $logger->error('this cannot be written anywhere');

            // Losing a diagnostic must never cost the caller their document.
            self::assertTrue(true, 'logging did not throw');
        } finally {
            @\unlink($blocker);
        }
    }

    #[Test]
    public function a_context_value_that_cannot_be_encoded_does_not_break_the_line(): void
    {
        $logger = new FileLogger($this->directory);
        $recursive = ['self' => null];
        $recursive['self'] = &$recursive;

        $logger->error('Broke on {thing}', ['thing' => 'a cycle', 'cycle' => $recursive]);

        self::assertStringContainsString('Broke on a cycle', $this->contents($logger));
    }
}
