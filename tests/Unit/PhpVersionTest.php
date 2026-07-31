<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\PhpVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpVersion::class)]
final class PhpVersionTest extends TestCase
{
    #[Test]
    public function it_allows_the_minimum_and_newer_versions(): void
    {
        PhpVersion::guard(PhpVersion::MINIMUM_ID, '8.3.0');
        PhpVersion::guard(80400, '8.4.1');
        $this->addToAssertionCount(2); // no exception thrown
    }

    #[Test]
    public function it_rejects_older_versions_with_the_error_code(): void
    {
        try {
            PhpVersion::guard(80299, '8.2.99');
            self::fail('Expected a RuntimeException for PHP < 8.3.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('MP-CORE-1001', $e->getMessage());
            self::assertStringContainsString('8.3.0', $e->getMessage());
            self::assertStringContainsString('8.2.99', $e->getMessage());
        }
    }

    #[Test]
    public function the_running_interpreter_satisfies_the_guard(): void
    {
        // The suite itself runs on a supported PHP, so this must not throw.
        PhpVersion::guard(\PHP_VERSION_ID, \PHP_VERSION);
        $this->addToAssertionCount(1);
    }
}
