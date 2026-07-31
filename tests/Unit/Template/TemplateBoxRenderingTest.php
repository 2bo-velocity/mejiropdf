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
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Page\VerticalAlignment;
use MejiroPDF\Template\CompiledTemplateLoader;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\MpbWriter;
use MejiroPDF\Template\TemplateImage;
use MejiroPDF\Template\TemplateLink;
use MejiroPDF\Template\TemplateRenderer;
use MejiroPDF\Template\TemplateText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The box-based parts MejiroPDF Studio v1.0 compiles: text laid out inside a
 * designer's rectangle, images, and link annotations.
 *
 * The point-anchored labels of the original format are covered by
 * {@see TemplateRendererTest}; the two forms coexist, selected by whether the
 * part carries a width.
 */
#[CoversClass(TemplateRenderer::class)]
#[CoversClass(CompiledTemplateLoader::class)]
#[CoversClass(MpbWriter::class)]
final class TemplateBoxRenderingTest extends TestCase
{
    /** A 1×1 white PNG — enough to prove the bytes reach an XObject. */
    private const string PIXEL_PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==';

    /**
     * @param list<TemplateText>   $texts
     * @param list<DynamicBinding> $bindings
     * @param list<TemplateImage>  $images
     * @param list<TemplateLink>                    $links
     * @param array<string, scalar|\Stringable|null> $data
     */
    private function render(
        array $texts = [],
        array $bindings = [],
        array $images = [],
        array $links = [],
        array $data = [],
    ): string {
        $a4 = PageSize::a4();
        $bytes = MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: '',
            texts: $texts,
            bindings: $bindings,
            flags: 0,
            images: $images,
            links: $links,
        );

        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->renderTemplate(CompiledTemplateLoader::loadString($bytes), $data);

