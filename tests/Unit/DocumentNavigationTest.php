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
use MejiroPDF\Page\CreditOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentNavigationTest extends TestCase
{
    private function doc(): Document
    {
        return new Document(credit: CreditOptions::disabled());
    }

    #[Test]
    public function it_adds_a_uri_link_annotation(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->link(10, 20, 50, 10, 'https://mejiropdf.com');

        $out = $pdf->output();

        self::assertStringContainsString('/Subtype /Link', $out);
        self::assertStringContainsString('/S /URI', $out);
        self::assertStringContainsString('(https://mejiropdf.com)', $out);
        self::assertMatchesRegularExpression('~/Annots \[ \d+ 0 R~', $out);
    }

    #[Test]
    public function it_adds_an_internal_goto_link(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->linkToPage(10, 20, 50, 10, 3);
        $pdf->addPage();
        $pdf->addPage();

        $out = $pdf->output();

        self::assertStringContainsString('/S /GoTo', $out);
        self::assertMatchesRegularExpression('~/D \[ \d+ 0 R /Fit \]~', $out);
    }

    #[Test]
    public function it_builds_a_nested_outline(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->bookmark('Chapter 1');
        $pdf->addPage()->bookmark('Section 1.1', 1);
        $pdf->addPage()->bookmark('Chapter 2');

        $out = $pdf->output();

        self::assertStringContainsString('/Type /Outlines', $out);
        self::assertStringContainsString('/Title (Chapter 1)', $out);
        self::assertStringContainsString('/Title (Section 1.1)', $out);
        self::assertStringContainsString('/Outlines ', $out); // referenced by the catalog
        // Chapter 1 has one child (Section 1.1).
        self::assertMatchesRegularExpression('~/First \d+ 0 R /Last \d+ 0 R /Count 1~', $out);
    }

    #[Test]
    public function outline_titles_use_utf16_for_non_ascii(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->bookmark('第1章 概要');

        self::assertStringContainsString('/Title <FEFF', $pdf->output());
    }

    #[Test]
    public function it_renders_a_two_pass_table_of_contents(): void
    {
        $pdf = $this->doc();
        $pdf->tableOfContents('Contents');
        $pdf->addPage()->bookmark('Introduction');
        $pdf->addPage()->bookmark('Results');

        $out = $pdf->output();

        self::assertSame(3, $pdf->pageCount()); // TOC + 2 content pages
        self::assertStringContainsString('(Contents) Tj', $out);      // heading
        self::assertStringContainsString('(Introduction) Tj', $out);  // entry title
        self::assertStringContainsString('(Results) Tj', $out);
        // Resolved page numbers: Introduction is physical page 2, Results page 3.
        self::assertStringContainsString('(2) Tj', $out);
        self::assertStringContainsString('(3) Tj', $out);
    }

    #[Test]
    public function it_draws_dotted_leaders_and_clickable_toc_entries(): void
    {
        $pdf = $this->doc();
        $pdf->tableOfContents('Contents');
        $pdf->addPage()->bookmark('Introduction');

        $out = $pdf->output();

        self::assertStringContainsString('[0.4 2.4] 0 d', $out); // dotted leader dash pattern
        self::assertStringContainsString('/S /GoTo', $out);      // the entry is a clickable link
    }

    #[Test]
    public function a_long_table_of_contents_spans_multiple_pages(): void
    {
        $pdf = $this->doc();
        $pdf->tableOfContents('Contents');
        // Far more entries than fit on one page (~34 rows at 7mm on A4).
        for ($i = 1; $i <= 80; $i++) {
            $pdf->addPage()->bookmark("Chapter {$i}");
        }

        $out = $pdf->output();

        // 80 content pages + a multi-page (>= 2) TOC.
        $tocPages = $pdf->pageCount() - 80;
        self::assertGreaterThanOrEqual(2, $tocPages);

        // Page numbers must account for the multi-page TOC: with N TOC pages,
        // "Chapter 1" sits on physical page N+1.
        self::assertStringContainsString(\sprintf('(%d) Tj', $tocPages + 1), $out); // Chapter 1
        self::assertStringContainsString(\sprintf('(%d) Tj', $tocPages + 80), $out); // Chapter 80
    }

    #[Test]
    public function no_outline_is_emitted_without_bookmarks(): void
    {
        $pdf = $this->doc();
        $pdf->addPage()->text(10, 10, 'plain');

        self::assertStringNotContainsString('/Type /Outlines', $pdf->output());
    }
}
