<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Support;

/**
 * Helpers for integration tests that shell out to an external validator
 * (openssl, veraPDF). Tests self-skip when the tool is absent, so the suite
 * stays green on a bare developer machine and runs for real inside the pinned
 * Docker image (see test/Dockerfile).
 */
trait ExternalCommand
{
    /** @var list<string> Temp files to remove on teardown. */
    private array $tempFiles = [];

    protected function requireCommand(string $name): void
    {
        $output = [];
        $code = 0;
        \exec('command -v ' . \escapeshellarg($name) . ' 2>/dev/null', $output, $code);
        if ($code !== 0) {
            self::markTestSkipped("`{$name}` is not available in this environment.");
        }
    }

    /**
     * Run a shell command, returning its exit code and combined stdout/stderr.
     *
     * @return array{int, string}
     */
    protected function runCommand(string $command): array
    {
        $output = [];
        $code = 0;
        \exec($command . ' 2>&1', $output, $code);

        return [$code, \implode("\n", $output)];
    }

    protected function tempFile(string $contents = ''): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'mp_it_');
        if ($path === false) {
            self::fail('Could not create a temporary file.');
        }
        \file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function cleanupTempFiles(): void
    {
        foreach ($this->tempFiles as $path) {
            @\unlink($path);
        }
        $this->tempFiles = [];
    }
}
