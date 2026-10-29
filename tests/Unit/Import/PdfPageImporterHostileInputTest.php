<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Import;

use MejiroPDF\Exception\ErrorCode;
use MejiroPDF\Import\PdfImportException;
use MejiroPDF\Import\PdfPageImporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Files built to exhaust the importer rather than to be imported. Each one is
 * a few kilobytes at most, and each once cost a worker its memory, its stack
 * or its time limit; now each is refused with MP-VAL-1003/1004.
 *
 * The time and memory assertions are deliberately loose — they exist to fail
 * loudly if a guard is removed, not to benchmark.
 */
#[CoversClass(PdfPageImporter::class)]
final class PdfPageImporterHostileInputTest extends TestCase
{
    /**
     * A classic-xref PDF holding the given objects, numbered by their keys.
     *
     * @param array<int, string> $objects
     */
    private static function pdf(array $objects): string
    {
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = \strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = \strlen($out);
        $size = \max(\array_keys($objects)) + 1;
        $out .= "xref\n0 {$size}\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= \sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        return $out . "trailer\n<< /Size {$size} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    /**
     * A PDF 1.5 file indexed only by a cross-reference stream, whose rows are
     * given raw (W = [1 4 2]).
     */
    private static function xrefStreamPdf(string $before, string $rows, int $size, string $extra = ''): string
    {
        $out = "%PDF-1.5\n" . $before;
        $xref = \strlen($out);
        $out .= \sprintf(
            "%d 0 obj\n<< /Type /XRef /Size %d /W [1 4 2] /Root 1 0 R%s /Length %d >>\nstream\n%s\nendstream\nendobj\n",
            $size,
            $size + 1,
            $extra,
            \strlen($rows),
            $rows,
        );

        return $out . "startxref\n{$xref}\n%%EOF";
    }

    private static function row(int $type, int $field2, int $field3): string
    {
        return \pack('CNn', $type, $field2, $field3);
    }

    private function expectRefusal(ErrorCode $code, callable $import): void
    {
        $start = \microtime(true);
        $memory = \memory_get_usage();
        try {
            $import();
            self::fail('A hostile source must be refused.');
        } catch (PdfImportException $e) {
            self::assertSame($code->value, $e->getErrorCode());
        }
        self::assertLessThan(5.0, \microtime(true) - $start, 'Refusing a hostile source must be quick.');
        self::assertLessThan(128 * 1024 * 1024, \memory_get_usage() - $memory);
    }

    #[Test]
    public function a_page_tree_that_reaches_a_node_twice_is_refused(): void
    {
        // Every level lists the next one twice: 40 levels, 2^40 page visits.
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        for ($n = 2; $n < 42; $n++) {
            $objects[$n] = \sprintf('<< /Type /Pages /Kids [%1$d 0 R %1$d 0 R] /Count 2 >>', $n + 1);
        }
        $objects[42] = '<< /Type /Page /MediaBox [0 0 10 10] >>';
        $pdf = self::pdf($objects);

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::pageBoxes($pdf));
        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function a_content_stream_that_inflates_past_the_ceiling_is_refused(): void
    {
        // About 70 KB on disk.
        $bomb = \gzcompress(\str_repeat("\0", PdfPageImporter::MAX_INFLATED_BYTES + 1), 9);
        self::assertIsString($bomb);
        $pdf = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /MediaBox [0 0 10 10] /Contents 4 0 R >>',
            4 => \sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", \strlen($bomb), $bomb),
        ]);
        unset($bomb);

        $this->expectRefusal(ErrorCode::PDF_IMPORT_UNSUPPORTED, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function content_just_under_the_ceiling_still_imports(): void
    {
        $ops = "0 0 10 10 re f\n";
        $packed = \gzcompress($ops);
        self::assertIsString($packed);
        $pdf = self::pdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /MediaBox [0 0 10 10] /Contents 4 0 R >>',
            4 => \sprintf("<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream", \strlen($packed), $packed),
        ]);

        self::assertStringContainsString($ops, PdfPageImporter::import($pdf)->content);
    }

    #[Test]
    public function a_classic_xref_claiming_more_entries_than_the_file_holds_is_refused(): void
    {
        $pdf = "%PDF-1.4\nxref\n0 1099511627776\ntrailer\n<< /Root 1 0 R >>\nstartxref\n9\n%%EOF";

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function an_object_stream_stored_inside_itself_is_refused(): void
    {
        // Every object, object 5 included, claims to live in object stream 5.
        $pdf = self::xrefStreamPdf('', \str_repeat(self::row(2, 5, 0), 6), 6);

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function an_object_stream_claiming_more_objects_than_it_indexes_is_refused(): void
    {
        $stream = "5 0 obj\n<< /Type /ObjStm /N 100000000 /First 4 /Length 5 >>\nstream\n1 0 x\nendstream\nendobj\n";
        $rows = self::row(0, 0, 0)            // 0: free
            . self::row(2, 5, 0)              // 1: the catalog, in stream 5
            . \str_repeat(self::row(0, 0, 0), 3)
            . self::row(1, 9, 0);             // 5: the object stream, after "%PDF-1.5\n"
        $pdf = self::xrefStreamPdf($stream, $rows, 6);

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function a_predictor_wider_than_its_data_is_refused(): void
    {
        $pdf = self::xrefStreamPdf('', self::row(1, 9, 0), 1, ' /DecodeParms << /Predictor 12 /Columns 1099511627776 >>');

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }

    #[Test]
    public function an_offset_past_the_end_of_the_file_is_an_import_error(): void
    {
        // Used to escape as a bare \ValueError from strpos().
        $pdf = "%PDF-1.4\nxref\n0 2\n0000000000 65535 f \n9999999999 00000 n \ntrailer\n<< /Root 1 0 R >>\nstartxref\n9\n%%EOF";

        $this->expectRefusal(ErrorCode::PDF_IMPORT_INVALID, static fn () => PdfPageImporter::import($pdf));
    }
}
