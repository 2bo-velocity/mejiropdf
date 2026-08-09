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
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Layout\Section;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\RunningHeadLevel;
use MejiroPDF\Page\RunningHeadOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An opened book shows two pages at once, and printing the same words on both
 * wastes one of them. Japanese books set the chapter on the verso and the
 * section on the recto.
 */
#[CoversClass(Document::class)]
#[CoversClass(RunningHeadOptions::class)]
#[CoversClass(RunningHeadLevel::class)]
final class DocumentRunningHeadTest extends TestCase
{
    private const string PARAGRAPH = '　本文が続く。組版の話をする。組版の話をする。組版の話をする。'
        . '組版の話をする。組版の話をする。組版の話をする。組版の話をする。組版の話をする。';

    /**
     * The running heads, in page order. They are set in one piece rather than a
     * glyph at a time, which is what tells them apart from flowed prose.
     *
     * @return list<string>
     */
    private static function heads(string $pdf): array
    {
        \preg_match_all("~8\.00 Tf\n[\d.]+ [\d.]+ Td\n<([0-9A-F]+)> Tj~", $pdf, $hits);

        return \array_map(
            static fn (string $hex): string => (string) \mb_convert_encoding(
                (string) \hex2bin($hex),
                'UTF-8',
                'UTF-16BE',
            ),
            $hits[1],
        );
    }

    /** @return array{0: string, 1: int} the PDF and its page count */
    private static function book(RunningHeadOptions $options, bool $subheading): array
    {
        $heading = $subheading ? '[第一節　準備]{.subhead}' : '[第一節　準備]{.bold}';
        $body = \implode("\n\n", [
            ...\array_fill(0, 3, self::PARAGRAPH),
            $heading,
            ...\array_fill(0, 8, self::PARAGRAPH),
        ]);

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setRunningHead($options);
        $pdf->section(
            new Section('第一章　組版とは', $body),
            FlowRegion::horizontal(x: 20, topY: 25, width: 160, bottomY: 120),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );

        return [$pdf->output(), $pdf->pageCount()];
    }

    #[Test]
    public function the_chapter_falls_on_the_verso_and_the_section_on_the_recto(): void
    {
        [$pdf, $pages] = self::book(new RunningHeadOptions(), true);

        // Page 1 opens the chapter and carries no head; 2 is the verso, 3 the recto.
        self::assertSame(3, $pages);
        self::assertSame(['第一章　組版とは', '第一節　準備'], self::heads($pdf));
    }

    #[Test]
    public function which_side_takes_which_is_the_caller_s_to_choose(): void
    {
        [$pdf] = self::book(
            new RunningHeadOptions(verso: RunningHeadLevel::SECTION, recto: RunningHeadLevel::CHAPTER),
            true,
        );

        self::assertSame(['第一節　準備', '第一章　組版とは'], self::heads($pdf));
    }

    #[Test]
    public function a_side_can_carry_nothing(): void
    {
        [$pdf] = self::book(new RunningHeadOptions(recto: RunningHeadLevel::NONE), true);

        self::assertSame(['第一章　組版とは'], self::heads($pdf));
    }

    #[Test]
    public function a_book_without_subheadings_sets_its_chapter_on_every_page(): void
    {
        // The recto asks for a section and there is none, so it takes the
        // chapter — which is what the engine did before there were two levels.
        [$pdf] = self::book(new RunningHeadOptions(), false);

        self::assertSame(['第一章　組版とは', '第一章　組版とは'], self::heads($pdf));
    }

    #[Test]
    public function the_head_is_set_in_the_face_the_words_were_written_in(): void
    {
        // The document's default face is Helvetica here, as it is in most
        // documents, and the book is set in a CJK face passed to section().
        // Drawn in the default the title would be a Latin literal string, and
        // the reader would get rubble.
        [$pdf] = self::book(new RunningHeadOptions(), true);

        self::assertSame(['第一章　組版とは', '第一節　準備'], self::heads($pdf));
        self::assertDoesNotMatchRegularExpression("~8\\.00 Tf\n[\d.]+ [\d.]+ Td\n\\(~", $pdf);
    }

    #[Test]
    public function the_caller_can_name_a_section_directly(): void
    {
        // For a subheading drawn some way other than a flow — and an empty
        // string takes the name away again.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setRunningHead(new RunningHeadOptions(font: CidFontJapanese::HEISEI_MIN_W3));
        $pdf->section(
            new Section('第一章', self::PARAGRAPH, startsOnNewPage: false),
            FlowRegion::horizontal(x: 20, topY: 25, width: 160, bottomY: 120),
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );
        $pdf->addPage()->runningSection('付録');
        $pdf->addPage();
        $pdf->addPage()->runningSection('');
        $pdf->addPage();

        // Pages 2 and 4 are versos and show the chapter; 3 and 5 are rectos and
        // show the section until it is cleared, when they fall back again.
        self::assertSame(['第一章', '付録', '第一章', '第一章'], self::heads($pdf->output()));
    }

    #[Test]
    public function a_section_does_not_outlive_its_chapter(): void
    {
        // Left standing, the last section of one chapter would name the rectos
        // of the next until that chapter happened to have a subheading.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setRunningHead(new RunningHeadOptions(font: CidFontJapanese::HEISEI_MIN_W3));
        $region = FlowRegion::horizontal(x: 20, topY: 25, width: 160, bottomY: 120);

        $pdf->section(
            new Section('第一章', '[一節]{.subhead}' . "\n\n" . self::PARAGRAPH, startsOnNewPage: false),
            $region,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );
        $pdf->section(
            new Section('第二章', \implode("\n\n", \array_fill(0, 3, self::PARAGRAPH))),
            $region,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );

        // The second chapter has no subheading of its own, so its recto falls
        // back to the chapter rather than borrowing the first chapter's section.
        self::assertNotContains('一節', \array_slice(self::heads($pdf->output()), 1));
    }

    #[Test]
    public function a_document_that_asks_for_no_head_gets_none(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 25, width: 160, bottomY: 120),
            '[第一節]{.subhead}' . "\n\n" . self::PARAGRAPH,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 11.0,
        );

        self::assertSame([], self::heads($pdf->output()));
    }
}
