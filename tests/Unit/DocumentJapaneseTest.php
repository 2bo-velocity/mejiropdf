<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\CreditOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentJapaneseTest extends TestCase
{
    #[Test]
    public function it_emits_a_type0_cid_font_for_japanese_text(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 40, '日本語', font: CidFontJapanese::HEISEI_KAKU_GO, size: 18.0);

        $bytes = $pdf->output();

        self::assertStringContainsString('/Subtype /Type0', $bytes);
        self::assertStringContainsString('/Subtype /CIDFontType0', $bytes);
        self::assertStringContainsString('/Encoding /UniJIS-UTF16-H', $bytes);
        self::assertStringContainsString('/BaseFont /HeiseiKakuGo-W5', $bytes);
        self::assertStringContainsString('/Ordering (Japan1)', $bytes);
    }

    #[Test]
    public function it_writes_japanese_as_utf16be_hex(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 40, '日本語', font: CidFontJapanese::HEISEI_MIN_W3);

        $bytes = $pdf->output();

        // UTF-16BE hex operand, not a literal ( … ).
        self::assertStringContainsString('<65E5672C8A9E> Tj', $bytes);
    }

    #[Test]
    public function it_can_mix_standard_and_cid_fonts_on_one_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()
            ->text(20, 30, 'Invoice', font: StandardFont::HELVETICA, size: 20.0)
            ->text(20, 45, '請求書', font: CidFontJapanese::HEISEI_MIN_W3, size: 16.0);

        $bytes = $pdf->output();

        self::assertStringContainsString('/BaseFont /Helvetica', $bytes);
        self::assertStringContainsString('/BaseFont /HeiseiMin-W3', $bytes);
        // Two distinct font resources referenced by the page tree.
        self::assertMatchesRegularExpression('~/Font << /F1 \d+ 0 R /F2 \d+ 0 R~', $bytes);
    }

    #[Test]
    public function a_japanese_default_font_applies_without_per_call_font(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setDefaultFont(CidFontJapanese::HEISEI_KAKU_GO);
        $pdf->addPage()->text(20, 40, '既定フォント');

        self::assertStringContainsString('/Subtype /Type0', $pdf->output());
    }
}
