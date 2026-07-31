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
 * Reads a dotted path — `customer.name`, `items.length` — out of the render
 * data.
 *
 * A template says where a value lives; this walks there. A path that leads
 * nowhere yields null rather than an error, because a report with a field its
 * record happens not to carry should print the rest of the record, not fail.
 * Length limits and required fields are the validator's job, not the reader's.
 */
final class DataPath
{
    /**
     * The value at $path, or null when any step of it is missing.
     *
     * `.length` is understood on arrays and strings, so a template can ask
     * "are there any rows?" without the data having to carry a count field.
     *
     * @param array<string, mixed> $data
     */
    public static function resolve(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }

        $cursor = $data;
        foreach (\explode('.', $path) as $step) {
            if ($step === 'length' && (\is_array($cursor) || \is_string($cursor))) {
                return \is_array($cursor) ? \count($cursor) : \mb_strlen($cursor, 'UTF-8');
            }
            if (!\is_array($cursor) || !\array_key_exists($step, $cursor)) {
                return null;
            }
            $cursor = $cursor[$step];
        }

        return $cursor;
    }

    /**
     * The rows a repeater draws: the value at $path when it is a list, and an
     * empty list otherwise.
     *
     * A missing or non-list data source draws nothing rather than raising —
     * "no rows" is the natural reading of "no data", and it lets the same
     * template serve an invoice with items and one without.
     *
     * @param  array<string, mixed> $data
     * @return list<mixed>
     */
    public static function rows(array $data, string $path): array
    {
        $value = self::resolve($data, $path);

        return \is_array($value) ? \array_values($value) : [];
    }
}
