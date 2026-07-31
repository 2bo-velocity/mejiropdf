<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Font;

use MejiroPDF\Exception\FontException;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Font\TrueTypeSubsetter;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TrueTypeSubsetter::class)]
final class TrueTypeSubsetterTest extends TestCase
{
    use FindFont;

    private function rawFont(): string
    {
        return (string) \file_get_contents($this->findTrueTypeFont());
    }

    /**
     * @return list<string>
     */
    private function tableTags(string $sfnt): array
    {
        $numTables = (\ord($sfnt[4]) << 8) | \ord($sfnt[5]);
        $tags = [];
        for ($i = 0; $i < $numTables; $i++) {
            $tags[] = \substr($sfnt, 12 + $i * 16, 4);
        }

        return $tags;
    }

    #[Test]
    public function it_shrinks_a_large_font_dramatically(): void
    {
        $raw = $this->rawFont();
        $font = TrueTypeFont::fromString($raw);

        $gids = \array_map(
            static fn (string $c): int => $font->gidForCodepoint(\mb_ord($c, 'UTF-8')),
            ['A', 'B', 'C', 'a', 'b', 'c'],
        );

        $subset = TrueTypeSubsetter::subset($raw, $gids);

        self::assertLessThan(\strlen($raw), \strlen($subset));
        // A handful of Latin glyphs out of thousands: expect a big reduction.
        self::assertLessThan(\strlen($raw) / 4, \strlen($subset));
    }

    #[Test]
    public function it_produces_a_valid_sfnt_with_only_essential_tables(): void
    {
        $raw = $this->rawFont();
        $subset = TrueTypeSubsetter::subset($raw, [0]);

        // sfnt version 1.0 (0x00010000).
        self::assertSame("\x00\x01\x00\x00", \substr($subset, 0, 4));

        $tags = $this->tableTags($subset);
        foreach (['glyf', 'loca', 'head', 'hhea', 'hmtx', 'maxp'] as $required) {
            self::assertContains($required, $tags, "subset must keep '{$required}'");
        }
        // Non-essential tables are dropped for a FontFile2 (Identity-H) embed.
        self::assertNotContains('cmap', $tags);
        self::assertNotContains('post', $tags);
    }

    #[Test]
    public function it_rejects_data_without_glyf_outlines(): void
    {
        $this->expectException(FontException::class);

        // A truncated / non-glyf blob cannot be subset.
        TrueTypeSubsetter::subset("\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", [0]);
    }
}
