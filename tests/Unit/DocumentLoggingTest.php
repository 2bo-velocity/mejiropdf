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
use MejiroPDF\Exception\DocumentException;
use MejiroPDF\Exception\ErrorCode;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateImage;
use MejiroPDF\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The engine takes any PSR-3 logger and records nothing without one.
 *
 * The volume matters as much as the content: logging must stay proportional to
 * *problems*, not to output size, or it would undo the constant-memory,
 * constant-work streaming the engine exists for.
 */
#[CoversClass(Document::class)]
#[CoversClass(DocumentException::class)]
final class DocumentLoggingTest extends TestCase
{
    #[Test]
    public function it_records_nothing_by_default(): void
    {
        // Constructing without a logger must not require psr/log wiring from
        // the caller, nor blow up when the engine logs internally.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(10, 10, 'Hello');

        self::assertStringStartsWith('%PDF-', $pdf->output());
        self::assertInstanceOf(NullLogger::class, $pdf->logger());
    }

    #[Test]
    public function it_reports_its_version_on_construction_and_its_size_at_the_end(): void
    {
        $logger = new RecordingLogger();
        $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
        $pdf->addPage()->text(10, 10, 'Hello');
        $bytes = $pdf->output();

        $start = $logger->firstWhere('MejiroPDF');
        self::assertNotNull($start);
        self::assertSame('debug', $start['level']);
        self::assertSame(\MejiroPDF\Version::VERSION, $start['context']['version']);
        self::assertFalse($start['context']['encrypted']);

        $end = $logger->firstWhere('Finalized');
        self::assertNotNull($end);
        self::assertSame('info', $end['level']);
        self::assertSame(1, $end['context']['pages']);
        self::assertSame(\strlen($bytes), $end['context']['bytes']);
    }

    #[Test]
    public function the_log_volume_does_not_grow_with_the_page_count(): void
    {
        $write = static function (int $pages): int {
            $logger = new RecordingLogger();
            $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
            for ($i = 0; $i < $pages; $i++) {
                $pdf->addPage()->text(10, 10, "Page {$i}");
            }
            $pdf->output();

            return \count($logger->records);
        };

        // A correct document logs a fixed number of lines however long it is.
        self::assertSame($write(1), $write(200));
    }

    #[Test]
    public function a_degraded_template_image_is_reported_once_per_occurrence(): void
    {
        $logger = new RecordingLogger();
        $a4 = PageSize::a4();
        $bytes = MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            images: [new TemplateImage(data: '', key: 'logo', x: 10, y: 10, width: 20, height: 20)],
        );
        $template = CompiledTemplateLoader::loadString($bytes);

        $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
        $pdf->renderTemplate($template, []); // no `logo` in the data
        $pdf->output();

        $warning = $logger->firstWhere('Skipped a template image');
        self::assertNotNull($warning);
        self::assertSame('warning', $warning['level']);
        self::assertSame('logo', $warning['context']['key']);
    }

    #[Test]
    public function a_page_limit_stops_the_document_at_the_page_that_would_cross_it(): void
    {
        $pdf = (new Document(credit: CreditOptions::disabled()))->setMaxPageLimit(3);
        $pdf->addPage()->addPage()->addPage();

        self::assertSame(3, $pdf->pageCount());

        try {
            $pdf->addPage();
            self::fail('the fourth page should have been refused');
        } catch (DocumentException $e) {
            self::assertSame(ErrorCode::PAGE_OVERFLOW->value, $e->getErrorCode());
            self::assertSame(3, $e->getContext()['limit']);
            self::assertSame(4, $e->getContext()['page']);
        }
    }

    #[Test]
    public function no_limit_is_set_by_default_and_zero_clears_one(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->addPage();
        self::assertSame(2, $pdf->pageCount());

        $pdf->setMaxPageLimit(2);
        $pdf->setMaxPageLimit(0);
        $pdf->addPage();

        self::assertSame(3, $pdf->pageCount());
    }

    #[Test]
    public function a_negative_limit_is_rejected_rather_than_silently_disabling_the_guard(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Document(credit: CreditOptions::disabled()))->setMaxPageLimit(-1);
    }
}
