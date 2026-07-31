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

namespace MejiroPDF\Template;

/**
 * Constants of the MPBF (MejiroPDF Binary Format) container — the **finalized
 * v1.0 format**.
 *
 * Layout (Big-Endian / Network Byte Order):
 *   Header, 16 bytes:
 *     0x00..0x03  Magic "MPBF"
 *     0x04..0x05  Format version (u16) = 1
 *     0x06..0x07  Flags bitmask (u16), assignments locked below
 *     0x08..0x0B  Static segment length (u32)
 *     0x0C..0x0F  Bindings segment length (u32)
 *   Static PDF stream segment (background vector operators; static_len bytes)
 *   Bindings segment (UTF-8 JSON: meta + static texts + variable bindings)
 *   Font segment (optional; present iff FLAG_FONTS is set — see below)
 *
 * The format is self-identifying: a future incompatible layout would carry a
 * different magic, which this loader rejects, so v1 files never mis-parse.
 * Unknown flag bits are reserved (must be 0) and are ignored by v1 readers,
 * which is what lets segments be added — the font segment was — without a
 * version bump breaking older engines.
 */
final class Mpb
{
    public const string MAGIC = 'MPBF';
    public const int VERSION = 1;
    public const int HEADER_SIZE = 16;

    /** pack()/unpack() format for the 16-byte header. */
    public const string HEADER_FORMAT = 'a4magic/nversion/nflags/NstaticLen/NbindLen';

    // Flags bitmask (locked for v1).
    /** The page is laid out in landscape orientation. */
    public const int FLAG_LANDSCAPE = 0x0001;
    /** Reserved: the segments are encrypted. */
    public const int FLAG_ENCRYPTED = 0x0002;
    /** Reserved: the segments are Flate-compressed. */
    public const int FLAG_COMPRESSED = 0x0004;
    /**
     * A font segment follows the bindings segment.
     *
     * Its length is not in the header — the header is 16 bytes and cannot grow
     * without breaking every reader — so the segment describes itself:
     *
     *   u16  font count
     *   per font:
     *     u16  PostScript name length, then that many UTF-8 bytes
     *     u32  font data length, then that many bytes (a whole `.ttf`)
     *
     * A reader that predates this flag stops after the bindings segment and
     * ignores what follows, so the file still loads; it then fails loudly on
     * the first binding naming an embedded font rather than drawing it wrong.
     */
    public const int FLAG_FONTS = 0x0008;

    /** Bits defined by v1; a conforming v1 file sets no others. */
    public const int KNOWN_FLAGS = self::FLAG_LANDSCAPE
        | self::FLAG_ENCRYPTED
        | self::FLAG_COMPRESSED
        | self::FLAG_FONTS;

    public static function hasFlag(int $flags, int $flag): bool
    {
        return ($flags & $flag) === $flag;
    }
}
