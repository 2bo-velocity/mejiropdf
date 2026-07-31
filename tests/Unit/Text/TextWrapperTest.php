<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Font\StandardFont;
use MejiroPDF\Font\StandardFontMetrics;
use MejiroPDF\Text\TextWrapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextWrapper::class)]
final class TextWrapperTest extends TestCase
{
    private function wrapper(float $size = 10.0): TextWrapper
    {
        return new TextWrapper(new StandardFontMetrics(StandardFont::HELVETICA), $size);
    }

    #[Test]
    public function it_keeps_short_text_on_one_line(): void
    {
        self::assertSame(['Hello world'], $this->wrapper()->wrap('Hello world', 500.0));
    }

    #[Test]
    public function it_wraps_on_word_boundaries(): void
    {
        // A narrow box forces one word per line (40pt fits any single word
        // here, but not two — "alpha beta" is ~47pt).
        $lines = $this->wrapper()->wrap('alpha beta gamma', 40.0);

        self::assertSame(['alpha', 'beta', 'gamma'], $lines);
    }

    #[Test]
    public function it_preserves_explicit_newlines(): void
    {
        $lines = $this->wrapper()->wrap("line1\nline2", 500.0);

        self::assertSame(['line1', 'line2'], $lines);
    }

    #[Test]
    public function it_breaks_a_word_that_is_wider_than_the_box(): void
    {
        $lines = $this->wrapper()->wrap('WWWWWWWWWW', 20.0);

        self::assertGreaterThan(1, \count($lines));
        self::assertSame('WWWWWWWWWW', \implode('', $lines));
    }
}
