<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Text;

use MejiroPDF\Text\TextAlign;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypesetterOptions::class)]
#[CoversClass(WritingMode::class)]
#[CoversClass(TextAlign::class)]
final class TypesetterOptionsTest extends TestCase
{
    #[Test]
    public function it_defaults_to_horizontal_start_with_japanese_features_on(): void
    {
        $options = new TypesetterOptions();

        self::assertSame(WritingMode::HORIZONTAL_TB, $options->writingMode);
        self::assertFalse($options->writingMode->isVertical());
        self::assertSame(TextAlign::START, $options->align);
        self::assertTrue($options->enableRuby);
        self::assertTrue($options->enableBouten);
        self::assertTrue($options->enableJapaneseKinsoku);
        self::assertTrue($options->enableBurasage);
        self::assertSame(2, $options->minLinesAtPageBreak);
        self::assertSame('ja', $options->locale);
        self::assertFalse($options->skipsInlineMarkup());
    }

    #[Test]
    public function novel_preset_is_vertical_with_ruby_and_bouten(): void
    {
        $options = TypesetterOptions::novel();

        self::assertSame(WritingMode::VERTICAL_RL, $options->writingMode);
        self::assertTrue($options->writingMode->isVertical());
        self::assertTrue($options->enableRuby);
        self::assertTrue($options->enableBouten);
    }

    #[Test]
    public function novel_preset_accepts_an_alignment_override(): void
    {
        self::assertSame(TextAlign::CENTER, TypesetterOptions::novel(align: TextAlign::CENTER)->align);
    }

    #[Test]
    public function plain_preset_disables_markup_and_substitutions(): void
    {
        $options = TypesetterOptions::plain();

        self::assertFalse($options->enableRuby);
        self::assertFalse($options->enableBouten);
        self::assertFalse($options->enableSmartQuotes);
        self::assertFalse($options->enableHyphenation);
        self::assertFalse($options->enableDropCaps);
        self::assertTrue($options->skipsInlineMarkup());
        // Line-breaking niceties that never rewrite content stay on.
        self::assertTrue($options->enableJapaneseKinsoku);
    }

    #[Test]
    public function it_rejects_fewer_than_one_line_at_a_page_break(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TypesetterOptions(minLinesAtPageBreak: 0);
    }

    #[Test]
    public function enums_expose_their_backing_values(): void
    {
        self::assertSame('vertical-rl', WritingMode::VERTICAL_RL->value);
        self::assertSame('horizontal-tb', WritingMode::HORIZONTAL_TB->value);
        self::assertSame('justify', TextAlign::JUSTIFY->value);
        self::assertSame(WritingMode::VERTICAL_RL, WritingMode::from('vertical-rl'));
    }
}
