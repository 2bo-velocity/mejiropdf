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
 * Locates a TrueType font for the embedding tests. Set MEJIROPDF_TEST_TTF to
 * override; otherwise a few common DejaVuSans locations are probed.
 */
trait FindFont
{
    protected function findTrueTypeFont(): string
    {
        $env = \getenv('MEJIROPDF_TEST_TTF');
        if (\is_string($env) && $env !== '' && \is_readable($env)) {
            return $env;
        }

        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu-sans-fonts/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/Library/Fonts/DejaVuSans.ttf',
        ];
        foreach ($candidates as $path) {
            if (\is_readable($path)) {
                return $path;
            }
        }

        foreach (\glob('/usr/share/fonts/**/DejaVuSans.ttf') ?: [] as $path) {
            if (\is_readable($path)) {
                return $path;
            }
        }

        self::markTestSkipped('No TrueType test font found (set MEJIROPDF_TEST_TTF).');
    }
}
