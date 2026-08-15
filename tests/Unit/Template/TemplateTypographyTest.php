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
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\ElementPainter;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateText;
use MejiroPDF\Text\JapaneseSpacing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Studio template chooses how Japanese and Latin sit together, and that
 * choice has to survive the `.mpb` and reach the glyphs.
 *
 * The engine has had the spacing since 1.2, but a template had no way to ask
 * for it: the painter built its options with the setting left at its default.
 * A Japanese business form is exactly the text the rule was written for.
 */
#[CoversClass(CompiledTemplateLoader::class)]
#[CoversClass(MpbWriter::class)]
#[CoversClass(ElementPainter::class)]
#[CoversClass(CompiledTemplate::class)]
final class TemplateTypographyTest extends TestCase
{
    private static function mpb(JapaneseSpacing $spacing): string
    {
        return MpbWriter::build(
            210.0,
            297.0,
            '',
            texts: [new TemplateText('和文とRailsの間。', 20.0, 30.0, 'HeiseiMin-W3', 12.0, width: 150.0, height: 10.0)],
            japaneseSpacing: $spacing,
        );
    }

    /** @return list<float> the x of every glyph drawn, in points */
    private static function glyphs(string $mpb): array
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate(CompiledTemplateLoader::loadString($mpb));
        \preg_match_all("~1 0 0[\d.]* 1 ([\d.]+) [\d.]+ Tm~", $pdf->output(), $hits);

        return \array_map('floatval', $hits[1]);
    }

    #[Test]
    public function a_template_names_the_document_rather_than_itself(): void
    {
        // A template is called "invoice"; the invoice it produces is called
        // "3 月分請求書 — 山田商店", which is only knowable from the record.
        $mpb = MpbWriter::build(210.0, 297.0, '', document: [
            'title' => '{{ month }}月分請求書 — {{ customer }}',
            'author' => '株式会社メジロ',
        ]);
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate(CompiledTemplateLoader::loadString($mpb), ['month' => '3', 'customer' => '山田商店']);
        $out = $pdf->output();

        self::assertStringContainsString(self::utf16('3月分請求書 — 山田商店'), $out);
        self::assertStringContainsString(self::utf16('株式会社メジロ'), $out);
    }

    #[Test]
    public function a_field_the_record_does_not_carry_leaves_a_gap_rather_than_the_placeholder(): void
    {
        // Printing "{{ customer }}" in a document's title is worse than a
        // shorter title, and failing the batch over it is worse still.
        $mpb = MpbWriter::build(210.0, 297.0, '', document: ['title' => '請求書 {{ customer }}']);
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate(CompiledTemplateLoader::loadString($mpb), []);
        $out = $pdf->output();

        self::assertStringNotContainsString(self::utf16('{{'), $out);
        self::assertStringContainsString(self::utf16('請求書'), $out);
    }

    #[Test]
    public function a_template_that_says_nothing_about_itself_writes_no_properties(): void
    {
        $mpb = MpbWriter::build(210.0, 297.0, '', document: ['title' => '', 'author' => '']);
        self::assertStringNotContainsString('"document"', $mpb);
        self::assertSame([], CompiledTemplateLoader::loadString($mpb)->document);
    }

    /** The hex body a PDF text string gets when it is not pure ASCII. */
    private static function utf16(string $text): string
    {
        return \strtoupper(\bin2hex((string) \mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }

    #[Test]
    public function a_template_that_does_not_ask_is_set_solid(): void
    {
        self::assertSame(JapaneseSpacing::NONE, CompiledTemplateLoader::loadString(self::mpb(JapaneseSpacing::NONE))->japaneseSpacing);
        self::assertSame(JapaneseSpacing::NONE, (new CompiledTemplate(1, 0, 210.0, 297.0, '', [], []))->japaneseSpacing);
    }

    #[Test]
    public function the_key_is_absent_unless_it_is_asked_for(): void
    {
        // Absence is what every `.mpb` written before the setting existed says,
        // so such a file must still compile to the bytes it always did.
        self::assertStringNotContainsString('typography', self::mpb(JapaneseSpacing::NONE));
        self::assertStringContainsString(
            '"typography":{"japaneseSpacing":"jis_x_4051"}',
            self::mpb(JapaneseSpacing::JIS_X_4051),
        );
    }

    #[Test]
    public function the_setting_survives_the_file(): void
    {
        self::assertSame(
            JapaneseSpacing::JIS_X_4051,
            CompiledTemplateLoader::loadString(self::mpb(JapaneseSpacing::JIS_X_4051))->japaneseSpacing,
        );
    }

    #[Test]
    public function a_quarter_em_opens_between_the_scripts_on_the_page(): void
    {
        $solid = self::glyphs(self::mpb(JapaneseSpacing::NONE));
        $spaced = self::glyphs(self::mpb(JapaneseSpacing::JIS_X_4051));

        self::assertNotSame([], $solid);
        self::assertCount(\count($solid), $spaced);

        // と → R is the seam: one em plus a quarter, where it was one em flat.
        $seam = 3;   // 和 文 と R …
        self::assertEqualsWithDelta(12.0, $solid[$seam] - $solid[$seam - 1], 0.01);
        self::assertEqualsWithDelta(12.0 * 1.25, $spaced[$seam] - $spaced[$seam - 1], 0.01);
    }

    #[Test]
    public function an_unknown_value_is_read_as_solid_rather_than_refused(): void
    {
        // A newer Studio must not make a template unreadable by an older engine,
        // and the reverse: an unrecognised setting is not worth failing a batch.
        $mpb = self::mpb(JapaneseSpacing::JIS_X_4051);
        // Same length, so the segment header still describes the payload.
        $broken = \str_replace('"jis_x_4051"', '"zenkaku_ak"', $mpb);
        self::assertNotSame($mpb, $broken);

        self::assertSame(JapaneseSpacing::NONE, CompiledTemplateLoader::loadString($broken)->japaneseSpacing);
    }

    #[Test]
    public function the_painter_keeps_an_injected_instance_when_nothing_is_asked(): void
    {
        // forTemplate() hands back the same painter unless the template differs,
        // so a caller that injected one still gets theirs.
        $painter = new ElementPainter();
        $plain = CompiledTemplateLoader::loadString(self::mpb(JapaneseSpacing::NONE));
        $asked = CompiledTemplateLoader::loadString(self::mpb(JapaneseSpacing::JIS_X_4051));

        self::assertSame($painter, $painter->forTemplate($plain));
        self::assertNotSame($painter, $painter->forTemplate($asked));
    }
}
