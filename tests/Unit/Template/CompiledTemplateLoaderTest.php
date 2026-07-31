<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Template;

use MejiroPDF\Exception\TemplateException;
use MejiroPDF\Template\CompiledTemplateLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompiledTemplateLoader::class)]
final class CompiledTemplateLoaderTest extends TestCase
{
    #[Test]
    public function it_rejects_a_too_short_header(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('16-byte MPBF header');

        CompiledTemplateLoader::loadString('MPBF');
    }

    #[Test]
    public function it_rejects_a_bad_magic(): void
    {
        $bytes = \pack('a4nnNN', 'XXXX', 1, 0, 0, 0);

        try {
            CompiledTemplateLoader::loadString($bytes);
            self::fail('Expected TemplateException.');
        } catch (TemplateException $e) {
            self::assertSame('MP-VAL-1001', $e->getErrorCode());
            self::assertStringContainsString('Invalid magic', $e->getMessage());
        }
    }

    #[Test]
    public function it_rejects_an_unsupported_version(): void
    {
        $bytes = \pack('a4nnNN', 'MPBF', 99, 0, 0, 0);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Unsupported MPBF version 99');

        CompiledTemplateLoader::loadString($bytes);
    }

    #[Test]
    public function it_rejects_a_truncated_body(): void
    {
        // Header claims 100 static bytes, but none follow.
        $bytes = \pack('a4nnNN', 'MPBF', 1, 0, 100, 0);

        try {
            CompiledTemplateLoader::loadString($bytes);
            self::fail('Expected TemplateException.');
        } catch (TemplateException $e) {
            self::assertSame('MP-VAL-1002', $e->getErrorCode());
            self::assertStringContainsString('Truncated', $e->getMessage());
        }
    }

    #[Test]
    public function it_rejects_a_missing_file(): void
    {
        $this->expectException(TemplateException::class);

        CompiledTemplateLoader::loadFile('/no/such/template.mpb');
    }
}
