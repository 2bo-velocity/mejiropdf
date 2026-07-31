<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Page;

use MejiroPDF\Page\PageNumberOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PageNumberOptions::class)]
final class PageNumberOptionsTest extends TestCase
{
    #[Test]
    public function it_resolves_page_and_total(): void
    {
        $opts = new PageNumberOptions();

        self::assertSame('1 / 3', $opts->resolve(1, 3));
        self::assertSame('3 / 3', $opts->resolve(3, 3));
    }

    #[Test]
    public function it_skips_leading_pages_and_renumbers(): void
    {
        $opts = new PageNumberOptions(skipPages: 1);

        self::assertFalse($opts->shouldRender(1));
        self::assertTrue($opts->shouldRender(2));

        // Page 2 becomes logical "1", and the total drops the skipped page.
        self::assertSame(1, $opts->logicalNumber(2));
        self::assertSame('1 / 2', $opts->resolve(2, 3));
    }

    #[Test]
    public function it_honours_a_custom_start_number(): void
    {
        $opts = new PageNumberOptions(startPageNumber: 5);

        self::assertSame(5, $opts->logicalNumber(1));
        self::assertSame(6, $opts->logicalNumber(2));
    }

    #[Test]
    public function it_supports_arbitrary_formats(): void
    {
        $opts = new PageNumberOptions(format: 'Page {PAGE} of {TOTAL}');

        self::assertSame('Page 2 of 4', $opts->resolve(2, 4));
    }
}
