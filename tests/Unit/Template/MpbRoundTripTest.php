<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Template;

use MejiroPDF\Page\Alignment;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\Mpb;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MpbWriter::class)]
#[CoversClass(CompiledTemplateLoader::class)]
final class MpbRoundTripTest extends TestCase
{
    private function sampleBytes(): string
    {
        return MpbWriter::build(
            width: 595.28,
            height: 841.89,
            staticVectorOps: "0.5 w 20 20 100 50 re S\n",
            texts: [new TemplateText('Invoice', 20, 20, 'Helvetica', 18.0, Alignment::LEFT)],
            bindings: [new DynamicBinding('customer', 30, 40, 'HeiseiMin-W3', 12.0, Alignment::RIGHT)],
        );
    }

    #[Test]
    public function it_writes_the_mpbf_magic(): void
    {
        self::assertStringStartsWith('MPBF', $this->sampleBytes());
    }

    #[Test]
    public function it_round_trips_every_field(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->sampleBytes());

        self::assertSame(1, $tpl->version);
        self::assertEqualsWithDelta(595.28, $tpl->width, 0.001);
        self::assertEqualsWithDelta(841.89, $tpl->height, 0.001);
        self::assertSame("0.5 w 20 20 100 50 re S\n", $tpl->staticVectorOps);

        self::assertCount(1, $tpl->texts);
        self::assertSame('Invoice', $tpl->texts[0]->text);
        self::assertSame('Helvetica', $tpl->texts[0]->font);
        self::assertSame(18.0, $tpl->texts[0]->fontSize);

        self::assertArrayHasKey('customer', $tpl->bindings);
        self::assertSame('HeiseiMin-W3', $tpl->bindings['customer']->font);
        self::assertSame(Alignment::RIGHT, $tpl->bindings['customer']->align);
    }

    #[Test]
    public function from_template_reproduces_identical_bytes(): void
    {
        $bytes = $this->sampleBytes();
        $tpl = CompiledTemplateLoader::loadString($bytes);

        self::assertSame($bytes, MpbWriter::fromTemplate($tpl));
    }

    #[Test]
    public function an_empty_template_is_valid(): void
    {
        $tpl = CompiledTemplateLoader::loadString(MpbWriter::build(100.0, 100.0, ''));

        self::assertSame('', $tpl->staticVectorOps);
        self::assertSame([], $tpl->texts);
        self::assertSame([], $tpl->bindings);
    }

    #[Test]
    public function the_flags_bitmask_round_trips(): void
    {
        $bytes = MpbWriter::build(100.0, 100.0, '', flags: Mpb::FLAG_LANDSCAPE);
        $tpl = CompiledTemplateLoader::loadString($bytes);

        self::assertTrue(Mpb::hasFlag($tpl->flags, Mpb::FLAG_LANDSCAPE));
        self::assertFalse(Mpb::hasFlag($tpl->flags, Mpb::FLAG_ENCRYPTED));
    }
}
