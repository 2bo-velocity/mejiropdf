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
use MejiroPDF\Text\Hyphenator;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentHyphenationTest extends TestCase
{
    private function document(): Document
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        return $pdf;
    }

    #[Test]
    public function it_hyphenates_a_long_word_in_a_narrow_column(): void
    {
        $pdf = $this->document();
        // "typography" (ty-pog-ra-phy) is wider than 15 mm at 11 pt, so it breaks.
        $pdf->typeset('typography', 20, 20, 15, font: StandardFont::HELVETICA, size: 11.0);

        self::assertStringContainsString('(-) Tj', $pdf->output()); // a hyphen was drawn
    }

    #[Test]
    public function hyphenation_can_be_turned_off(): void
    {
        $pdf = $this->document();
        $pdf->typeset(
            'typography',
            20,
            20,
            15,
            font: StandardFont::HELVETICA,
            size: 11.0,
            options: new TypesetterOptions(enableHyphenation: false),
        );

        self::assertStringNotContainsString('(-) Tj', $pdf->output());
    }

    #[Test]
    public function a_custom_hyphenator_can_be_supplied(): void
    {
        $pdf = $this->document();
        $pdf->setHyphenator(new Hyphenator('', 'wid-get', 2, 3));
        $pdf->typeset('widget', 20, 20, 8, font: StandardFont::HELVETICA, size: 11.0);

        self::assertStringContainsString('(-) Tj', $pdf->output());
    }
}
