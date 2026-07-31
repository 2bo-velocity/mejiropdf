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

namespace MejiroPDF\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * A minimal PSR-3 logger writing one daily file, `mp_YYYYMMDD.log`.
 *
 * The engine takes any {@see \Psr\Log\LoggerInterface}, and defaults to writing
 * nowhere. This class exists for the project that wants a log file without
 * pulling in a logging framework: pass it explicitly and you get one.
 *
 * ```php
 * $pdf = new Document(logger: new FileLogger('/var/log/mejiropdf'));
 * ```
 *
 * It deliberately does **not** rotate, compress or prune. Those are policies a
 * PDF engine has no business deciding; a project that needs them should pass
 * Monolog (or anything else PSR-3) instead.
 *
 * **It never throws.** A log line that cannot be written is dropped in silence,
 * because losing a diagnostic must never cost the caller their document.
 */
final class FileLogger extends AbstractLogger
{
    /** PSR-3 levels from most to least severe; the index is the threshold. */
    private const array SEVERITY = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    private readonly int $threshold;

    /**
     * @param string $directory    Where the daily files go; created on first write.
     * @param string $minimumLevel The least severe level to record (a PSR-3
     *                             {@see LogLevel} constant). Anything below it is
     *                             discarded before any file work happens.
     * @param string $prefix       File-name prefix — `mp` for the library,
     *                             `mps` for MejiroPDF Studio.
     */
    public function __construct(
        private readonly string $directory,
        string $minimumLevel = LogLevel::DEBUG,
        private readonly string $prefix = 'mp',
    ) {
        $at = \array_search($minimumLevel, self::SEVERITY, true);
        $this->threshold = $at === false ? \count(self::SEVERITY) - 1 : $at;
    }

    /**
     * The file this logger would write to right now. The date is resolved per
     * call, so a long-running worker rolls over at midnight on its own.
     */
    public function currentPath(): string
    {
        return \sprintf('%s/%s_%s.log', \rtrim($this->directory, '/\\'), $this->prefix, \date('Ymd'));
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $name = \is_string($level) ? $level : (string) LogLevel::INFO;
        $at = \array_search($name, self::SEVERITY, true);
        if ($at === false || $at > $this->threshold) {
            return;
        }

        $line = \sprintf(
            "[%s] %s: %s%s\n",
            \date('Y-m-d H:i:s'),
            \strtoupper($name),
            self::interpolate((string) $message, $context),
            self::formatContext($context),
        );

        if (!\is_dir($this->directory)) {
            @\mkdir($this->directory, 0o775, true);
        }
        @\file_put_contents($this->currentPath(), $line, \FILE_APPEND | \LOCK_EX);
    }

    /**
     * Replace `{placeholder}` with the matching context value, as PSR-3 §1.2
     * asks implementations to do. Values that have no string form are left for
     * {@see formatContext} to render.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if ($context === [] || !\str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if ($value === null || \is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = $value === null ? 'null' : (string) $value;
            }
        }

        return \strtr($message, $replacements);
    }

    /**
     * Render the context as trailing JSON. Never throws: a value that will not
     * encode (a resource, a recursive structure) yields an empty suffix rather
     * than an exception.
     *
     * @param array<string, mixed> $context
     */
    private static function formatContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $json = \json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '' : ' ' . $json;
    }
}