        return $pdf->output();
    }

    /**
     * The distinct `Tm` baselines in the page, top-most first.
     *
     * The typesetter emits one text object per laid-out unit, so a single line
     * yields many `Tm` operators that share a baseline; collapsing them is what
     * makes "how many lines?" and "how far down?" answerable.
     *
     * @return list<float>
     */
    private function baselines(string $pdf): array
    {
        // `1 0 <shear> 1 x y Tm` — the third slot carries the oblique shear.
        // Only laid-out text uses a text matrix; the point-anchored form and
        // the licence marker use `Td`, so neither shows up here.
        \preg_match_all('/1 0 [\d.-]+ 1 [\d.-]+ ([\d.-]+) Tm/', $pdf, $matches);

        $baselines = \array_values(\array_unique(\array_map('floatval', $matches[1])));
        \rsort($baselines);

        return $baselines;
    }

    /**
     * Every `Tj` operand concatenated, which is the text the page actually
     * shows. The typesetter emits one text object per laid-out unit, so a word
     * never survives as a single `(word) Tj` to assert against.
     */
    private function drawnText(string $pdf): string
    {
        \preg_match_all('/\((.*?)\) Tj/', $pdf, $matches);

        return \implode('', $matches[1]);
    }

    #[Test]
    public function a_boxed_label_treats_y_as_the_box_top_not_the_baseline(): void
    {
        $atPoint = $this->render([new TemplateText('Hello', 20, 50, 'Helvetica', 12.0)]);
        $inBox = $this->render([
            new TemplateText('Hello', 20, 50, 'Helvetica', 12.0, Alignment::LEFT, null, width: 80.0, height: 10.0),
        ]);

        // The point form puts the baseline exactly at y — 20mm across, and
        // 50mm down an A4 page is 700.16pt up from its foot.
        self::assertStringContainsString('56.69 700.16 Td', $atPoint);
        self::assertSame([], $this->baselines($atPoint), 'the point path uses no text matrix');

        // The box form starts at the box top, so its baseline drops below it
        // to leave room for the ascender.
        self::assertLessThan(700.16, $this->baselines($inBox)[0]);
    }

    #[Test]
    public function a_multiline_part_wraps_inside_its_box_and_a_single_line_part_does_not(): void
    {
        $sentence = 'The quick brown fox jumps over the lazy dog again and again.';

        $wrapped = $this->render([
            new TemplateText($sentence, 20, 20, 'Helvetica', 11.0, Alignment::LEFT, null, width: 40.0, height: 40.0, multiline: true),
        ]);
        $oneLine = $this->render([
            new TemplateText($sentence, 20, 20, 'Helvetica', 11.0, Alignment::LEFT, null, width: 40.0, height: 40.0),
        ]);

        self::assertGreaterThan(1, \count($this->baselines($wrapped)), 'the multiline part should break');
        self::assertCount(1, $this->baselines($oneLine), 'a single-line part keeps its one line');
    }

    #[Test]
    public function vertical_alignment_moves_the_text_down_its_box(): void
    {
        $box = static fn (VerticalAlignment $v): TemplateText => new TemplateText(
            'Total',
            20,
            20,
            'Helvetica',
            10.0,
            Alignment::LEFT,
            null,
            width: 60.0,
            height: 40.0,
            verticalAlign: $v,
        );

        $top = $this->baselines($this->render([$box(VerticalAlignment::TOP)]))[0];
        $middle = $this->baselines($this->render([$box(VerticalAlignment::MIDDLE)]))[0];
        $bottom = $this->baselines($this->render([$box(VerticalAlignment::BOTTOM)]))[0];

        // PDF y grows upward, so lower on the page means a smaller number.
        self::assertGreaterThan($middle, $top);
        self::assertGreaterThan($bottom, $middle);
    }

    #[Test]
    public function a_box_colour_and_underline_reach_the_drawn_run(): void
    {
        $plain = $this->render([
            new TemplateText('Link', 20, 20, 'Helvetica', 11.0, Alignment::LEFT, null, width: 40.0, height: 8.0),
        ]);
        $styled = $this->render([
            new TemplateText('Link', 20, 20, 'Helvetica', 11.0, Alignment::LEFT, '#1A56DB', width: 40.0, height: 8.0, underline: true),
        ]);

        self::assertStringContainsString('0.000 0.000 0.000 rg', $plain);
        self::assertStringContainsString('0.102 0.337 0.859 rg', $styled);
        // The underline is a stroked rule the plain run never emits, drawn in
        // the run's own colour.
        self::assertStringNotContainsString(' m\n', $plain);
        self::assertStringContainsString("0.102 0.337 0.859 RG", $styled);
        self::assertGreaterThan(\substr_count($plain, "\nS\n"), \substr_count($styled, "\nS\n"));
    }

    #[Test]
    public function rotation_wraps_the_box_in_a_transformation_matrix(): void
    {
        $pdf = $this->render([
            new TemplateText('Draft', 100, 100, 'Helvetica', 24.0, Alignment::CENTER, null, width: 60.0, height: 12.0, rotation: 45.0),
        ]);

        // cos 45° = sin 45° = 0.70711.
        self::assertMatchesRegularExpression('/0\.70711 0\.70711 -0\.70711 0\.70711 [\d.]+ [\d.]+ cm/', $pdf);
    }

    #[Test]
    public function a_binding_falls_back_when_the_data_has_no_value(): void
    {
        $binding = new DynamicBinding(
            'customer',
            20,
            40,
            'Helvetica',
            11.0,
            Alignment::LEFT,
            null,
            width: 80.0,
            height: 8.0,
            fallback: 'N/A',
        );

        $filled = $this->render(bindings: [$binding], data: ['customer' => 'Acme']);
        $empty = $this->render(bindings: [$binding], data: []);

        self::assertStringContainsString('Acme', $this->drawnText($filled));
        self::assertStringNotContainsString('N/A', $this->drawnText($filled));
        self::assertStringContainsString('N/A', $this->drawnText($empty));
    }

    #[Test]
    public function an_image_part_embeds_an_xobject_and_honours_contain(): void
    {
        $pdf = $this->render(images: [
            new TemplateImage(
                data: (string) \base64_decode(self::PIXEL_PNG_BASE64, true),
                key: null,
                x: 20.0,
                y: 20.0,
                width: 40.0,
                height: 20.0,
                fit: 'contain',
            ),
        ]);

        self::assertStringContainsString('/Subtype /Image', $pdf);
        self::assertStringContainsString(' Do', $pdf);
        // A square source contained in a 40×20mm box is drawn 20×20mm, so the
        // `cm` carries equal extents (20mm = 56.69pt).
        self::assertMatchesRegularExpression('/56\.69 0 0 56\.69 [\d.]+ [\d.]+ cm/', $pdf);
    }

    #[Test]
    public function a_bound_image_prefers_the_value_supplied_at_render_time(): void
    {
        $image = new TemplateImage(
            data: '',
            key: 'logo',
            x: 10.0,
            y: 10.0,
            width: 20.0,
            height: 20.0,
        );

        $withValue = $this->render(images: [$image], data: ['logo' => (string) \base64_decode(self::PIXEL_PNG_BASE64, true)]);
        $without = $this->render(images: [$image], data: []);

        self::assertStringContainsString('/Subtype /Image', $withValue);
        self::assertStringNotContainsString('/Subtype /Image', $without);
    }

    #[Test]
    public function a_link_part_becomes_a_uri_annotation(): void
    {
        $pdf = $this->render(links: [new TemplateLink(20.0, 170.0, 80.0, 8.0, 'https://mejiropdf.com')]);

        self::assertStringContainsString('/Annots', $pdf);
        self::assertStringContainsString('/Subtype /Link', $pdf);
        self::assertStringContainsString('https://mejiropdf.com', $pdf);
    }

    #[Test]
    public function the_payload_round_trips_through_the_writer_and_the_loader(): void
    {
        $a4 = PageSize::a4();
        $bytes = MpbWriter::build(
            width: $a4->width,
            height: $a4->height,
            staticVectorOps: "1 0 0 RG\n",
            texts: [new TemplateText('T', 1, 2, 'Helvetica', 9.0, Alignment::CENTER, '#FF0000', width: 3.0, height: 4.0, rotation: 15.0, verticalAlign: VerticalAlignment::BOTTOM, lineHeight: 1.25, underline: true, multiline: true)],
            bindings: [new DynamicBinding('k', 5, 6, 'Helvetica', 8.0, Alignment::RIGHT, null, width: 7.0, fallback: 'f')],
            images: [new TemplateImage('bytes', 'photo', 1.0, 2.0, 3.0, 4.0, 30.0, 'cover')],
            links: [new TemplateLink(1.0, 2.0, 3.0, 4.0, 'https://example.com')],
        );

        $template = CompiledTemplateLoader::loadString($bytes);
        $text = $template->texts[0];
        $binding = $template->bindings['k'];
        $image = $template->images[0];

        self::assertSame(3.0, $text->width);
        self::assertSame(15.0, $text->rotation);
        self::assertSame(VerticalAlignment::BOTTOM, $text->verticalAlign);
        self::assertSame(1.25, $text->lineHeight);
        self::assertTrue($text->underline);
        self::assertTrue($text->multiline);
        self::assertSame('#FF0000', $text->color);

        self::assertSame('f', $binding->fallback);
        self::assertSame(Alignment::RIGHT, $binding->align);
        self::assertTrue($binding->isBoxed());

        self::assertSame('bytes', $image->data, 'image bytes survive base64 in the payload');
        self::assertSame('photo', $image->key);
        self::assertSame('cover', $image->fit);
        self::assertSame(30.0, $image->rotation);

        self::assertSame('https://example.com', $template->links[0]->url);
    }

    #[Test]
    public function a_payload_written_before_boxes_existed_still_loads_as_point_labels(): void
    {
        // Exactly what an older Studio emitted: no w/h/valign/underline keys.
        $legacy = \json_encode([
            'meta' => ['width' => 595.28, 'height' => 841.89],
            'texts' => [['text' => 'Old', 'x' => 10, 'y' => 20, 'font' => 'Helvetica', 'size' => 9, 'align' => 'left', 'color' => null]],
            'bindings' => (object) [],
        ], \JSON_THROW_ON_ERROR);
        $bytes = \pack('a4nnNN', 'MPBF', 1, 0, 0, \strlen($legacy)) . $legacy;

        $template = CompiledTemplateLoader::loadString($bytes);

        self::assertFalse($template->texts[0]->isBoxed());
        self::assertSame(1.6, $template->texts[0]->lineHeight, 'falls back to the engine default');
        self::assertSame(VerticalAlignment::TOP, $template->texts[0]->verticalAlign);
        self::assertSame([], $template->images);
        self::assertSame([], $template->links);
    }
}
