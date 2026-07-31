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
use MejiroPDF\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentInfoTest extends TestCase
{
    #[Test]
    public function it_records_the_library_version_in_the_producer(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(10, 10, 'hi');
        $bytes = $pdf->output();

        self::assertStringContainsString('/Producer (MejiroPDF ' . Version::VERSION . ')', $bytes);
        self::assertStringContainsString('/Creator (MejiroPDF)', $bytes);
        self::assertMatchesRegularExpression("/\\/CreationDate \\(D:\\d{14}\\+00'00'\\)/", $bytes);
    }

    #[Test]
    public function the_trailer_references_the_info_dictionary(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->text(10, 10, 'hi');
        $bytes = $pdf->output();

        self::assertMatchesRegularExpression('/\\/Root \\d+ 0 R \\/Info \\d+ 0 R/', $bytes);
    }
}
