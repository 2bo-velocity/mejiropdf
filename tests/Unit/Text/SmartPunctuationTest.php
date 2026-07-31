<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\SmartPunctuation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmartPunctuation::class)]
final class SmartPunctuationTest extends TestCase
{
    #[Test]
    public function it_curls_double_quotes_by_context(): void
    {
        self::assertSame("\u{201C}yes\u{201D}", SmartPunctuation::apply('"yes"'));
        self::assertSame("say \u{201C}hi\u{201D}", SmartPunctuation::apply('say "hi"'));
    }

    #[Test]
    public function it_curls_single_quotes_and_apostrophes(): void
    {
        self::assertSame("\u{2018}a\u{2019}", SmartPunctuation::apply("'a'"));
        // Apostrophe after a letter closes.
        self::assertSame("don\u{2019}t", SmartPunctuation::apply("don't"));
        self::assertSame("it\u{2019}s \u{2018}quoted\u{2019}", SmartPunctuation::apply("it's 'quoted'"));
    }

    #[Test]
    public function it_converts_double_and_triple_hyphens_to_an_em_dash(): void
    {
        self::assertSame("a\u{2014}b", SmartPunctuation::apply('a--b'));
        self::assertSame("a\u{2014}b", SmartPunctuation::apply('a---b'));
        // A quote right after the dash opens.
        self::assertSame("wait\u{2014}\u{201C}no\u{201D}", SmartPunctuation::apply('wait--"no"'));
    }

    #[Test]
    public function it_leaves_text_without_punctuation_untouched(): void
    {
        self::assertSame('plain 日本語 text', SmartPunctuation::apply('plain 日本語 text'));
        self::assertSame('', SmartPunctuation::apply(''));
    }
}
