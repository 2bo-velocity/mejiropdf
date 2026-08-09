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
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Page\OpenFit;
use MejiroPDF\Page\ReadingDirection;
use MejiroPDF\Text\TypesetterOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(DocumentInfo::class)]
#[CoversClass(ReadingDirection::class)]
#[CoversClass(OpenFit::class)]
final class DocumentInfoAndViewTest extends TestCase
{
    use FindFont;

    #[Test]
    public function it_writes_the_authored_fields_and_the_language(): void
    {
        $pdf = new Document(
            credit: CreditOptions::disabled(),
            info: new DocumentInfo(
                title: 'The Long Scroll',
                author: 'A Novelist',
                subject: 'A vertical book',
                keywords: 'novel, japanese',
                language: 'ja',
            ),
        );
        $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);
        $out = $pdf->output();

        self::assertStringContainsString('/Title (The Long Scroll)', $out);
        self::assertStringContainsString('/Author (A Novelist)', $out);
        self::assertStringContainsString('/Subject (A vertical book)', $out);
        self::assertStringContainsString('/Lang (ja)', $out);
        // The engine's own fields are still its own.
        self::assertStringContainsString('/Creator (MejiroPDF)', $out);
    }

    #[Test]
    public function the_licence_marker_leads_the_keywords_and_the_authored_value_follows(): void
    {
        $pdf = new Document(
            credit: CreditOptions::disabled(),
            info: new DocumentInfo(keywords: 'novel, japanese'),
        );
        $pdf->addPage();

        self::assertStringContainsString(
            '/Keywords (Powered by MejiroPDF [AGPLv3 Community Edition]; novel, japanese)',
            $pdf->output(),
        );
    }

    #[Test]
    public function an_authored_copy_of_the_marker_does_not_displace_the_real_one(): void
    {
        // Writing the marker yourself must not persuade the engine to drop its
        // own — a de-duplicating rule would hand over exactly that lever.
        $pdf = new Document(
            credit: CreditOptions::disabled(),
            info: new DocumentInfo(keywords: 'Powered by MejiroPDF [AGPLv3 Community Edition]'),
        );
        $pdf->addPage();
        $out = $pdf->output();

        \preg_match('~/Keywords \(([^)]*)\)~', $out, $m);
        self::assertSame(
            'Powered by MejiroPDF [AGPLv3 Community Edition]; Powered by MejiroPDF [AGPLv3 Community Edition]',
            $m[1] ?? '',
        );
    }

    #[Test]
    public function a_visible_credit_leaves_the_keywords_to_the_author(): void
    {
        $pdf = new Document(info: new DocumentInfo(keywords: 'novel'));
        $pdf->addPage();

        self::assertStringContainsString('/Keywords (novel)', $pdf->output());
    }

    #[Test]
    public function a_document_that_says_nothing_writes_no_extra_entries(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $out = $pdf->output();

        self::assertStringNotContainsString('/Title', $out);
        self::assertStringNotContainsString('/Lang', $out);
        self::assertStringNotContainsString('/ViewerPreferences', $out);
        self::assertStringNotContainsString('/OpenAction', $out);
    }

    #[Test]
    public function setting_text_vertically_turns_the_page_order_around(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset(
            '吾輩は猫である',
            190,
            20,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: TypesetterOptions::novel(),
        );

        // Without this a two-page view shows the spreads back to front.
        self::assertStringContainsString('/ViewerPreferences << /Direction /R2L >>', $pdf->output());
    }

    #[Test]
    public function a_horizontal_document_keeps_the_default_page_order(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);

        self::assertStringNotContainsString('/Direction', $pdf->output());
    }

    #[Test]
    public function the_direction_can_be_stated_outright_either_way(): void
    {
        $forced = new Document(credit: CreditOptions::disabled(), direction: ReadingDirection::RIGHT_TO_LEFT);
        $forced->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);
        self::assertStringContainsString('/Direction /R2L', $forced->output());

        $suppressed = new Document(credit: CreditOptions::disabled(), direction: ReadingDirection::LEFT_TO_RIGHT);
        $suppressed->addPage()->typeset(
            '吾輩は猫',
            190,
            20,
            120,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            options: TypesetterOptions::novel(),
        );
        self::assertStringNotContainsString('/Direction', $suppressed->output());
    }

    #[Test]
    public function it_can_ask_the_reader_to_open_at_full_width(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), openFit: OpenFit::WIDTH);
        $pdf->addPage()->text(20, 20, 'x', font: StandardFont::HELVETICA);

        self::assertMatchesRegularExpression('~/OpenAction \[ \d+ 0 R /FitH null \]~', $pdf->output());
    }

    #[Test]
    public function the_authored_fields_reach_the_xmp_packet_as_well(): void
    {
        // PDF/A requires a property carried in both places to agree, so the two
        // are written from the same source.
        $pdf = new Document(
            credit: CreditOptions::disabled(),
            pdfA: true,
            info: new DocumentInfo(title: 'Ampersand & Co', author: 'A Novelist', keywords: 'novel'),
        );
        $pdf->setDefaultFont(TrueTypeFont::fromFile($this->findTrueTypeFont()));
        $pdf->addPage();
        $out = $pdf->output();

        self::assertStringContainsString('<rdf:li xml:lang="x-default">Ampersand &amp; Co</rdf:li>', $out);
        self::assertStringContainsString('<rdf:li>A Novelist</rdf:li>', $out);
        self::assertStringContainsString(
            '<pdf:Keywords>Powered by MejiroPDF [AGPLv3 Community Edition]; novel</pdf:Keywords>',
            $out,
        );
    }
}
