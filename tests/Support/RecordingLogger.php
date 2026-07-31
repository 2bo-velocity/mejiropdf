<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps everything it is told, so a test can assert on both
 * what was logged and — just as importantly for this engine — how many times.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : 'info',
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * The first record whose message contains $needle, or null.
     *
     * @return array{level: string, message: string, context: array<string, mixed>}|null
     */
    public function firstWhere(string $needle): ?array
    {
        foreach ($this->records as $record) {
            if (\str_contains($record['message'], $needle)) {
                return $record;
            }
        }

        return null;
    }
}
