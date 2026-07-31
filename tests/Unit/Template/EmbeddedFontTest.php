<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option) any
 * later version. This program is distributed WITHOUT ANY WARRANTY; see the GNU
 * Affero General Public License <https://www.gnu.org/licenses/> for details.
 */

namespace MejiroPDF\Tests\Unit\Template;

use MejiroPDF\Document;
use MejiroPDF\Exception\FontException;
use MejiroPDF\Exception\TemplateException;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\Mpb;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateText;
use MejiroPDF\Tests\Support\FindFont;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A template can carry its own fonts, so a report is one file to deploy rather
 * than a file plus whatever the designer had installed.
 */
final class EmbeddedFontTest extends TestCase
{
    use FindFont;

    private function face(): TrueTypeFont
    {
        return TrueTypeFont::fromFile($this->findTrueTypeFont(), 'StudioSans');
    }

    /** A template whose one label is drawn in the embedded face. */
    private function templateWith(TrueTypeFont $font): string
    {
        $a4 = PageSize::a4();

        return MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Invoice', 20, 30, 'StudioSans', 18.0)],
            bindings: [new DynamicBinding('customer', 20, 45, 'StudioSans', 11.0)],
            fonts: ['StudioSans' => $font],
        );
    }

    #[Test]
    public function embedding_a_font_sets_the_flag_the_reader_looks_for(): void
    {
        $bytes = $this->templateWith($this->face());

        /** @var array{flags: int} $header */
        $header = \unpack(Mpb::HEADER_FORMAT, \substr($bytes, 0, Mpb::HEADER_SIZE));

        self::assertTrue(Mpb::hasFlag($header['flags'], Mpb::FLAG_FONTS));
    }

    #[Test]
    public function a_template_without_fonts_is_byte_identical_to_before(): void
    {
        // The segment is additive: adding the feature must not change the bytes
        // of a template that does not use it.
        $a4 = PageSize::a4();
        $bytes = MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Invoice', 20, 30)],
        );

        /** @var array{flags: int, staticLen: int, bindLen: int} $header */
        $header = \unpack(Mpb::HEADER_FORMAT, \substr($bytes, 0, Mpb::HEADER_SIZE));

        self::assertFalse(Mpb::hasFlag($header['flags'], Mpb::FLAG_FONTS));
        self::assertSame(
            Mpb::HEADER_SIZE + $header['staticLen'] + $header['bindLen'],
            \strlen($bytes),
            'A template with no fonts must carry no font segment at all.',
        );
    }

    #[Test]
    public function the_font_survives_the_round_trip(): void
    {
        $font = $this->face();
        $template = CompiledTemplateLoader::loadString($this->templateWith($font));

        self::assertArrayHasKey('StudioSans', $template->fonts);
        self::assertSame($font->rawBytes, $template->fonts['StudioSans']->rawBytes);
        self::assertSame('StudioSans', $template->fonts['StudioSans']->baseName);
    }

    #[Test]
    public function re_encoding_a_loaded_template_keeps_its_fonts(): void
    {
        $original = $this->templateWith($this->face());
        $again = MpbWriter::fromTemplate(CompiledTemplateLoader::loadString($original));

        self::assertSame($original, $again);
    }

    #[Test]
    public function the_embedded_face_is_what_gets_drawn(): void
    {
        $template = CompiledTemplateLoader::loadString($this->templateWith($this->face()));

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($template, ['customer' => 'Acme']);
        $out = $pdf->output();

        // A composite Type0/Identity-H font with an embedded file is what an
        // embedded TrueType face compiles to; a Standard 14 fallback would show
        // up as /Type1 with no FontFile2.
        self::assertStringContainsString('/FontFile2', $out);
        self::assertStringContainsString('/Identity-H', $out);
        // The subset tag ("ABCDEF+") the PDF spec requires in front of a
        // subsetted BaseFont, so this also says the name reached the output.
        self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+StudioSans~', $out);
    }

    #[Test]
    public function the_document_ships_a_subset_not_the_whole_file(): void
    {
        $font = $this->face();
        $template = CompiledTemplateLoader::loadString($this->templateWith($font));

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate($template, ['customer' => 'Acme']);

        // Subsetting stays at render time, where the glyphs actually drawn are
        // known — the template cannot know its data, so it carries the whole
        // font and the document cuts it down.
        self::assertLessThan(
            \strlen($font->rawBytes),
            \strlen($pdf->output()),
            'The rendered PDF should be smaller than the font it draws with.',
        );
    }

    #[Test]
    public function a_named_font_can_be_registered_instead_of_embedded(): void
    {
        // The other half of the story: a template that only names a face, for
        // one the designer may not redistribute or want in every file.
        $a4 = PageSize::a4();
        $template = CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Invoice', 20, 30, 'CorporateSans', 18.0)],
        ));

        self::assertSame([], $template->fonts);

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->registerFont($this->face(), 'CorporateSans');
        $pdf->renderTemplate($template, []);

        self::assertStringContainsString('/FontFile2', $pdf->output());
    }

    #[Test]
    public function an_unresolvable_font_name_still_fails_loudly(): void
    {
        $a4 = PageSize::a4();
        $template = CompiledTemplateLoader::loadString(MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: [new TemplateText('Invoice', 20, 30, 'NeverInstalled', 18.0)],
        ));

        $this->expectException(FontException::class);
        (new Document(credit: CreditOptions::disabled()))->renderTemplate($template, []);
    }

    #[Test]
    public function a_registration_outlives_the_template_that_did_not_need_it(): void
    {
        // Rendering a template that embeds "StudioSans" must not leave that
        // face standing in for a registration the caller made under the same
        // name for the next template.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->registerFont($this->face(), 'CorporateSans');
        $pdf->renderTemplate(CompiledTemplateLoader::loadString($this->templateWith($this->face())), []);

        self::assertArrayHasKey('CorporateSans', $pdf->namedFonts());
    }

    #[Test]
    public function a_truncated_font_segment_is_rejected(): void
    {
        $bytes = $this->templateWith($this->face());

        $this->expectException(TemplateException::class);
        // Half the font is not a font, and reading on would take whatever bytes
        // happen to follow as glyph outlines.
        CompiledTemplateLoader::loadString(\substr($bytes, 0, \strlen($bytes) - 1024));
    }

    #[Test]
    public function a_font_segment_that_lies_about_its_count_is_rejected(): void
    {
        $bytes = $this->templateWith($this->face());

        /** @var array{staticLen: int, bindLen: int} $header */
        $header = \unpack(Mpb::HEADER_FORMAT, \substr($bytes, 0, Mpb::HEADER_SIZE));
        $at = Mpb::HEADER_SIZE + $header['staticLen'] + $header['bindLen'];

        $this->expectException(TemplateException::class);
        CompiledTemplateLoader::loadString(
            \substr($bytes, 0, $at) . \pack('n', 2) . \substr($bytes, $at + 2),
        );
    }

    #[Test]
    public function an_older_reader_ignores_the_segment_it_does_not_know(): void
    {
        // The compatibility claim, exercised: clearing the flag is what a
        // reader predating it effectively does, and the file must still load —
        // just without the fonts.
        $bytes = $this->templateWith($this->face());
        $withoutFlag = \substr($bytes, 0, 6) . \pack('n', 0) . \substr($bytes, 8);

        $template = CompiledTemplateLoader::loadString($withoutFlag);

        self::assertSame([], $template->fonts);
        self::assertCount(1, $template->texts);
    }
}
