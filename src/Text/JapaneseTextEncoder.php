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

namespace MejiroPDF\Text;

/**
 * Encodes UTF-8 text as a PDF UTF-16BE hex string (`<FEFF…>`), for use with
 * Identity-H / CID fonts. Example: "日本語" -> "<FEFF65E5672C8A9E>".
 */
final class JapaneseTextEncoder
{
    /**
     * Encode UTF-8 as a PDF hex string of raw UTF-16BE code units (no BOM), for
     * a Type0 font using the UniJIS-UTF16-H CMap in a content stream.
     * Example: "日本語" -> "<65E5672C8A9E>".
     */
    public static function toCidHex(string $utf8Text): string
    {
        if ($utf8Text === '') {
            return '<>';
        }

        $utf16be = \mb_convert_encoding($utf8Text, 'UTF-16BE', 'UTF-8');

        return '<' . \strtoupper(\bin2hex($utf16be)) . '>';
    }

    /**
     * Encode UTF-8 as a BOM-prefixed UTF-16BE hex string, for PDF text strings
     * (document info, ToUnicode, outline titles). Example: "日" -> "<FEFF65E5>".
     */
    public static function toPdfHex(string $utf8Text): string
    {
        if ($utf8Text === '') {
            return '<FEFF>';
        }

        $utf16be = \mb_convert_encoding($utf8Text, 'UTF-16BE', 'UTF-8');

        return '<FEFF' . \strtoupper(\bin2hex($utf16be)) . '>';
    }

    /**
     * Heuristic: does the string contain any non-ASCII (multibyte) character?
     */
    public static function isMultibyte(string $text): bool
    {
        return \strlen($text) !== \mb_strlen($text, 'UTF-8');
    }
}
