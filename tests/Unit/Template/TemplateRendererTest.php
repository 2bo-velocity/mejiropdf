<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Template;

use MejiroPDF\Document;
use MejiroPDF\Exception\ErrorCode;
use MejiroPDF\Exception\TemplateException;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateRenderer;
use MejiroPDF\Template\TemplateText;
use MejiroPDF\Text\JapaneseTextEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateRenderer::class)]
#[CoversClass(Document::class)]
final class TemplateRendererTest extends TestCase
{
    private function templateBytes(): string
    {
        $a4 = PageSize::a4();

        return MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: "0.4 w 56.69 700 481.89 100 re S\n",
            texts: [new TemplateText('請求書', 20, 25, 'HeiseiKakuGo-W5', 24.0, Alignment::LEFT)],
            bindings: [
                new DynamicBinding('customer_name', 20, 35, 'HeiseiMin-W3', 13.0, Alignment::LEFT),
                new DynamicBinding('amount', 180, 60, 'Helvetica', 12.0, Alignment::RIGHT),
            ],
        );
    }

    #[Test]
    public function it_renders_static_labels_and_bound_values(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->templateBytes());

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, [
            'customer_name' => '株式会社メジロ',
            'amount' => '1,300,000',
        ]);

        $bytes = $pdf->output();

        self::assertSame(1, $pdf->pageCount());
        self::assertStringContainsString('[0 0 595.28 841.89]', $bytes);   // A4 MediaBox
        self::assertStringContainsString('56.69 700 481.89 100 re S', $bytes); // static vector
        self::assertStringContainsString(JapaneseTextEncoder::toCidHex('請求書') . ' Tj', $bytes);
        self::assertStringContainsString(JapaneseTextEncoder::toCidHex('株式会社メジロ') . ' Tj', $bytes);
        self::assertStringContainsString('(1,300,000) Tj', $bytes);
    }

    #[Test]
    public function it_skips_bindings_absent_from_the_data(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->templateBytes());

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['amount' => '999']); // no customer_name

        $bytes = $pdf->output();

        self::assertStringContainsString('(999) Tj', $bytes);
        // The unbound customer field draws nothing.
        self::assertStringNotContainsString(JapaneseTextEncoder::toCidHex('株式会社メジロ'), $bytes);
    }

    #[Test]
    public function it_emits_japanese_fonts_used_by_the_template(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->templateBytes());

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['customer_name' => 'テスト']);

        $bytes = $pdf->output();

        self::assertStringContainsString('/BaseFont /HeiseiKakuGo-W5', $bytes);
        self::assertStringContainsString('/BaseFont /HeiseiMin-W3', $bytes);
        self::assertStringContainsString('/Subtype /Type0', $bytes);
    }

    /**
     * A template whose invoice number is declared to hold 8 characters.
     */
    private function boundedTemplate(): string
    {
        $a4 = PageSize::a4();

        return MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            bindings: [new DynamicBinding('invoice_no', 20, 35, 'Helvetica', 12.0, maxLength: 8)],
        );
    }

    #[Test]
    public function a_declared_max_length_survives_the_mpb_round_trip(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->boundedTemplate());

        self::assertSame(8, $tpl->bindings['invoice_no']->maxLength);
    }

    #[Test]
    public function an_oversized_value_is_rejected_before_the_page_is_opened(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->boundedTemplate());
        $pdf = new Document(credit: CreditOptions::disabled());

        try {
            $pdf->renderTemplate($tpl, ['invoice_no' => 'INV-2026-0001']);
            self::fail('An over-long value must not be rendered.');
        } catch (TemplateException $e) {
            self::assertSame(ErrorCode::MAX_LENGTH_EXCEEDED, $e->getErrorCodeEnum());
            self::assertSame(
                ['field' => 'invoice_no', 'limit' => 8, 'length' => 13],
                $e->getContext(),
            );
        }

        self::assertSame(0, $pdf->pageCount(), 'The failed record must leave no page behind.');
    }

    #[Test]
    public function a_value_within_the_ceiling_renders_normally(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->boundedTemplate());

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['invoice_no' => 'INV-0001']); // exactly 8

        self::assertStringContainsString('(INV-0001) Tj', $pdf->output());
    }

    #[Test]
    public function the_ceiling_counts_characters_not_utf8_bytes(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->boundedTemplate());

        // 8 kana are 24 bytes but still 8 characters, which is what the
        // designer sized the box for.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['invoice_no' => 'あいうえおかきく']);

        self::assertSame(1, $pdf->pageCount());
    }

    /** A template whose note box is 60mm wide and declared to hold 2 lines. */
    private function lineBoundedTemplate(): string
    {
        $a4 = PageSize::a4();

        return MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            bindings: [
                new DynamicBinding(
                    'note',
                    20,
                    35,
                    'Helvetica',
                    10.0,
                    width: 60.0,
                    height: 20.0,
                    multiline: true,
                    maxLines: 2,
                ),
            ],
        );
    }

    #[Test]
    public function a_declared_max_lines_survives_the_mpb_round_trip(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->lineBoundedTemplate());

        self::assertSame(2, $tpl->bindings['note']->maxLines);
    }

    #[Test]
    public function a_value_that_wraps_within_the_ceiling_renders(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->lineBoundedTemplate());

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['note' => 'Short enough to fit']);

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function a_value_that_wraps_past_the_ceiling_is_rejected(): void
    {
        $tpl = CompiledTemplateLoader::loadString($this->lineBoundedTemplate());
        $pdf = new Document(credit: CreditOptions::disabled());

        try {
            // Comfortably more than two lines at 10pt in a 60mm box.
            $pdf->renderTemplate($tpl, ['note' => \str_repeat('wrapping words ', 20)]);
            self::fail('A value that overflows its line ceiling must not be rendered.');
        } catch (TemplateException $e) {
            self::assertSame(ErrorCode::MAX_LENGTH_EXCEEDED, $e->getErrorCodeEnum());
            $context = $e->getContext();
            self::assertSame('note', $context['field']);
            self::assertSame(2, $context['limit']);
            // A `lines` key rather than a `length` key is what says which limit
            // was hit, since both share one error code.
            self::assertArrayHasKey('lines', $context);
            self::assertGreaterThan(2, $context['lines']);
        }

        self::assertSame(0, $pdf->pageCount(), 'The failed record must leave no page behind.');
    }

    #[Test]
    public function the_line_ceiling_is_ignored_for_a_part_that_cannot_wrap(): void
    {
        // A single-line part is widened rather than wrapped, so counting lines
        // for it would always answer 1 — and rejecting on it would be a limit
        // the designer can never trip on purpose.
        $a4 = PageSize::a4();
        $tpl = CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            bindings: [
                new DynamicBinding('title', 20, 35, 'Helvetica', 10.0, width: 20.0, maxLines: 1),
            ],
        ));

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($tpl, ['title' => \str_repeat('very long single line ', 10)]);

        self::assertSame(1, $pdf->pageCount());
    }

    #[Test]
    public function a_narrow_box_can_reject_a_short_value(): void
    {
        // The two limits answer different questions: this value is well under
        // any sane character ceiling, but the box is too narrow to hold it in
        // two lines.
        $a4 = PageSize::a4();
        $tpl = CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            bindings: [
                new DynamicBinding(
                    'note',
                    20,
                    35,
                    'Helvetica',
                    10.0,
                    width: 12.0,
                    multiline: true,
                    maxLines: 2,
                ),
            ],
        ));

        $this->expectException(TemplateException::class);
        (new Document(credit: CreditOptions::disabled()))
            ->renderTemplate($tpl, ['note' => 'one two three four five six']);
    }

    #[Test]
    public function fill_applies_the_same_ceiling_as_the_renderer(): void
    {
        $field = new DynamicBinding('invoice_no', 20, 35, 'Helvetica', 12.0, maxLength: 8);

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();

        $this->expectException(TemplateException::class);
        $pdf->fill([$field], ['invoice_no' => 'INV-2026-0001']);
    }
}
