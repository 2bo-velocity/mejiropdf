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
use MejiroPDF\Exception\ErrorCode;
use MejiroPDF\Exception\RenderException;
use MejiroPDF\Image\Image;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\WritingMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(RenderException::class)]
final class DocumentDebugFlagsTest extends TestCase
{
    /** A 1×1 red PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function guides(): Document
    {
        return new Document(credit: CreditOptions::disabled(), debugGuides: true);
    }

    private function strict(): Document
    {
        return new Document(credit: CreditOptions::disabled(), strictCanvas: true);
    }

    /** How many dashed guide frames the content stream carries. */
    private function frameCount(string $pdf): int
    {
        return (int) \preg_match_all('~\[1 1\] 0 d\n[-\d. ]+ re\nS~', $pdf);
    }

    #[Test]
    public function no_frames_are_drawn_by_default(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()
            ->typeset('Hello', 20, 20, 80)
            ->image(Image::fromString(\base64_decode(self::PNG)), 20, 60, 30, 30);

        self::assertSame(0, $this->frameCount($pdf->output()));
    }

    #[Test]
    public function a_typeset_block_is_framed_in_magenta(): void
    {
        $pdf = $this->guides();
        $pdf->addPage()->typeset('Hello world', 20, 20, 80);

        $out = $pdf->output();

        self::assertSame(1, $this->frameCount($out));
        self::assertStringContainsString('1 0 1 RG', $out);
    }

    #[Test]
    public function an_image_is_framed_in_cyan(): void
    {
        $pdf = $this->guides();
        $pdf->addPage()->image(Image::fromString(\base64_decode(self::PNG)), 20, 60, 30, 30);

        $out = $pdf->output();

        self::assertSame(1, $this->frameCount($out));
        self::assertStringContainsString('0 0.6 1 RG', $out);
        // 30 mm square at (20, 60) on a 297 mm page: 85.04 pt wide, bottom edge
        // at 841.89 − 255.12 = 586.77 pt.
        self::assertStringContainsString('56.69 586.77 85.04 85.04 re', $out);
    }

    #[Test]
    public function a_paginated_flow_is_framed_once_per_page(): void
    {
        $pdf = $this->guides();
        $pdf->addPage()->flowText(\str_repeat('The quick brown fox. ', 400), 20, 20, 170, 280);

        self::assertGreaterThan(1, $pdf->pageCount());
        self::assertSame($pdf->pageCount(), $this->frameCount($pdf->output()));
    }

    #[Test]
    public function a_rotated_text_object_carries_its_frame_inside_the_transform(): void
    {
        $pdf = $this->guides();
        $pdf->addPage()->textObject('Draft', 105, 148, 60, rotation: 45.0, anchor: Anchor::CENTER);

        // The frame is emitted between the cm and its matching Q, so it turns
        // with the object rather than boxing it in page space.
        self::assertMatchesRegularExpression('~ cm\n(?:(?!\nQ\n).)*\[1 1\] 0 d~s', $pdf->output());
    }

    #[Test]
    public function drawing_off_the_page_is_allowed_unless_strictness_is_asked_for(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->rect(-50, -50, 40, 40); // a bleed that a viewer clips

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function strict_mode_rejects_a_box_past_the_right_edge(): void
    {
        $pdf = $this->strict();
        $pdf->addPage();

        try {
            // 250 mm of text starting at 20 mm needs a 270 mm-wide page; A4 is 210.
            $pdf->typeset('Hello', 20, 20, 250);
            self::fail('An over-wide block must be rejected.');
        } catch (RenderException $e) {
            self::assertSame(ErrorCode::CANVAS_OVERFLOW, $e->getErrorCodeEnum());
            self::assertSame('Text block', $e->getContext()['element']);
        }
    }

    #[Test]
    public function strict_mode_rejects_a_negative_origin(): void
    {
        $pdf = $this->strict();
        $pdf->addPage();

        $this->expectException(RenderException::class);
        $pdf->rect(-5, 10, 40, 40);
    }

    #[Test]
    public function strict_mode_accepts_a_box_flush_with_the_page_edge(): void
    {
        // Millimetres round-trip through points, so a full-bleed rectangle
        // lands a few nanometres past the edge. That must not be an error.
        $pdf = $this->strict();
        $pdf->addPage()->rect(0, 0, $pdf->pageWidthMm(), $pdf->pageHeightMm());

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function strict_mode_measures_a_vertical_block_leftward_from_its_origin(): void
    {
        // Vertical text fills leftward from (x, y), so a block anchored 30 mm
        // from the left runs off the page long before its width does.
        $pdf = $this->strict();
        $pdf->addPage();

        $this->expectException(RenderException::class);
        $pdf->typeset(
            \str_repeat('縦書きの文章です。', 40),
            30,
            20,
            200,
            options: new TypesetterOptions(writingMode: WritingMode::VERTICAL_RL),
        );
    }
}
