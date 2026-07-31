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
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentDropCapTest extends TestCase
{
    private function document(): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        return $pdf;
    }

    #[Test]
    public function an_explicit_dropcap_enlarges_the_initial(): void
    {
        $pdf = $this->document();
        $pdf->typeset(
            '[O]{.dropcap}nce upon a time there lived a small bird in a tall green tree.',
            20,
            20,
            70,
            font: StandardFont::HELVETICA,
            size: 12.0,
        );

        $out = $pdf->output();

        // The cap is set at 3× the body size (36 pt) and is not part of the flow.
        self::assertMatchesRegularExpression('~/F\d+ 36\.00 Tf\n1 0 0 1 [\d.]+ [\d.]+ Tm\n\(O\) Tj~', $out);
        self::assertStringContainsString('(n) Tj', $out); // the "nce…" continues at body size
        self::assertStringContainsString('/F1 12.00 Tf', $out);
    }

    #[Test]
    public function enable_drop_caps_auto_applies_to_the_first_letter(): void
    {
        $pdf = $this->document();
        $pdf->typeset(
            'Once upon a time there lived a small bird in a tall green tree by the sea.',
            20,
            20,
            70,
            font: StandardFont::HELVETICA,
            size: 12.0,
            options: new TypesetterOptions(enableDropCaps: true),
        );

        self::assertMatchesRegularExpression('~/F\d+ 36\.00 Tf\n1 0 0 1 [\d.]+ [\d.]+ Tm\n\(O\) Tj~', $pdf->output());
    }

    #[Test]
    public function plain_text_has_no_enlarged_initial(): void
    {
        $pdf = $this->document();
        $pdf->typeset('Once upon a time', 20, 20, 100, font: StandardFont::HELVETICA, size: 12.0);

        self::assertStringNotContainsString('36.00 Tf', $pdf->output());
    }
}
