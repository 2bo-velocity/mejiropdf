<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Writer;

use MejiroPDF\Stream\SafeStreamWriter;
use MejiroPDF\Writer\DocumentWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentWriter::class)]
final class DocumentWriterTest extends TestCase
{
    /** @var resource */
    private $stream;

    private SafeStreamWriter $writer;
    private DocumentWriter $doc;

    protected function setUp(): void
    {
        $stream = \fopen('php://temp', 'r+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
        $this->writer = new SafeStreamWriter($stream);
        $this->doc = new DocumentWriter($this->writer);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }

    private function contents(): string
    {
        \rewind($this->stream);

        return (string) \stream_get_contents($this->stream);
    }

    #[Test]
    public function it_writes_a_header_with_binary_marker(): void
    {
        $this->doc->writeHeader();
        $output = $this->contents();

        self::assertStringStartsWith("%PDF-1.7\n", $output);
        self::assertStringContainsString("%\xE2\xE3\xCF\xD3\n", $output);
    }

    #[Test]
    public function it_records_offsets_and_writes_object_delimiters(): void
    {
        $this->doc->writeHeader();

        $id = $this->doc->allocateObjectId();
        $this->doc->writeObject($id, '<< /Type /Catalog >>');

        self::assertStringContainsString("1 0 obj\n<< /Type /Catalog >>\nendobj\n", $this->contents());
    }

    #[Test]
    public function it_emits_a_stream_object_with_correct_length(): void
    {
        $data = "BT /F1 12 Tf (Hi) Tj ET";
        $id = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($id, $data);

        $length = \strlen($data);
        self::assertStringContainsString("<< /Length {$length} >>\nstream\n{$data}\nendstream", $this->contents());
    }

    #[Test]
    public function it_generates_a_valid_xref_and_trailer_on_finalize(): void
    {
        $this->doc->writeHeader();

        $cat = $this->doc->allocateObjectId();
        $pages = $this->doc->allocateObjectId();
        $this->doc->writeObject($cat, "<< /Type /Catalog /Pages {$pages} 0 R >>");
        $this->doc->writeObject($pages, '<< /Type /Pages /Kids [] /Count 0 >>');
        $this->doc->finalize($cat);

        $output = $this->contents();

        self::assertStringContainsString("xref\n0 3\n", $output);
        self::assertStringContainsString("0000000000 65535 f \n", $output);
        self::assertMatchesRegularExpression('/\d{10} 00000 n /', $output);
        self::assertStringContainsString('trailer', $output);
        self::assertStringContainsString('<< /Size 3 /Root 1 0 R >>', $output);
        self::assertStringContainsString('startxref', $output);
        self::assertStringContainsString('%%EOF', $output);
    }

    #[Test]
    public function xref_entries_are_exactly_twenty_bytes(): void
    {
        $this->doc->writeHeader();
        $id = $this->doc->allocateObjectId();
        $this->doc->writeObject($id, '<< /Type /Catalog >>');
        $this->doc->finalize($id);

        $output = $this->contents();
        $xrefPos = \strpos($output, "xref\n");
        self::assertNotFalse($xrefPos);

        // "xref\n" then "0 2\n" then two 20-byte entries.
        $entriesStart = $xrefPos + \strlen("xref\n0 2\n");
        $freeEntry = \substr($output, $entriesStart, 20);
        self::assertSame("0000000000 65535 f \n", $freeEntry);
        self::assertSame(20, \strlen($freeEntry));
    }
}
