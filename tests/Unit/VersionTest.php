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

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Version;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    #[Test]
    public function it_reports_a_semver_string(): void
    {
        self::assertSame('1.0.0', Version::VERSION);
        self::assertSame('1.0.0', Version::version());
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Version::version());
    }

    #[Test]
    public function the_string_matches_its_component_constants(): void
    {
        self::assertSame(
            \sprintf('%d.%d.%d', Version::MAJOR, Version::MINOR, Version::PATCH),
            Version::VERSION,
        );
    }

    #[Test]
    public function the_producer_string_carries_the_version(): void
    {
        self::assertSame('MejiroPDF 1.0.0', Version::producer());
    }
}
