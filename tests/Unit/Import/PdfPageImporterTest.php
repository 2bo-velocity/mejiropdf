<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Import;

use MejiroPDF\Import\PdfImportException;
use MejiroPDF\Import\PdfPageImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdfPageImporter::class)]
final class PdfPageImporterTest extends TestCase
{
    private function fixture(string $file): string
    {
        return (string) \file_get_contents(__DIR__ . '/../../fixtures/pdf/' . $file);
    }

    #[Test]
    public function it_extracts_the_media_box_and_content(): void
    {
        $page = PdfPageImporter::import($this->fixture('vector.pdf'));

        self::assertSame('0 0 595 842', $page->bbox);
        self::assertStringContainsString('re f', $page->content); // a filled rectangle op
        self::assertSame([], $page->objects);                     // vector-only: no resources
    }

    #[Test]
    public function it_decompresses_flate_content(): void
    {
        // The fixture's content stream is FlateDecode; the importer returns raw ops.
        $page = PdfPageImporter::import($this->fixture('vector.pdf'));

        self::assertStringNotContainsString('FlateDecode', $page->content);
        self::assertGreaterThan(0, \strlen($page->content));
    }

    #[Test]
    public function it_collects_referenced_resource_objects(): void
    {
        $page = PdfPageImporter::import($this->fixture('with_font.pdf'));

        self::assertStringContainsString('/Font', $page->resources);
        self::assertCount(1, $page->objects); // the referenced font object
        $objects = $page->objects;
        $font = \reset($objects);
        self::assertNotFalse($font);
        self::assertStringContainsString('/BaseFont /Helvetica-Bold', $font['dict']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function crossReferenceStreamFixtures(): iterable
    {
        yield 'xref stream' => ['xref_stream.pdf'];
        yield 'xref stream + PNG predictor' => ['xref_predictor.pdf'];
        yield 'xref stream + object stream' => ['xref_objstm.pdf'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('crossReferenceStreamFixtures')]
    public function it_reads_pdf_1_5_cross_reference_streams(string $file): void
    {
        $page = PdfPageImporter::import($this->fixture($file));

        self::assertSame('0 0 595 842', $page->bbox);
        self::assertStringContainsString('re f', $page->content);
    }

    #[Test]
    public function it_reads_a_hybrid_reference_file(): void
    {
        // The page's /Resources (object 5) lives only in an object stream that is
        // indexed exclusively by the classic trailer's /XRefStm. Resolving it
        // proves the hybrid cross-reference was followed.
        $page = PdfPageImporter::import($this->fixture('hybrid.pdf'));

        self::assertSame('0 0 200 200', $page->bbox);
        self::assertStringContainsString('re f', $page->content);
        self::assertStringContainsString('/ProcSet', $page->resources);
    }

    #[Test]
    public function it_inherits_resources_from_an_ancestor_pages_node(): void
    {
        // The page dict has no /Resources; it must inherit the one on /Pages.
        $page = PdfPageImporter::import($this->fixture('inherited_resources.pdf'));

        self::assertSame('0 0 200 200', $page->bbox);
        self::assertStringContainsString('/ProcSet', $page->resources);
    }

    #[Test]
    public function a_source_without_a_rotate_is_upright(): void
    {
        self::assertSame(0, PdfPageImporter::import($this->fixture('vector.pdf'))->rotate);
    }

    /**
     * The fixture's /Pages node carries /Rotate 270 and a portrait MediaBox, so
     * every page is the same size and differs only in how it is turned.
     *
     * @return iterable<string, array{int, int}> [page number, expected rotation]
     */
    public static function rotations(): iterable
    {
        yield 'inherited from the /Pages node' => [1, 270];
        yield 'the page overrides its ancestor' => [2, 90];
        yield 'a negative value normalises' => [3, 270];
        yield 'a value that is not a quarter turn is ignored' => [4, 0];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('rotations')]
    public function it_reads_the_display_rotation(int $pageNumber, int $expected): void
    {
        $page = PdfPageImporter::import($this->fixture('rotated.pdf'), $pageNumber);

        self::assertSame($expected, $page->rotate);
        // The rotation is reported alongside the MediaBox, never folded into it.
        self::assertSame('0 0 595 842', $page->bbox);
    }

    #[Test]
    public function it_rejects_a_missing_page(): void
    {
        $this->expectException(PdfImportException::class);

        PdfPageImporter::import($this->fixture('vector.pdf'), 99);
    }
}
