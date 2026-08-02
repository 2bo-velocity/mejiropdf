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
use MejiroPDF\Exception\RenderException;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentRotatedGroupTest extends TestCase
{
    private function document(): Document
    {
        return new Document(credit: CreditOptions::disabled());
    }

    #[Test]
    public function it_wraps_the_group_in_one_balanced_transform(): void
    {
        $pdf = $this->document();
        $pdf->addPage()->rotated(90.0, 20, 250, function (Document $pdf): void {
            $pdf->rect(0, 0, 50, 20, fill: '#eeeeee');
            $pdf->text(2, 12, 'Turned', font: StandardFont::HELVETICA);
        });

        $out = $pdf->output();

        // A quarter turn about (20 mm, 250 mm) from the top-left = (56.69,
        // 133.23) pt in the page's y-up space, so e = cx + cy and f = cy - cx.
        self::assertStringContainsString('0.00000 1.00000 -1.00000 0.00000 189.92 76.54 cm', $out);
        // Both the rectangle and the text sit inside that one transform.
        self::assertMatchesRegularExpression('~cm\n.*re\n.*\(Turned\) Tj.*\nQ\n~s', $out);
        self::assertSame(\substr_count($out, "q\n"), \substr_count($out, "Q\n"));
    }

    #[Test]
    public function members_of_a_group_keep_their_positions_relative_to_each_other(): void
    {
        // Turning the assembly as a whole is not the same as turning each part
        // about its own centre: the operators for the parts must be untouched,
        // with only the wrapper carrying the angle.
        $upright = $this->document();
        $upright->addPage()->rect(0, 0, 50, 20, fill: '#eeeeee')->rect(0, 30, 50, 20, fill: '#eeeeee');

        $turned = $this->document();
        $turned->addPage()->rotated(30.0, 100, 100, function (Document $pdf): void {
            $pdf->rect(0, 0, 50, 20, fill: '#eeeeee');
            $pdf->rect(0, 30, 50, 20, fill: '#eeeeee');
        });

        // Both rectangles appear with identical operators in both documents.
        self::assertStringContainsString('0.00 785.20 141.73 56.69 re', $upright->output());
        self::assertStringContainsString('0.00 785.20 141.73 56.69 re', $turned->output());
        self::assertStringContainsString('0.00 700.16 141.73 56.69 re', $turned->output());
    }

    #[Test]
    public function groups_nest_and_the_angles_add_up(): void
    {
        $pdf = $this->document();
        $pdf->addPage()->rotated(90.0, 100, 100, function (Document $pdf): void {
            $pdf->rotated(90.0, 100, 100, function (Document $pdf): void {
                $pdf->rect(10, 10, 20, 20);
            });
        });

        $out = $pdf->output();

        // The same matrix twice, one inside the other, around the one rectangle:
        // the transforms compose rather than the second replacing the first.
        self::assertSame(2, \substr_count($out, '0.00000 1.00000 -1.00000 0.00000 841.89 274.96 cm'));
        self::assertMatchesRegularExpression('~cm\n.*cm\n.*re\n.*Q\n.*Q\n~s', $out);
        self::assertSame(\substr_count($out, "q\n"), \substr_count($out, "Q\n"));
    }

    #[Test]
    public function a_zero_angle_still_scopes_the_group(): void
    {
        $pdf = $this->document();
        $pdf->addPage()->rotated(0.0, 10, 10, static function (Document $pdf): void {
            $pdf->rect(0, 0, 10, 10);
        });

        // An identity matrix — the group is a scope whether or not it turns.
        self::assertStringContainsString('1.00000 0.00000 0.00000 1.00000 ', $pdf->output());
    }

    #[Test]
    public function the_transform_is_closed_even_when_the_callback_throws(): void
    {
        $pdf = $this->document();
        $pdf->addPage();

        try {
            $pdf->rotated(45.0, 100, 100, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('The callback exception should have propagated.');
        } catch (\RuntimeException) {
            // Expected: the caller sees their own exception, not a broken stream.
        }

        // Anything drawn afterwards must be upright, which means the group's Q
        // was emitted before the exception left.
        $pdf->text(20, 20, 'After', font: StandardFont::HELVETICA);
        $out = $pdf->output();

        self::assertSame(\substr_count($out, "q\n"), \substr_count($out, "Q\n"));
        // The group closed before the text opened: no matrix is left standing
        // between them.
        self::assertMatchesRegularExpression('~cm\nQ\n.*\(After\) Tj~s', $out);
    }

    #[Test]
    public function opening_a_page_inside_a_group_is_refused(): void
    {
        $pdf = $this->document();
        $pdf->addPage();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('rotated()');

        $pdf->rotated(45.0, 100, 100, static function (Document $pdf): void {
            $pdf->addPage();
        });
    }

    #[Test]
    public function a_strict_canvas_checks_the_pivot_but_not_the_contents(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), strictCanvas: true);
        $pdf->addPage()->rotated(45.0, 105, 148, static function (Document $pdf): void {
            // Far off the sheet in page coordinates, but the group turns it back
            // over the page — which is why the check has to stand down.
            $pdf->rect(0, -200, 50, 20);
        });

        self::assertStringContainsString('re', $pdf->output());
    }

    #[Test]
    public function a_strict_canvas_still_rejects_a_pivot_off_the_page(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), strictCanvas: true);
        $pdf->addPage();

        $this->expectException(RenderException::class);

        $pdf->rotated(45.0, 400, 148, static function (): void {
        });
    }

    #[Test]
    public function the_check_comes_back_once_the_group_closes(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled(), strictCanvas: true);
        $pdf->addPage()->rotated(45.0, 105, 148, static function (Document $pdf): void {
            $pdf->rect(0, -200, 50, 20);
        });

        $this->expectException(RenderException::class);

        $pdf->rect(0, -200, 50, 20);
    }

    #[Test]
    public function a_link_inside_a_group_is_reported_as_not_turning(): void
    {
        $logger = new RecordingLogger();
        $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
        $pdf->addPage()->rotated(45.0, 100, 100, static function (Document $pdf): void {
            $pdf->link(10, 10, 40, 8, 'https://mejiropdf.com');
        });
        $pdf->output();

        $record = $logger->firstWhere('axis-aligned');
        self::assertNotNull($record);
        self::assertSame('warning', $record['level']);
    }

    #[Test]
    public function an_upright_link_is_not_reported(): void
    {
        $logger = new RecordingLogger();
        $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
        $pdf->addPage()->link(10, 10, 40, 8, 'https://mejiropdf.com');
        $pdf->output();

        self::assertNull($logger->firstWhere('axis-aligned'));
    }

    #[Test]
    public function a_page_can_be_marked_for_display_rotation(): void
    {
        $pdf = $this->document();
        $pdf->addPage(PageSize::a4(), rotation: 90);

        self::assertStringContainsString('/MediaBox [0 0 595.28 841.89] /Rotate 90 /Contents', $pdf->output());
    }

    #[Test]
    public function a_negative_display_rotation_normalises(): void
    {
        $pdf = $this->document();
        $pdf->addPage(rotation: -90);

        self::assertStringContainsString('/Rotate 270 /Contents', $pdf->output());
    }

    #[Test]
    public function an_upright_page_carries_no_rotate_entry(): void
    {
        $pdf = $this->document();
        $pdf->addPage();

        self::assertStringNotContainsString('/Rotate', $pdf->output());
    }

    #[Test]
    public function the_display_rotation_belongs_to_its_own_page(): void
    {
        $pdf = $this->document();
        $pdf->addPage(rotation: 90);
        $pdf->addPage();

        // One page asked to be turned; the next did not inherit it.
        self::assertSame(1, \substr_count($pdf->output(), '/Rotate'));
    }

    #[Test]
    public function a_display_rotation_that_is_not_a_quarter_turn_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->document()->addPage(rotation: 45);
    }

    #[Test]
    public function a_display_rotation_does_not_move_the_drawing_origin(): void
    {
        // /Rotate turns the paper, not the coordinate space: the same call must
        // produce the same operators on a turned page as on an upright one.
        $upright = $this->document();
        $upright->addPage()->rect(10, 10, 50, 20);

        $turned = $this->document();
        $turned->addPage(rotation: 90)->rect(10, 10, 50, 20);

        self::assertStringContainsString('28.35 756.85 141.73 56.69 re', $upright->output());
        self::assertStringContainsString('28.35 756.85 141.73 56.69 re', $turned->output());
    }
}
