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

namespace MejiroPDF\Block;

/**
 * Decides whether a {@see Block} is drawn for this record.
 *
 * The grammar is deliberately closed and tiny:
 *
 * ```text
 *   expr    := term (('&&' | '||') term)*
 *   term    := '!'? path (op literal)?
 *   op      := '===' | '!==' | '==' | '!=' | '>=' | '<=' | '>' | '<'
 *   literal := true | false | null | number | 'string' | "string"
 * ```
 *
 * A bare path is a truth test, so `items.length` and `props.showBank` read the
 * way a designer expects.
 *
 * **`eval()` is never used, and this is not a detail.** A template is data — it
 * arrives from a designer's file, and in a hosted Studio it could arrive from a
 * stranger. Handing that to `eval()` would turn a layout description into
 * arbitrary code execution on the report server. A closed grammar cannot call a
 * function, touch a superglobal, or reach the filesystem, because it has no
 * production for any of those. The cost of that safety is roughly a microsecond
 * per block.
 *
 * A malformed expression is **true**, not an error: an unparsable condition
 * should leave the block visible so the mistake is seen on the page, rather
 * than silently deleting content from every document in a batch.
 */
final class Condition
{
    /** Longest expression accepted, as a guard against pathological input. */
    private const int MAX_LENGTH = 512;

    /**
     * @param array<string, mixed> $data
     */
    public static function isTrue(?string $expression, array $data): bool
    {
        $expression = $expression === null ? '' : \trim($expression);
        if ($expression === '' || \strlen($expression) > self::MAX_LENGTH) {
            return true; // no condition, or one too long to trust: draw it
        }

        // Split on the logical operators first; they bind loosest. Evaluated
        // left to right without precedence between && and ||, which is enough
        // for the conditions templates actually carry and keeps the parser flat.
        $parts = \preg_split('/\s*(&&|\|\|)\s*/', $expression, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || $parts === []) {
            return true;
        }

        $result = self::term($parts[0], $data);
        for ($i = 1; $i + 1 < \count($parts); $i += 2) {
            $next = self::term($parts[$i + 1], $data);
            $result = $parts[$i] === '&&' ? ($result && $next) : ($result || $next);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function term(string $term, array $data): bool
    {
        $term = \trim($term);
        if ($term === '') {
            return true;
        }

        if (\str_starts_with($term, '!')) {
            return !self::term(\substr($term, 1), $data);
        }

        if (\preg_match('/^([A-Za-z_][\w.]*)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/', $term, $m) !== 1) {
            // A bare path is a truth test. Anything that is not shaped like a
            // path was not understood at all, and an unparsable condition
            // leaves the block visible so the mistake reaches the page.
            return \preg_match('/^[A-Za-z_][\w.]*$/', $term) === 1
                ? self::truthy(DataPath::resolve($data, $term))
                : true;
        }

        $left = DataPath::resolve($data, $m[1]);
        $right = self::literal(\trim($m[3]));

        // Exhaustive: the operator came from the regex alternation above.
        return match ($m[2]) {
            // Loose and strict comparison are both mapped to PHP's strict form
            // after a scalar cast, so '5' == 5 holds while null == false does
            // not. Template data arrives as strings often enough (a JSON field,
            // a form value) that strict-only comparison would fail surprisingly.
            '===', '==' => self::equals($left, $right, strict: $m[2] === '==='),
            '!==', '!=' => !self::equals($left, $right, strict: $m[2] === '!=='),
            '>' => self::number($left) > self::number($right),
            '>=' => self::number($left) >= self::number($right),
            '<' => self::number($left) < self::number($right),
            '<=' => self::number($left) <= self::number($right),
        };
    }

    private static function equals(mixed $left, mixed $right, bool $strict): bool
    {
        if ($strict && \is_string($left) !== \is_string($right)) {
            // A strict comparison against a quoted literal still succeeds for
            // numeric data written as a string, which is the common case.
            return \is_scalar($left) && \is_scalar($right)
                && (string) $left === (string) $right;
        }

        if (\is_scalar($left) && \is_scalar($right)) {
            return \is_bool($left) || \is_bool($right)
                ? self::truthy($left) === self::truthy($right)
                : (string) $left === (string) $right;
        }

        return $left === $right;
    }

    private static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            \is_bool($value) => $value,
            \is_array($value) => $value !== [],
            \is_string($value) => $value !== '' && $value !== '0' && $value !== 'false',
            \is_int($value) || \is_float($value) => (float) $value !== 0.0,
            default => true,
        };
    }

    private static function number(mixed $value): float
    {
        return \is_numeric($value) ? (float) $value : (self::truthy($value) ? 1.0 : 0.0);
    }

    /**
     * A literal token: `true` / `false` / `null` / a number / a quoted string.
     */
    private static function literal(string $token): mixed
    {
        $lower = \strtolower($token);
        if ($lower === 'true' || $lower === 'false') {
            return $lower === 'true';
        }
        if ($lower === 'null') {
            return null;
        }
        if (\is_numeric($token)) {
            return $token + 0;
        }

        $quote = $token[0] ?? '';
        if (($quote === "'" || $quote === '"') && \strlen($token) >= 2 && \str_ends_with($token, $quote)) {
            return \substr($token, 1, -1);
        }

        return $token; // a bare word compares as itself
    }
}
