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

namespace MejiroPDF;

use MejiroPDF\Block\BlockHandle;
use MejiroPDF\Block\BlockOverride;
use MejiroPDF\Exception\DocumentException;
use MejiroPDF\Exception\FontException;
use MejiroPDF\Exception\RenderException;
use MejiroPDF\Exception\StreamException;
use MejiroPDF\Font\CharacterTracker;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Font\FontFace;
use MejiroPDF\Font\FontKind;
use MejiroPDF\Font\FontMetrics;
use MejiroPDF\Font\FontRegistry;
use MejiroPDF\Font\FontResolver;
use MejiroPDF\Font\GlyphCoverage;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Font\TrueTypeFont;
use MejiroPDF\Font\TrueTypeSubsetter;
use MejiroPDF\Image\Image;
use MejiroPDF\Image\RasterImage;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Layout\BorderBox;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\GridColumn;
use MejiroPDF\Layout\GridTable;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Import\ImportedPage;
use MejiroPDF\Import\PdfImportException;
use MejiroPDF\Import\PdfPageImporter;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\PageNumberOptions;
use MejiroPDF\Exception\SecurityException;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Security\Encryption;
use MejiroPDF\Security\Signature;
use MejiroPDF\Security\Signer;
use MejiroPDF\Security\StandardSecurityHandler;
use MejiroPDF\Stream\SafeStreamWriter;
use MejiroPDF\Table\Column;
use MejiroPDF\Table\Table;
use MejiroPDF\Template\CompiledTemplate;
use MejiroPDF\Template\DynamicBinding;
use MejiroPDF\Template\ElementPainter;
use MejiroPDF\Template\TemplateRenderer;
use MejiroPDF\Text\Hyphenator;
use MejiroPDF\Text\JapaneseTextEncoder;
use MejiroPDF\Text\LineBreaker;
use MejiroPDF\Text\Pagination;
use MejiroPDF\Text\PdfEscaper;
use MejiroPDF\Text\SmartPunctuation;
use MejiroPDF\Text\TextToken;
use MejiroPDF\Text\TextTokenizer;
use MejiroPDF\Text\Typesetter;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\TypesetResult;
use MejiroPDF\Writer\DocumentWriter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The top-level, iText-style facade for building a PDF.
 *
 * Coordinates and lengths are given in **millimetres from the top-left corner**
 * (X right, Y down); font sizes are in points. Each page's main content streams
 * straight to a buffer; the footer (page number + credit) is emitted as a
 * deferred per-page stream at finalization, so {TOTAL} is always exact while
 * memory stays flat with respect to what a single page holds.
 */
final class Document
{
    /** @var resource */
    private $stream;

    private readonly SafeStreamWriter $writer;
    private readonly DocumentWriter $doc;
    private readonly FontRegistry $fonts;

    private readonly int $catalogId;
    private readonly int $pagesId;

    /** Captured once so the Info dict and the XMP packet agree (PDF/A requires it). */
    private readonly \DateTimeImmutable $createdAt;

    /**
     * @var list<array{
     *     page: int,
     *     footer: int,
     *     size: PageSize,
     *     annots: list<array{id: int, kind: string, rect: string, url: string, target: int}>
     * }>
     */
    private array $pages = [];

    /** @var list<array{name: string, id: int}> Image XObjects for the shared /Resources. */
    private array $xobjects = [];

    /** @var array<int, string> spl_object_id(image) => XObject name, for de-duplication. */
    private array $embeddedImages = [];

    /** @var list<array{name: string, id: int}> Gradient shadings for the shared /Resources. */
    private array $shadings = [];

    /** Bézier control-point ratio for a quarter arc (4/3·(√2−1)). */
    private const float BEZIER_K = 0.5522847498;

    /** Debug-guide stroke colours: text blocks magenta, images cyan. */
    private const string GUIDE_TEXT = '1 0 1';
    private const string GUIDE_IMAGE = '0 0.6 1';

    /** How many text lines a drop cap spans (and indents beside). */
    private const int DROP_CAP_LINES = 3;

    /** @var list<array{kind: string, rect: string, url: string, target: int}> Annotations for the open page. */
    private array $pendingAnnots = [];

    /** @var list<array{title: string, level: int, page: int}> Outline / TOC entries. */
    private array $bookmarks = [];

    /** @var array{heading: string, font: FontFace, fontSize: float}|null A requested table of contents, built at finalize. */
    private ?array $pendingToc = null;

    /** Index in {@see $pages} where the TOC page(s) are inserted (-1 = none). */
    private int $tocSlot = -1;

    /** How many pages the finalized TOC occupies (0 until built). */
    private int $tocPageCount = 0;

    private FontFace $defaultFont = StandardFont::HELVETICA;

    /**
     * Faces registered by name for templates that name rather than carry them.
     *
     * @var array<string, FontFace>
     */
    private array $namedFonts = [];

    /**
     * Faces the template being rendered brought with it.
     *
     * @var array<string, FontFace>
     */
    private array $templateFonts = [];

    private readonly LoggerInterface $logger;

    /** Maximum pages this document may start; 0 = no ceiling. */
    private int $maxPageLimit = 0;

    /** Lazily built the first time hyphenation is used. */
    private ?Hyphenator $hyphenator = null;
    private ?PageNumberOptions $pageNumbers = null;

    private ?PageSize $currentSize = null;
    private string $content = '';
    private bool $pageOpen = false;
    private bool $finalized = false;

    /**
     * Per-block decisions made by the calling code, keyed by block id.
     *
     * @var array<string, BlockOverride>
     */
    private array $blockOverrides = [];

    private ?StandardSecurityHandler $security = null;

    private ?Signature $signature = null;
    private int $acroFormId = 0;
    private int $sigWidgetId = 0;
    private int $sigDictId = 0;
    private bool $sigWidgetPlaced = false;

    /**
     * @param LoggerInterface|null $logger       Any PSR-3 logger — the host's Monolog
     *                                           or framework logger, or the bundled
     *                                           {@see \MejiroPDF\Log\FileLogger}. Null
     *                                           records nothing, which is the default.
     * @param bool                 $debugGuides  Outline every text and image block
     *                                           so a layout can be read off the page
     *                                           itself. A development aid — leave it
     *                                           off in production, where the frames
     *                                           would print.
     * @param bool                 $strictCanvas Refuse geometry that falls outside the
     *                                           page (MP-RND-4001) instead of letting
     *                                           the viewer clip it. Off by default
     *                                           because full-bleed art draws past the
     *                                           edge on purpose; on, it catches a
     *                                           mm/pt mix-up at the call site.
     */
    public function __construct(
        private readonly PageSize $defaultSize = new PageSize(595.275590551181, 841.8897637795275),
        private readonly CreditOptions $credit = new CreditOptions(),
        ?Encryption $encryption = null,
        ?Signature $signature = null,
        private readonly bool $pdfA = false,
        ?LoggerInterface $logger = null,
        private readonly bool $debugGuides = false,
        private readonly bool $strictCanvas = false,
    ) {
        // Defence in depth: the eager bootstrap guard normally fires first, but
        // this also covers a host that instantiates Document via a custom loader.
        PhpVersion::guard(\PHP_VERSION_ID, \PHP_VERSION);

        $this->logger = $logger ?? new NullLogger();

        if ($encryption !== null && $signature !== null) {
            throw new \LogicException('Encrypting and signing the same document is not supported.');
        }
        if ($pdfA && $encryption !== null) {
            throw new \LogicException('PDF/A-1b forbids encryption.');
        }

        $stream = \fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw StreamException::invalidResource();
        }

        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->stream = $stream;
        $this->writer = new SafeStreamWriter($stream);
        $this->doc = new DocumentWriter($this->writer);
        $this->fonts = new FontRegistry();

        if ($encryption !== null) {
            $this->security = StandardSecurityHandler::fromEncryption($encryption);
            $this->doc->enableEncryption($this->security);
        }

        $this->catalogId = $this->doc->allocateObjectId(); // 1
        $this->pagesId = $this->doc->allocateObjectId();    // 2

        if ($signature !== null) {
            $this->signature = $signature;
            $this->acroFormId = $this->doc->allocateObjectId();
            $this->sigWidgetId = $this->doc->allocateObjectId();
            $this->sigDictId = $this->doc->allocateObjectId();
        }

        // PDF/A-1 is defined against PDF 1.4.
        $this->doc->writeHeader($pdfA ? '1.4' : '1.7');

        // Logging is bounded by the document, not by its size: this line and the
        // one in finalize() are the only unconditional calls, so a correct
        // 100,000-page report still produces two log entries. Everything else is
        // logged only when the engine has degraded something.
        $this->logger->debug('MejiroPDF {version} initialised', [
            'version' => Version::VERSION,
            'pdfVersion' => $pdfA ? '1.4' : '1.7',
            'pdfA' => $pdfA,
            'encrypted' => $encryption !== null,
            'signed' => $signature !== null,
        ]);
    }

    /**
     * The logger this document was given, for collaborators that draw on its
     * behalf ({@see \MejiroPDF\Template\TemplateRenderer}). Returns a
     * {@see NullLogger} when none was supplied, so callers never null-check.
     */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Refuse to start more than $limit pages, raising `MP-CORE-PAGE_OVERFLOW`
     * on the one that would cross the line. Pass 0 to lift the ceiling.
     *
     * Streaming output has no natural stopping point: a report driven by a query
     * that unexpectedly returns a million rows will happily write pages until
     * the request dies, and the eventual timeout says nothing about why. A
     * declared ceiling converts that into a single exception naming the limit.
     */
    public function setMaxPageLimit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('The page limit cannot be negative; pass 0 to disable it.');
        }
        $this->maxPageLimit = $limit;

        return $this;
    }

    /**
     * Make a font resolvable by name, for templates that name a face instead of
     * carrying it.
     *
     * A `.mpb` can embed its fonts, and then it needs nothing installed here.
     * When it only names one — a corporate face that may not be redistributable,
     * or one too large to want in every template — this is where the server says
     * which file that name means.
     *
     * @param string|null $name The name bindings refer to it by. Defaults to the
     *                          font's own PostScript name, which is what Studio
     *                          writes unless told otherwise.
     */
    public function registerFont(FontFace $font, ?string $name = null): self
    {
        $this->namedFonts[$name ?? $font->baseFontName()] = $font;

        return $this;
    }

    /**
     * Fonts the current template brought with it.
     *
     * Kept apart from {@see registerFont()} rather than merged into it, so that
     * rendering a template does not quietly replace a registration the caller
     * made and expects to still hold for the next one.
     *
     * @param array<string, FontFace> $fonts
     *
     * @internal Called by {@see TemplateRenderer} as a render begins.
     */
    public function adoptTemplateFonts(array $fonts): void
    {
        $this->templateFonts = $fonts;
    }

    /**
     * Every font resolvable by name right now, embedded ones first.
     *
     * @return array<string, FontFace>
     */
    public function namedFonts(): array
    {
        return $this->templateFonts + $this->namedFonts;
    }

    public function setDefaultFont(FontFace $font): self
    {
        $this->defaultFont = $font;

        return $this;
    }

    public function setPageNumbers(PageNumberOptions $options): self
    {
        $this->pageNumbers = $options;

        return $this;
    }

    public function addPage(?PageSize $size = null): self
    {
        $this->assertNotFinalized();

        // Counted before the page opens, so the limit is the number of pages
        // that exist rather than the number that were attempted.
        if ($this->maxPageLimit > 0 && $this->pageCount() >= $this->maxPageLimit) {
            throw DocumentException::pageLimitExceeded($this->maxPageLimit);
        }

        $this->commitPage();

        $this->currentSize = $size ?? $this->defaultSize;
        $this->content = '';
        $this->pageOpen = true;

        // Hidden credit: emit the marker first so it lands on the back layer.
        // PDF has no z-index — the order in the content stream is the order of
        // painting — so anything drawn afterwards covers it. The visible credit
        // takes the opposite route: it goes into the footer stream, which the
        // page lists after the body (see buildFooter).
        if (!$this->credit->enabled) {
            $this->content .= $this->creditMarkerOps($this->currentSize);
        }

        return $this;
    }

    /**
     * Draw a line of text with its baseline anchored at (x, y) millimetres.
     */
    public function text(
        float $x,
        float $y,
        string $text,
        ?FontFace $font = null,
        float $size = 11.0,
        ?string $color = null,
    ): self {
        $this->ensurePage();

        $font ??= $this->defaultFont;
        $this->fonts->track($font, $text); // no-op unless embedded TrueType
        $colorOp = $color === null ? null : $this->hexToRg($color);

        // The run's box is only needed by the development flags, and measuring
        // it costs a metrics lookup per call — so it is computed only when one
        // of them is on.
        if ($this->debugGuides || $this->strictCanvas) {
            [$boxY, $boxW, $boxH] = $this->runBox($text, $font, $size, $y);
            $this->assertOnCanvas('Text', $x, $boxY, $boxW, $boxH);
            $this->debugGuide($x, $boxY, $boxW, $boxH, self::GUIDE_TEXT);
        }

        $this->content .= $this->textOps(
            $this->fonts->resourceName($font),
            $size,
            PageSize::mm($x),
            $this->pageHeightPt() - PageSize::mm($y),
            $this->showOperand($font, $text),
            $colorOp,
        );

        return $this;
    }

    /**
     * The box (top-y, width, height in mm) a baseline-anchored run occupies.
     *
     * The em is split 0.8 above the baseline and 0.2 below, the same split the
     * typesetter uses to place lines, so a guide drawn here lines up with one
     * drawn around a typeset block.
     *
     * @return array{float, float, float}
     */
    private function runBox(string $text, FontFace $font, float $size, float $baselineY): array
    {
        $widthMm = FontMetrics::for($font)->textWidth($text, $size) / PageSize::MM_TO_PT;
        $ascentMm = $size * 0.8 / PageSize::MM_TO_PT;
        $descentMm = $size * 0.2 / PageSize::MM_TO_PT;

        return [$baselineY - $ascentMm, $widthMm, $ascentMm + $descentMm];
    }

    /**
     * Draw text aligned around the anchor (x, y): LEFT anchors the left edge,
     * RIGHT the right edge, CENTER the centre. Coordinates in millimetres.
     */
    public function textAligned(
        float $x,
        float $y,
        string $text,
        ?FontFace $font = null,
        float $size = 11.0,
        Alignment $align = Alignment::LEFT,
        ?string $color = null,
    ): self {
        $resolved = $font ?? $this->defaultFont;

        $anchorX = $x;
        if ($align !== Alignment::LEFT && $text !== '') {
            $widthMm = FontMetrics::for($resolved)->textWidth($text, $size) / PageSize::MM_TO_PT;
            $anchorX = $align === Alignment::RIGHT ? $x - $widthMm : $x - $widthMm / 2.0;
        }

        return $this->text($anchorX, $y, $text, $resolved, $size, $color);
    }

    /**
     * Lay out inline-markup text with the typesetting engine: ruby, emphasis
     * dots, bold/italic, underline/strikethrough, colour/highlight, Smart
     * Quotes, and word / 行頭禁則 / ぶら下げ line-breaking per {@see TypesetterOptions}.
     *
     * $width is the box extent **along the reading flow**: for horizontal text
     * it is the line width and (x, y) is the box top-left, with lines flowing
     * downward; for vertical text (`WritingMode::VERTICAL_RL`) it is the column
     * height and (x, y) is the box top-**right**, with columns flowing leftward.
     *
     * @see TextTokenizer for the supported markup (`|漢字《かんじ》`, `**bold**`, …).
     */
    public function typeset(
        string $text,
        float $x,
        float $y,
        float $width,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
    ): self {
        $this->renderTypeset($text, $x, $y, $width, $font, $size, $options);

        return $this;
    }

    /**
     * Like {@see typeset()} but returns the millimetres the block consumed on
     * its cross axis (downward for horizontal, leftward for vertical), so the
     * next block can be placed directly after it — e.g.
     * `$y += $pdf->flow($heading, ...); $pdf->flow($body, $x, $y, ...);`.
     */
    public function flow(
        string $text,
        float $x,
        float $y,
        float $width,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
    ): float {
        return $this->renderTypeset($text, $x, $y, $width, $font, $size, $options)->height / PageSize::MM_TO_PT;
    }

    /**
     * Flow multi-paragraph text down a page and **auto-paginate**: lines are
     * placed from $topY within [$topY, $bottomY] mm; when the next line would
     * cross $bottomY a new page is started and the text continues. Paragraphs
     * (blank lines) are kept off page edges per {@see TypesetterOptions::$minLinesAtPageBreak}
     * (widow/orphan control). Returns the Y (mm) just below the last line on the
     * final page. Horizontal writing only.
     */
    public function flowText(
        string $text,
        float $x,
        float $topY,
        float $width,
        float $bottomY,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
    ): float {
        $this->ensurePage();
        $font ??= $this->defaultFont;
        $options ??= new TypesetterOptions();
        if ($options->writingMode->isVertical()) {
            throw new \LogicException('flowText() supports horizontal writing only.');
        }

        $tokens = TextTokenizer::tokenize($text);
        if ($options->enableSmartQuotes) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken(SmartPunctuation::apply($t->text), $t->rubyText, $t->style),
                $tokens,
            );
        }

        $lines = (new LineBreaker(FontMetrics::for($font), $size, $options, $options->enableHyphenation ? $this->hyphenator() : null))->break($tokens, PageSize::mm($width));
        if ($lines === []) {
            return $topY;
        }

        $typesetter = new Typesetter($options);
        $resource = $this->fonts->resourceName($font);
        $encode = fn (string $run): string => $this->showOperand($font, $run);

        $heights = [];
        $paragraphEnd = [];
        foreach ($lines as $line) {
            foreach ($line->units as $unit) {
                $this->fonts->track($font, $unit->text);
                if ($unit->rubyText !== null) {
                    $this->fonts->track($this->rubyFace($options, $font, $unit->rubyText), $unit->rubyText);
                }
            }
            $heights[] = $typesetter->lineHeight($line, $size) / PageSize::MM_TO_PT;
            $paragraphEnd[] = $line->isLastInParagraph;
        }

        $plan = Pagination::plan($heights, $paragraphEnd, $topY, $bottomY, $options->minLinesAtPageBreak);

        // Render each page's run of lines as one segment, adding pages in order.
        $count = \count($lines);
        $currentPage = 0;
        $index = 0;
        while ($index < $count) {
            $page = $plan[$index]['page'];
            while ($currentPage < $page) {
                $this->addPage();
                $currentPage++;
            }

            $runEnd = $index;
            while ($runEnd < $count && $plan[$runEnd]['page'] === $page) {
                $runEnd++;
            }

            $segment = \array_slice($lines, $index, $runEnd - $index);
            $segmentTop = $plan[$index]['y'];
            $segmentHeight = $plan[$runEnd - 1]['y'] + $heights[$runEnd - 1] - $segmentTop;

            $this->assertOnCanvas('Flowed text', $x, $segmentTop, $width, $segmentHeight);
            $this->content .= $typesetter->render(
                $segment,
                PageSize::mm($x),
                $this->pageHeightPt() - PageSize::mm($segmentTop),
                PageSize::mm($width),
                $size,
                $resource,
                $encode,
                ...$this->rubyHandles($options, $font),
            )->operators;
            // One frame per page, so a paginated flow shows where each page's
            // share of the text actually landed.
            $this->debugGuide($x, $segmentTop, $width, $segmentHeight, self::GUIDE_TEXT);

            $index = $runEnd;
        }

        return $plan[$count - 1]['y'] + $heights[$count - 1];
    }

    /**
     * Draw a {@see BorderBox}: typeset its text within the padding at (x, y) mm
     * of the given width, then frame it with a background and border sized to
     * the measured text height. Returns the outer height (mm) so the next block
     * flows below it. Horizontal writing only.
     */
    public function drawBox(
        BorderBox $box,
        float $x,
        float $y,
        float $width,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
    ): float {
        $this->ensurePage();
        if (($options ?? new TypesetterOptions())->writingMode->isVertical()) {
            throw new \LogicException('drawBox() supports horizontal writing only.');
        }

        $pad = $box->padding;
        $innerWidth = $width - $pad->left - $pad->right;
        if ($innerWidth <= 0.0) {
            throw new \InvalidArgumentException('BorderBox width is smaller than its horizontal padding.');
        }

        $text = $this->typesetResult($box->text, $x + $pad->left, $y + $pad->top, $innerWidth, $font, $size, $options);
        $outerHeight = $text->height / PageSize::MM_TO_PT + $pad->top + $pad->bottom;
        $this->assertOnCanvas('Border box', $x, $y, $width, $outerHeight);

        // Background and border first, so the text sits on top.
        if ($box->backgroundColor !== null) {
            $this->rect($x, $y, $width, $outerHeight, fill: $box->backgroundColor);
        }
        $borders = $box->borders;
        if ($borders->top !== null) {
            $this->borderLine($x, $y, $x + $width, $y, $borders->top);
        }
        if ($borders->bottom !== null) {
            $this->borderLine($x, $y + $outerHeight, $x + $width, $y + $outerHeight, $borders->bottom);
        }
        if ($borders->left !== null) {
            $this->borderLine($x, $y, $x, $y + $outerHeight, $borders->left);
        }
        if ($borders->right !== null) {
            $this->borderLine($x + $width, $y, $x + $width, $y + $outerHeight, $borders->right);
        }
        $this->content .= $text->operators;
        // The inner frame marks the padding box, which is what a caller chasing
        // "why is my text off-centre?" actually needs to see.
        $this->debugGuide(
            $x + $pad->left,
            $y + $pad->top,
            $innerWidth,
            $text->height / PageSize::MM_TO_PT,
            self::GUIDE_TEXT,
        );

        return $outerHeight;
    }

    /**
     * Place typeset text as a positioned, optionally rotated object: the text is
     * laid out in a $width-mm box, then that box's {@see Anchor} point is pinned
     * to (x, y) mm and rotated $rotation degrees counter-clockwise about it —
     * for titles, stamps, obi bands and diagonal watermarks. Horizontal only.
     */
    public function textObject(
        string $text,
        float $x,
        float $y,
        float $width,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
        Anchor $anchor = Anchor::TOP_LEFT,
        float $rotation = 0.0,
    ): self {
        $this->ensurePage();
        if (($options ?? new TypesetterOptions())->writingMode->isVertical()) {
            throw new \LogicException('textObject() supports horizontal writing only.');
        }

        // Lay the text out in a local frame with its top-left at (0, 0).
        $widthPt = PageSize::mm($width);
        $result = $this->typesetResultAt($text, 0.0, 0.0, $widthPt, $font, $size, $options);
        $heightPt = $result->height;

        // Local coordinates of the anchor point within that frame (y is down).
        [$ax, $ay] = match ($anchor) {
            Anchor::TOP_LEFT => [0.0, 0.0],
            Anchor::TOP_RIGHT => [$widthPt, 0.0],
            Anchor::BOTTOM_LEFT => [0.0, -$heightPt],
            Anchor::BOTTOM_RIGHT => [$widthPt, -$heightPt],
            Anchor::CENTER => [$widthPt / 2.0, -$heightPt / 2.0],
        };

        $rad = \deg2rad($rotation);
        $cos = \cos($rad);
        $sin = \sin($rad);
        $targetX = PageSize::mm($x);
        $targetY = $this->pageHeightPt() - PageSize::mm($y);

        // CTM maps a local point L to R(θ)·(L − anchor) + target.
        $e = $targetX - ($ax * $cos - $ay * $sin);
        $f = $targetY - ($ax * $sin + $ay * $cos);

        // Only the anchor is checked: the object is free to rotate its corners
        // off the sheet — a diagonal watermark routinely does — but the point
        // it was pinned to has to be on the page.
        $this->assertOnCanvas('Text object anchor', $x, $y, 0.0, 0.0);

        // The frame rides inside the same transform as the text, so it rotates
        // with the object instead of boxing it in page space.
        $this->content .= \sprintf("q\n%.5F %.5F %.5F %.5F %.2F %.2F cm\n", $cos, $sin, -$sin, $cos, $e, $f)
            . $result->operators
            . ($this->debugGuides ? self::guideOps(0.0, -$heightPt, $widthPt, $heightPt, self::GUIDE_TEXT) : '')
            . "Q\n";

        return $this;
    }

    /**
     * The height (mm) the text would consume laid out in a $width-mm box,
     * without drawing — {@see GridTable} uses it to size rows to content.
     */
    public function measureTextHeight(
        string $text,
        float $width,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
    ): float {
        return $this->typesetResultAt($text, 0.0, 0.0, PageSize::mm($width), $font, $size, $options)->height
            / PageSize::MM_TO_PT;
    }

    /**
     * Stroke a line between two points (mm) with a {@see BorderStyle}
     * (SOLID/DASHED/DOTTED/DOUBLE). Collaborator API used by {@see GridTable}.
     */
    public function styledLine(float $x1, float $y1, float $x2, float $y2, BorderStyle $border): self
    {
        $this->ensurePage();
        $this->assertLineOnCanvas('Rule', $x1, $y1, $x2, $y2);
        $this->borderLine($x1, $y1, $x2, $y2, $border);

        return $this;
    }

    /**
     * Begin a content-sized {@see GridTable} anchored at (x, y) mm. Rows stream
     * out as you add them, so memory stays flat regardless of row count.
     *
     * @param list<float|GridColumn> $columns column widths (mm) or definitions
     */
    public function grid(array $columns, float $x, float $y, ?FontFace $font = null, float $size = 9.0): GridTable
    {
        $this->ensurePage();

        return new GridTable($this, $columns, $x, $y, $font ?? $this->defaultFont, $size);
    }

    /**
     * Refuse geometry that falls outside the page, when the document was
     * constructed with `strictCanvas: true` (MP-RND-4001).
     *
     * The check exists for the mistakes that are otherwise invisible: a value
     * passed in points where millimetres were meant, or a column offset that
     * walks the last cell off the sheet. A viewer clips silently, so without
     * this the first sign of trouble is missing content in a finished document.
     * It stays opt-in because bleeding past the edge is a legitimate technique.
     */
    private function assertOnCanvas(string $what, float $x, float $y, float $width, float $height): void
    {
        if (!$this->strictCanvas) {
            return;
        }

        $pageWidth = $this->pageWidthMm();
        $pageHeight = $this->pageHeightMm();
        // Millimetres round-trip through points, so a box laid flush against
        // the edge lands a few nanometres past it. That is arithmetic, not a
        // mistake worth an exception.
        $slack = 1.0e-6;

        if (
            $x < -$slack
            || $y < -$slack
            || $x + $width > $pageWidth + $slack
            || $y + $height > $pageHeight + $slack
        ) {
            throw RenderException::canvasOverflow($what, $x, $y, $width, $height, $pageWidth, $pageHeight);
        }
    }

    /**
     * The two-point form of {@see assertOnCanvas}, for strokes given as
     * endpoints rather than as a box.
     */
    private function assertLineOnCanvas(string $what, float $x1, float $y1, float $x2, float $y2): void
    {
        if (!$this->strictCanvas) {
            return;
        }

        $x = \min($x1, $x2);
        $y = \min($y1, $y2);
        $this->assertOnCanvas($what, $x, $y, \max($x1, $x2) - $x, \max($y1, $y2) - $y);
    }

    /**
     * Outline a block, when the document was constructed with
     * `debugGuides: true`.
     *
     * Drawn after its block so the frame is never painted over, in a hairline
     * dash that reads as an annotation rather than as part of the design. What
     * it answers is "where does the engine think this box is?" — the question
     * behind most layout bugs, and one that is otherwise guesswork because the
     * boxes themselves leave no mark.
     */
    private function debugGuide(float $x, float $y, float $width, float $height, string $stroke): void
    {
        if (!$this->debugGuides) {
            return;
        }

        $this->content .= self::guideOps(
            PageSize::mm($x),
            $this->pageHeightPt() - PageSize::mm($y) - PageSize::mm($height),
            PageSize::mm($width),
            PageSize::mm($height),
            $stroke,
        );
    }

    /**
     * The guide frame itself, in PDF points and y-up space, so a caller that
     * has already set up a transform ({@see textObject}) can place one inside it.
     */
    private static function guideOps(float $xPt, float $bottomYPt, float $widthPt, float $heightPt, string $stroke): string
    {
        return \sprintf(
            "q\n%s RG\n0.2 w\n[1 1] 0 d\n%.2F %.2F %.2F %.2F re\nS\nQ\n",
            $stroke,
            $xPt,
            $bottomYPt,
            $widthPt,
            $heightPt,
        );
    }

    /**
     * Stroke one box edge (mm). DOUBLE becomes two parallel lines offset
     * perpendicular to the edge by the border width.
     */
    private function borderLine(float $x1, float $y1, float $x2, float $y2, BorderStyle $border): void
    {
        if ($border->style === LineStyle::NONE || $border->width <= 0.0) {
            return;
        }
        if ($border->style === LineStyle::DOUBLE) {
            $offset = $border->width;
            $horizontal = \abs($y1 - $y2) < 1.0e-9;
            [$dx, $dy] = $horizontal ? [0.0, $offset] : [$offset, 0.0];
            $this->emitBorderLine($x1 - $dx, $y1 - $dy, $x2 - $dx, $y2 - $dy, $border);
            $this->emitBorderLine($x1 + $dx, $y1 + $dy, $x2 + $dx, $y2 + $dy, $border);

            return;
        }
        $this->emitBorderLine($x1, $y1, $x2, $y2, $border);
    }

    private function emitBorderLine(float $x1, float $y1, float $x2, float $y2, BorderStyle $border): void
    {
        $h = $this->pageHeightPt();
        $w = PageSize::mm($border->width);
        $ops = "q\n" . \sprintf("%.2F w\n", $w) . $this->hexToRg($border->color, stroke: true) . "\n";
        $ops .= match ($border->style) {
            LineStyle::DASHED => \sprintf("[%.2F %.2F] 0 d\n", $w * 3.0, $w * 2.0),
            LineStyle::DOTTED => \sprintf("1 J\n[%.2F %.2F] 0 d\n", $w * 0.01, $w * 2.5),
            default => '',
        };
        $ops .= \sprintf(
            "%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
            PageSize::mm($x1),
            $h - PageSize::mm($y1),
            PageSize::mm($x2),
            $h - PageSize::mm($y2),
        );
        $this->content .= $ops;
    }

    /**
     * Set the hyphenator used when {@see TypesetterOptions::$enableHyphenation}
     * is on — e.g. to supply a fuller pattern set. Returns $this.
     */
    public function setHyphenator(Hyphenator $hyphenator): self
    {
        $this->hyphenator = $hyphenator;

        return $this;
    }

    private function hyphenator(): Hyphenator
    {
        return $this->hyphenator ??= new Hyphenator();
    }

    private function renderTypeset(
        string $text,
        float $x,
        float $y,
        float $width,
        ?FontFace $font,
        float $size,
        ?TypesetterOptions $options,
    ): TypesetResult {
        $result = $this->typesetResult($text, $x, $y, $width, $font, $size, $options);

        // Vertical text fills leftward from (x, y) as its top-right corner, so
        // the block's origin is not the one that was passed in.
        $vertical = ($options ?? new TypesetterOptions())->writingMode->isVertical();
        $consumed = $result->height / PageSize::MM_TO_PT;
        [$boxX, $boxY, $boxW, $boxH] = $vertical
            ? [$x - $consumed, $y, $consumed, $width]
            : [$x, $y, $width, $consumed];

        $this->assertOnCanvas('Text block', $boxX, $boxY, $boxW, $boxH);
        $this->content .= $result->operators;
        $this->debugGuide($boxX, $boxY, $boxW, $boxH, self::GUIDE_TEXT);

        return $result;
    }

    /**
     * Lay out text and return its operators + height without appending, so a
     * caller (e.g. {@see drawBox}) can draw a background and border first.
     * Coordinates are millimetres from the page top-left.
     */
    private function typesetResult(
        string $text,
        float $x,
        float $y,
        float $width,
        ?FontFace $font,
        float $size,
        ?TypesetterOptions $options,
    ): TypesetResult {
        return $this->typesetResultAt(
            $text,
            PageSize::mm($x),
            $this->pageHeightPt() - PageSize::mm($y),
            PageSize::mm($width),
            $font,
            $size,
            $options,
        );
    }

    /**
     * As {@see typesetResult} but at an explicit PDF-point geometry (x, top-y in
     * y-up space, width in points), so callers can render into a local frame and
     * transform it — the basis of {@see textObject}.
     */
    private function typesetResultAt(
        string $text,
        float $xPt,
        float $topYPt,
        float $widthPt,
        ?FontFace $font,
        float $size,
        ?TypesetterOptions $options,
    ): TypesetResult {
        $this->ensurePage();
        $font ??= $this->defaultFont;
        $options ??= new TypesetterOptions();

        $tokens = $options->skipsInlineMarkup()
            ? [new TextToken($text)]
            : TextTokenizer::tokenize($text);

        // A block-level style (colour, underline) sits under whatever inline
        // markup produced, so a run can still override the colour it inherits.
        $base = $options->baseStyle;
        if ($base !== null) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken($t->text, $t->rubyText, $t->style->over($base)),
                $tokens,
            );
        }

        if ($options->enableSmartQuotes) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken(SmartPunctuation::apply($t->text), $t->rubyText, $t->style),
                $tokens,
            );
        }

        // A drop cap enlarges the first character and indents the lines beside it.
        $indent = 0.0;
        $indentLines = 0;
        $capOps = '';
        $dropCap = $this->extractDropCap($tokens, $options);
        if ($dropCap !== null) {
            $capSize = $size * self::DROP_CAP_LINES;
            $indent = FontMetrics::for($font)->textWidth($dropCap, $capSize) + $capSize * 0.1;
            $indentLines = self::DROP_CAP_LINES;
            $this->fonts->track($font, $dropCap);
            $capOps = \sprintf(
                "BT\n0 0 0 rg\n/%s %.2F Tf\n1 0 0 1 %.2F %.2F Tm\n%s Tj\nET\n",
                $this->fonts->resourceName($font),
                $capSize,
                $xPt,
                $topYPt - $capSize * 0.8,
                $this->showOperand($font, $dropCap),
            );
        }

        $lines = (new LineBreaker(FontMetrics::for($font), $size, $options, $options->enableHyphenation ? $this->hyphenator() : null))
            ->break($tokens, $widthPt, $indent, $indentLines);

        // Track every rendered glyph (incl. ruby) so an embedded font subsets
        // them in — otherwise they would render blank.
        foreach ($lines as $line) {
            foreach ($line->units as $unit) {
                $this->fonts->track($font, $unit->text);
                if ($unit->rubyText !== null) {
                    $this->fonts->track($this->rubyFace($options, $font, $unit->rubyText), $unit->rubyText);
                }
            }
        }

        $result = (new Typesetter($options))->render(
            $lines,
            $xPt,
            $topYPt,
            $widthPt,
            $size,
            $this->fonts->resourceName($font),
            fn (string $run): string => $this->showOperand($font, $run),
            ...$this->rubyHandles($options, $font),
        );

        return new TypesetResult($capOps . $result->operators, $result->height, $result->lineCount);
    }

    /**
     * Pull a leading drop-cap character off the token stream: the first token
     * tagged `.dropcap`, or the first character when {@see TypesetterOptions::$enableDropCaps}
     * is on. Horizontal only; returns null when there is nothing to enlarge.
     *
     * @param list<TextToken> $tokens
     */
    private function extractDropCap(array &$tokens, TypesetterOptions $options): ?string
    {
        if ($tokens === [] || $options->writingMode->isVertical()) {
            return null;
        }
        $first = $tokens[0];
        if ($first->rubyText !== null || $first->text === '') {
            return null;
        }
        $explicit = $first->style->isDropCap;
        if (!$explicit && !$options->enableDropCaps) {
            return null;
        }

        $chars = \mb_str_split($first->text, 1, 'UTF-8');
        $cap = $chars[0];
        if (\trim($cap) === '') {
            return null; // never drop-cap a leading space
        }

        $rest = \implode('', \array_slice($chars, 1));
        if ($rest === '') {
            \array_shift($tokens);
        } else {
            $tokens[0] = new TextToken($rest, null, $explicit ? $first->style->with(isDropCap: false) : $first->style);
        }

        return $cap;
    }

    /**
     * Draw a straight line between two points (millimetres).
     */
    public function line(float $x1, float $y1, float $x2, float $y2, float $weight = 0.2, ?string $color = null): self
    {
        $this->ensurePage();
        $this->assertLineOnCanvas('Line', $x1, $y1, $x2, $y2);
        $h = $this->pageHeightPt();

        $ops = "q\n" . \sprintf("%.2F w\n", PageSize::mm($weight));
        if ($color !== null) {
            $ops .= $this->hexToRg($color, stroke: true) . "\n";
        }
        $ops .= \sprintf("%.2F %.2F m\n", PageSize::mm($x1), $h - PageSize::mm($y1));
        $ops .= \sprintf("%.2F %.2F l\n", PageSize::mm($x2), $h - PageSize::mm($y2));
        $ops .= "S\nQ\n";

        $this->content .= $ops;

        return $this;
    }

    /**
     * Draw a rectangle whose top-left corner is at (x, y) millimetres.
     */
    public function rect(
        float $x,
        float $y,
        float $width,
        float $height,
        float $weight = 0.2,
        ?string $stroke = null,
        ?string $fill = null,
    ): self {
        $this->ensurePage();

        $this->assertOnCanvas('Rectangle', $x, $y, $width, $height);

        $px = PageSize::mm($x);
        $lowerY = $this->pageHeightPt() - PageSize::mm($y) - PageSize::mm($height);

        $ops = "q\n" . \sprintf("%.2F w\n", PageSize::mm($weight));
        if ($stroke !== null) {
            $ops .= $this->hexToRg($stroke, stroke: true) . "\n";
        }
        if ($fill !== null) {
            $ops .= $this->hexToRg($fill) . "\n";
        }
        $ops .= \sprintf("%.2F %.2F %.2F %.2F re\n", $px, $lowerY, PageSize::mm($width), PageSize::mm($height));
        $ops .= match (true) {
            $fill !== null && $stroke !== null => "B\n",
            $fill !== null => "f\n",
            default => "S\n",
        };
        $ops .= "Q\n";

        $this->content .= $ops;

        return $this;
    }

    /**
     * Draw a circle centred at (cx, cy) millimetres with radius r (mm).
     */
    public function circle(float $cx, float $cy, float $r, float $weight = 0.2, ?string $stroke = null, ?string $fill = null): self
    {
        return $this->ellipse($cx, $cy, $r, $r, $weight, $stroke, $fill);
    }

    /**
     * Draw an ellipse centred at (cx, cy) millimetres with radii (rx, ry) mm.
     */
    public function ellipse(
        float $cx,
        float $cy,
        float $rx,
        float $ry,
        float $weight = 0.2,
        ?string $stroke = null,
        ?string $fill = null,
    ): self {
        $this->ensurePage();

        $this->assertOnCanvas('Ellipse', $cx - $rx, $cy - $ry, $rx * 2.0, $ry * 2.0);

        $pcx = PageSize::mm($cx);
        $pcy = $this->pageHeightPt() - PageSize::mm($cy);
        $prx = PageSize::mm($rx);
        $pry = PageSize::mm($ry);
        $ox = $prx * self::BEZIER_K;
        $oy = $pry * self::BEZIER_K;

        $path = \sprintf("%.2F %.2F m\n", $pcx + $prx, $pcy)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $pcx + $prx, $pcy + $oy, $pcx + $ox, $pcy + $pry, $pcx, $pcy + $pry)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $pcx - $ox, $pcy + $pry, $pcx - $prx, $pcy + $oy, $pcx - $prx, $pcy)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $pcx - $prx, $pcy - $oy, $pcx - $ox, $pcy - $pry, $pcx, $pcy - $pry)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $pcx + $ox, $pcy - $pry, $pcx + $prx, $pcy - $oy, $pcx + $prx, $pcy)
            . "h\n";

        $this->content .= $this->pathBlock($path, $weight, $stroke, $fill);

        return $this;
    }

    /**
     * Draw a rounded rectangle whose top-left corner is at (x, y) millimetres.
     */
    public function roundedRect(
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius,
        float $weight = 0.2,
        ?string $stroke = null,
        ?string $fill = null,
    ): self {
        $this->ensurePage();

        $x0 = PageSize::mm($x);
        $x1 = $x0 + PageSize::mm($width);
        $y1 = $this->pageHeightPt() - PageSize::mm($y);
        $y0 = $y1 - PageSize::mm($height);
        $rr = \min(PageSize::mm($radius), ($x1 - $x0) / 2, ($y1 - $y0) / 2);
        $this->assertOnCanvas('Rounded rectangle', $x, $y, $width, $height);
        $k = $rr * self::BEZIER_K;

        $path = \sprintf("%.2F %.2F m\n", $x0 + $rr, $y0)
            . \sprintf("%.2F %.2F l\n", $x1 - $rr, $y0)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1 - $rr + $k, $y0, $x1, $y0 + $rr - $k, $x1, $y0 + $rr)
            . \sprintf("%.2F %.2F l\n", $x1, $y1 - $rr)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x1, $y1 - $rr + $k, $x1 - $rr + $k, $y1, $x1 - $rr, $y1)
            . \sprintf("%.2F %.2F l\n", $x0 + $rr, $y1)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x0 + $rr - $k, $y1, $x0, $y1 - $rr + $k, $x0, $y1 - $rr)
            . \sprintf("%.2F %.2F l\n", $x0, $y0 + $rr)
            . \sprintf("%.2F %.2F %.2F %.2F %.2F %.2F c\n", $x0, $y0 + $rr - $k, $x0 + $rr - $k, $y0, $x0 + $rr, $y0)
            . "h\n";

        $this->content .= $this->pathBlock($path, $weight, $stroke, $fill);

        return $this;
    }

    /**
     * Fill a rectangle (top-left at x, y millimetres) with a linear gradient
     * from $from to $to (hex colours). Horizontal by default; set $vertical for
     * bottom-to-top.
     */
    public function linearGradient(
        float $x,
        float $y,
        float $width,
        float $height,
        string $from,
        string $to,
        bool $vertical = false,
    ): self {
        $this->ensurePage();

        $x0 = PageSize::mm($x);
        $x1 = $x0 + PageSize::mm($width);
        $yTop = $this->pageHeightPt() - PageSize::mm($y);
        $yBottom = $yTop - PageSize::mm($height);

        $coords = $vertical
            ? \sprintf('%.2F %.2F %.2F %.2F', ($x0 + $x1) / 2, $yBottom, ($x0 + $x1) / 2, $yTop)
            : \sprintf('%.2F %.2F %.2F %.2F', $x0, ($yTop + $yBottom) / 2, $x1, ($yTop + $yBottom) / 2);

        $shading = \sprintf(
            '<< /ShadingType 2 /ColorSpace /DeviceRGB /Coords [ %s ] /Extend [ true true ] '
            . '/Function << /FunctionType 2 /Domain [ 0 1 ] /C0 [ %s ] /C1 [ %s ] /N 1 >> >>',
            $coords,
            $this->hexToRgb($from),
            $this->hexToRgb($to),
        );

        $name = $this->registerShading($shading);
        $this->content .= \sprintf(
            "q\n%.2F %.2F %.2F %.2F re W n\n/%s sh\nQ\n",
            $x0,
            $yBottom,
            $x1 - $x0,
            $yTop - $yBottom,
            $name,
        );

        return $this;
    }

    /**
     * Fill a rectangle with a radial gradient from $inner (at the centre) to
     * $outer (at the edge).
     */
    public function radialGradient(
        float $x,
        float $y,
        float $width,
        float $height,
        string $inner,
        string $outer,
    ): self {
        $this->ensurePage();

        $x0 = PageSize::mm($x);
        $x1 = $x0 + PageSize::mm($width);
        $yTop = $this->pageHeightPt() - PageSize::mm($y);
        $yBottom = $yTop - PageSize::mm($height);
        $cx = ($x0 + $x1) / 2;
        $cy = ($yTop + $yBottom) / 2;
        $radius = \max($x1 - $x0, $yTop - $yBottom) / 2;

        $shading = \sprintf(
            '<< /ShadingType 3 /ColorSpace /DeviceRGB /Coords [ %.2F %.2F 0 %.2F %.2F %.2F ] /Extend [ true true ] '
            . '/Function << /FunctionType 2 /Domain [ 0 1 ] /C0 [ %s ] /C1 [ %s ] /N 1 >> >>',
            $cx,
            $cy,
            $cx,
            $cy,
            $radius,
            $this->hexToRgb($inner),
            $this->hexToRgb($outer),
        );

        $name = $this->registerShading($shading);
        $this->content .= \sprintf(
            "q\n%.2F %.2F %.2F %.2F re W n\n/%s sh\nQ\n",
            $x0,
            $yBottom,
            $x1 - $x0,
            $yTop - $yBottom,
            $name,
        );

        return $this;
    }

    /**
     * Place an image with its top-left corner at (x, y) millimetres.
     *
     * A path or raw bytes are auto-detected (JPEG/PNG). If only one of width /
     * height is given the other is derived from the image's aspect ratio; if
     * neither is given a 40 mm width is assumed. The same image instance reused
     * across pages is embedded only once.
     */
    public function image(
        RasterImage|string $image,
        float $x,
        float $y,
        ?float $width = null,
        ?float $height = null,
    ): self {
        $this->ensurePage();

        $raster = \is_string($image) ? Image::fromFile($image) : $image;

        $key = \spl_object_id($raster);
        $name = $this->embeddedImages[$key] ?? null;
        if ($name === null) {
            $id = $raster->embed($this->doc);
            $name = 'Im' . \count($this->xobjects);
            $this->xobjects[] = ['name' => $name, 'id' => $id];
            $this->embeddedImages[$key] = $name;
        }

        $aspect = $raster->height() / $raster->width();
        if ($width === null && $height === null) {
            $width = 40.0;
            $height = 40.0 * $aspect;
        } elseif ($width === null) {
            $width = $height / $aspect;
        } elseif ($height === null) {
            $height = $width * $aspect;
        }

        $displayW = PageSize::mm($width);
        $displayH = PageSize::mm($height);
        $px = PageSize::mm($x);
        $py = $this->pageHeightPt() - PageSize::mm($y) - $displayH;

        $this->assertOnCanvas('Image', $x, $y, $width, $height);
        $this->content .= \sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/%s Do\nQ\n", $displayW, $displayH, $px, $py, $name);
        $this->debugGuide($x, $y, $width, $height, self::GUIDE_IMAGE);

        return $this;
    }

    /**
     * Import page $pageNumber of an existing PDF and paint it, scaled to fill the
     * current page, as a vector background. Call this before drawing foreground
     * content so it sits behind everything.
     *
     * Scope: classic-xref (PDF ≤ 1.4), unencrypted sources; cross-reference
     * streams (PDF 1.5+) and encrypted sources raise a PdfImportException.
     */
    public function importPageAsBackground(string $path, int $pageNumber = 1): self
    {
        [$name, $x0, $y0, $x1, $y1] = $this->embedImportedPage($path, $pageNumber);

        // Scale the imported BBox to fill the current page.
        $size = $this->currentSize ?? $this->defaultSize;
        $scaleX = ($x1 - $x0) != 0.0 ? $size->width / ($x1 - $x0) : 1.0;
        $scaleY = ($y1 - $y0) != 0.0 ? $size->height / ($y1 - $y0) : 1.0;
        $this->content .= \sprintf(
            "q\n%.4F 0 0 %.4F %.4F %.4F cm\n/%s Do\nQ\n",
            $scaleX,
            $scaleY,
            -$x0 * $scaleX,
            -$y0 * $scaleY,
            $name,
        );

        return $this;
    }

    /**
     * Import page $pageNumber of an existing PDF and place it with its top-left
     * corner at (x, y) millimetres. If only one of width/height is given the
     * other follows the imported aspect ratio; if neither, the page is placed at
     * its native size (1 pt = 1 pt).
     */
    public function importPage(
        string $path,
        int $pageNumber,
        float $x,
        float $y,
        ?float $width = null,
        ?float $height = null,
    ): self {
        [$name, $x0, $y0, $x1, $y1] = $this->embedImportedPage($path, $pageNumber);

        $nativeW = ($x1 - $x0) / PageSize::MM_TO_PT;
        $nativeH = ($y1 - $y0) / PageSize::MM_TO_PT;
        $aspect = $nativeW != 0.0 ? $nativeH / $nativeW : 1.0;

        if ($width === null && $height === null) {
            $width = $nativeW;
            $height = $nativeH;
        } elseif ($width === null) {
            $width = $aspect != 0.0 ? $height / $aspect : $nativeW;
        } elseif ($height === null) {
            $height = $width * $aspect;
        }

        // Map the imported BBox onto the target rectangle (top-left origin, mm).
        $scaleX = ($x1 - $x0) != 0.0 ? PageSize::mm($width) / ($x1 - $x0) : 1.0;
        $scaleY = ($y1 - $y0) != 0.0 ? PageSize::mm($height) / ($y1 - $y0) : 1.0;
        $tx = PageSize::mm($x) - $x0 * $scaleX;
        $ty = ($this->pageHeightPt() - PageSize::mm($y + $height)) - $y0 * $scaleY;

        $this->content .= \sprintf(
            "q\n%.4F 0 0 %.4F %.4F %.4F cm\n/%s Do\nQ\n",
            $scaleX,
            $scaleY,
            $tx,
            $ty,
            $name,
        );

        return $this;
    }

    /**
     * Open a new page whose size exactly matches page $pageNumber of an existing
     * PDF, and paint that page 1:1 as a background. Subsequent text/line/rect
     * calls then stamp onto the template in the same millimetre coordinate
     * space, while the source's own content streams — and therefore its layout
     * and fonts — are copied through untouched.
     *
     * This is MejiroPDF's non-destructive template-editing model: it overlays,
     * it never rewrites the original operators. Loop over {@see importedPageCount}
     * to stamp every page of a multi-page template.
     *
     * Same source scope as {@see importPage}: classic-xref or cross-reference
     * stream (PDF 1.5+), unencrypted.
     */
    public function editTemplatePage(string $path, int $pageNumber = 1): self
    {
        $this->assertNotFinalized();
        $imported = PdfPageImporter::import($this->readSource($path), $pageNumber);
        [$x0, $y0, $x1, $y1] = $this->parseBox($imported->bbox);

        $this->addPage(new PageSize(\max(1.0, $x1 - $x0), \max(1.0, $y1 - $y0)));

        $name = $this->embedImported($imported);
        // Origin-align the source box (its MediaBox need not start at 0 0).
        $this->content .= \sprintf("q\n1 0 0 1 %.4F %.4F cm\n/%s Do\nQ\n", -$x0, -$y0, $name);

        return $this;
    }

    /**
     * Number of pages in an existing PDF — for looping a template edit over each
     * page. Same source scope as {@see importPage}.
     */
    public function importedPageCount(string $path): int
    {
        return \count(PdfPageImporter::pageBoxes($this->readSource($path)));
    }

    /**
     * Read, import and embed a PDF page as a Form XObject, returning its
     * resource name and BBox corners (points).
     *
     * @return array{0: string, 1: float, 2: float, 3: float, 4: float}
     */
    private function embedImportedPage(string $path, int $pageNumber): array
    {
        $this->ensurePage();

        $imported = PdfPageImporter::import($this->readSource($path), $pageNumber);
        $name = $this->embedImported($imported);
        [$x0, $y0, $x1, $y1] = $this->parseBox($imported->bbox);

        return [$name, $x0, $y0, $x1, $y1];
    }

    private function readSource(string $path): string
    {
        $bytes = @\file_get_contents($path);
        if ($bytes === false) {
            throw PdfImportException::invalid("PDF file not found or unreadable: {$path}");
        }

        return $bytes;
    }

    /**
     * Copy a parsed page's reachable objects (remapping their numbers) and its
     * content into a Form XObject, returning the XObject's resource name.
     */
    private function embedImported(ImportedPage $imported): string
    {
        // Copy the imported objects, remapping their numbers into this document.
        $remap = [];
        foreach (\array_keys($imported->objects) as $sourceNumber) {
            $remap[$sourceNumber] = $this->doc->allocateObjectId();
        }
        foreach ($imported->objects as $sourceNumber => $object) {
            $this->doc->writeRawObject(
                $remap[$sourceNumber],
                $this->remapReferences($object['dict'], $remap),
                $object['stream'],
            );
        }

        $xobjectId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($xobjectId, $imported->content, \sprintf(
            '/Type /XObject /Subtype /Form /FormType 1 /BBox [ %s ] /Resources %s',
            $imported->bbox,
            $this->remapReferences($imported->resources, $remap),
        ));
        $name = 'Im' . \count($this->xobjects);
        $this->xobjects[] = ['name' => $name, 'id' => $xobjectId];

        return $name;
    }

    /**
     * Add a clickable web link over the rectangle at (x, y) millimetres.
     */
    public function link(float $x, float $y, float $width, float $height, string $url): self
    {
        $this->pendingAnnots[] = [
            'kind' => 'uri',
            'rect' => $this->annotRect($x, $y, $width, $height),
            'url' => $url,
            'target' => -1,
        ];

        return $this;
    }

    /**
     * Add a clickable internal link over the rectangle at (x, y) millimetres
     * that jumps to the given 1-based page number.
     */
    public function linkToPage(float $x, float $y, float $width, float $height, int $pageNumber): self
    {
        $this->pendingAnnots[] = [
            'kind' => 'goto',
            'rect' => $this->annotRect($x, $y, $width, $height),
            'url' => '',
            'target' => \max(0, $pageNumber - 1),
        ];

        return $this;
    }

    /**
     * Add an outline (bookmark) entry pointing to the current page. Larger
     * $level values nest under the most recent shallower entry.
     */
    public function bookmark(string $title, int $level = 0): self
    {
        $this->ensurePage();
        $this->bookmarks[] = ['title' => $title, 'level' => \max(0, $level), 'page' => \count($this->pages)];

        return $this;
    }

    /**
     * Insert a table-of-contents page here. It is rendered at finalization from
     * the {@see bookmark()} entries, with each title and its resolved page
     * number (2-pass: numbers are only known once every page exists).
     */
    public function tableOfContents(string $heading = 'Contents', ?FontFace $font = null, float $fontSize = 11.0): self
    {
        $this->assertNotFinalized();
        $this->commitPage();
        // Reserve this position; the actual page(s) are built and inserted at
        // finalize, once every entry and its page number is known.
        $this->tocSlot = \count($this->pages);
        $this->pendingToc = ['heading' => $heading, 'font' => $font ?? $this->defaultFont, 'fontSize' => $fontSize];

        return $this;
    }

    /**
     * Begin a streaming table anchored at (x, y) millimetres.
     *
     * @param list<Column> $columns
     */
    public function table(
        array $columns,
        float $x,
        float $y,
        FontFace $font = StandardFont::HELVETICA,
        float $fontSize = 9.0,
    ): Table {
        $this->ensurePage();

        return new Table($this, $columns, $x, $y, $font, $fontSize);
    }

    /**
     * Render a compiled `.mpb` template onto a new page, filling its variable
     * bindings from $data.
     *
     * Values may nest: a block's condition reads dotted paths
     * (`props.paymentMethod`) and a repeater's data source names a list of
     * rows, so $data is a record rather than a flat map of strings.
     *
     * @param array<string, mixed> $data
     */
    public function renderTemplate(CompiledTemplate $template, array $data = []): self
    {
        (new TemplateRenderer())->render($this, $template, $data);

        return $this;
    }

    /**
     * Stamp named-field values onto the current page.
     *
     * Each field is a {@see DynamicBinding} — a position, alignment, font and
     * colour bound to a key; its value is looked up in $data by that key and
     * drawn. Missing or empty values are skipped, exactly as when filling a
     * compiled `.mpb` template (this is the same fill path the template renderer
     * uses). Pairs with {@see editTemplatePage}: describe a form's fields once,
     * then fill() them per record.
     *
     * @param array<array-key, DynamicBinding> $fields
     * @param array<string, mixed>             $data
     */
    public function fill(array $fields, array $data): self
    {
        // Validated in full before anything is drawn, so an over-long value
        // fails without leaving half a record on the page (MP-VAL-MAX_LENGTH).
        foreach ($fields as $field) {
            TemplateRenderer::assertFits($field, ElementPainter::valueFor($field, $data), $this->namedFonts());
        }

        $this->ensurePage();

        foreach ($fields as $field) {
            $string = ElementPainter::valueFor($field, $data);
            if ($string === '') {
                continue;
            }

            $this->textAligned(
                $field->x,
                $field->y,
                $string,
                FontResolver::resolve($field->font, $this->namedFonts()),
                $field->fontSize,
                $field->align,
                $field->color,
            );
        }

        return $this;
    }

    /**
     * Address a block of the template about to be rendered.
     *
     * ```php
     * $pdf->block('bank_details')->hide();
     * $pdf->block('items')->bindData($invoice->lines);
     * $pdf->renderTemplate($template, $data);
     * ```
     *
     * A template's `condition` is the designer's rule; this is the program's.
     * Both are legitimate — the expression covers what the record says, the
     * call covers what only the surrounding code knows — so an override wins
     * where it is set and defers everywhere else.
     *
     * Naming a block the template does not have is deliberately silent. The
     * template is somebody else's file, and code guarding a panel that was
     * later renamed should not take a thousand-record batch down with it.
     */
    public function block(string $id): BlockHandle
    {
        return new BlockHandle($this->blockOverrides[$id] ??= new BlockOverride());
    }

    /**
     * The overrides recorded so far, for the renderer.
     *
     * @return array<string, BlockOverride>
     */
    public function blockOverrides(): array
    {
        return $this->blockOverrides;
    }

    /**
     * Forget every block override.
     *
     * Rendering the same template twice from one Document — a batch loop — will
     * otherwise carry the first record's decisions into the second.
     */
    public function clearBlockOverrides(): self
    {
        $this->blockOverrides = [];

        return $this;
    }

    /**
     * Append raw PDF content-stream operators to the current page.
     *
     * Advanced / collaborator API used by {@see Table} and the template
     * renderer. Prefer text/line/rect.
     */
    public function appendContentOps(string $ops): self
    {
        $this->ensurePage();
        $this->content .= $ops;

        return $this;
    }

    /**
     * Register a font and return its PDF resource name (e.g. "F1").
     * Used by collaborators that build content operators directly.
     */
    public function fontResource(StandardFont $font): string
    {
        return $this->fonts->resourceName($font);
    }

    public function pageWidthMm(): float
    {
        return ($this->currentSize ?? $this->defaultSize)->width / PageSize::MM_TO_PT;
    }

    public function pageHeightMm(): float
    {
        return ($this->currentSize ?? $this->defaultSize)->height / PageSize::MM_TO_PT;
    }

    public function output(): string
    {
        $this->finalize();
        \rewind($this->stream);
        $bytes = (string) \stream_get_contents($this->stream);

        return $this->signature !== null ? $this->applySignature($bytes) : $bytes;
    }

    public function save(string $path): void
    {
        $this->finalize();

        // A signed document must be assembled in full to compute the byte range.
        if ($this->signature !== null) {
            if (@\file_put_contents($path, $this->output()) === false) {
                throw StreamException::writeFailed(0);
            }

            return;
        }

        $out = @\fopen($path, 'wb');
        if ($out === false) {
            throw StreamException::writeFailed(0);
        }

        \rewind($this->stream);
        \stream_copy_to_stream($this->stream, $out);
        \fclose($out);
    }

    public function pageCount(): int
    {
        return \count($this->pages) + ($this->pageOpen ? 1 : 0);
    }

    // ------------------------------------------------------------------ internals

    public function ensurePage(): void
    {
        $this->assertNotFinalized();

        if (!$this->pageOpen) {
            $this->addPage();
        }
    }

    private function pageHeightPt(): float
    {
        return ($this->currentSize ?? $this->defaultSize)->height;
    }

    private function commitPage(): void
    {
        if (!$this->pageOpen) {
            return;
        }

        $size = $this->currentSize ?? $this->defaultSize;

        // Reserve annotation objects for this page (written at finalize).
        $annots = [];
        foreach ($this->pendingAnnots as $spec) {
            $annots[] = ['id' => $this->doc->allocateObjectId(), ...$spec];
        }
        $this->pendingAnnots = [];

        $contentId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($contentId, $this->content);

        // The footer (page number + credit) is written later, once TOTAL is known.
        $footerId = $this->doc->allocateObjectId();

        $ids = \array_map(static fn (array $a): string => "{$a['id']} 0 R", $annots);
        if ($this->signature !== null && !$this->sigWidgetPlaced) {
            $ids[] = "{$this->sigWidgetId} 0 R";
            $this->sigWidgetPlaced = true;
        }
        $annotsRef = $ids === [] ? '' : ' /Annots [ ' . \implode(' ', $ids) . ' ]';

        $pageId = $this->doc->allocateObjectId();
        $this->doc->writeObject($pageId, \sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents [ %d 0 R %d 0 R ]%s >>',
            $this->pagesId,
            $size->width,
            $size->height,
            $contentId,
            $footerId,
            $annotsRef,
        ));

        $this->pages[] = ['page' => $pageId, 'footer' => $footerId, 'size' => $size, 'annots' => $annots];
        $this->pageOpen = false;
        $this->content = '';
    }

    private function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        if ($this->pdfA && $this->pageNumbers !== null) {
            throw new \LogicException(
                'PDF/A-1b cannot use Standard-14 page numbers; PDF/A requires embedded fonts only.',
            );
        }

        if (!$this->pageOpen && $this->pages === []) {
            $this->addPage();
        }
        $this->commitPage();

        // Build and splice in the table-of-contents page(s) before anything reads
        // page positions, so footers, /Kids and links all number them uniformly.
        // (Rendered here, before font emission, so its TrueType glyphs are subset.)
        if ($this->pendingToc !== null) {
            $this->buildTocPages($this->pendingToc);
        }

        $total = \count($this->pages);

        // Deferred footers: TOTAL is now known, so numbers/credit align exactly.
        foreach ($this->pages as $i => $record) {
            $this->doc->writeStreamObject($record['footer'], $this->buildFooter($i + 1, $total, $record['size']));
        }

        // Link annotations (internal targets are resolvable now).
        foreach ($this->pages as $record) {
            foreach ($record['annots'] as $annotation) {
                $this->doc->writeObject($annotation['id'], $this->annotationBody($annotation, $total));
            }
        }

        // PDF/A-1b: every glyph must come from an embedded font.
        if ($this->pdfA) {
            foreach ($this->fonts->all() as $entry) {
                if ($entry['font']->kind() !== FontKind::TRUETYPE) {
                    throw new \LogicException(\sprintf(
                        'PDF/A-1b requires every font to be embedded; %s is not. '
                        . 'Use TrueTypeFont::fromFile() and setDefaultFont().',
                        $entry['font']::class,
                    ));
                }
            }
        }

        // Font objects (all faces are registered by now).
        $fontRefs = [];
        foreach ($this->fonts->all() as $entry) {
            $font = $entry['font'];
            $fontRefs[$entry['ref']] = match (true) {
                $font instanceof TrueTypeFont => $this->writeTrueTypeFont($font, $entry['tracker'] ?? new CharacterTracker()),
                $font instanceof CidFontJapanese => $this->writeCidFont($font),
                $font instanceof StandardFont => $this->writeStandardFont($font),
                default => throw new \LogicException('Unsupported font face: ' . $font::class),
            };
        }

        $fontDict = '';
        foreach ($fontRefs as $ref => $id) {
            $fontDict .= \sprintf('/%s %d 0 R ', $ref, $id);
        }

        $xobjectDict = '';
        foreach ($this->xobjects as $xobject) {
            $xobjectDict .= \sprintf('/%s %d 0 R ', $xobject['name'], $xobject['id']);
        }

        $shadingDict = '';
        foreach ($this->shadings as $shading) {
            $shadingDict .= \sprintf('/%s %d 0 R ', $shading['name'], $shading['id']);
        }

        $resourceParts = '';
        if ($fontDict !== '') {
            $resourceParts .= \sprintf('/Font << %s>> ', $fontDict);
        }
        if ($xobjectDict !== '') {
            $resourceParts .= \sprintf('/XObject << %s>> ', $xobjectDict);
        }
        if ($shadingDict !== '') {
            $resourceParts .= \sprintf('/Shading << %s>> ', $shadingDict);
        }
        $resources = $resourceParts === '' ? '<< >>' : \sprintf('<< %s>>', $resourceParts);

        $kids = \implode(' ', \array_map(
            static fn (array $p): string => "{$p['page']} 0 R",
            $this->pages,
        ));
        $this->doc->writeObject($this->pagesId, \sprintf(
            '<< /Type /Pages /Kids [ %s ] /Count %d /Resources %s >>',
            $kids,
            $total,
            $resources,
        ));

        $outlinesId = $this->writeOutline();
        $catalogExtra = $outlinesId !== null
            ? \sprintf(' /Outlines %d 0 R /PageMode /UseOutlines', $outlinesId)
            : '';

        if ($this->signature !== null) {
            $this->writeSignatureObjects();
            $catalogExtra .= \sprintf(' /AcroForm %d 0 R', $this->acroFormId);
        }

        if ($this->pdfA) {
            $catalogExtra .= $this->writePdfAObjects();
        }

        $this->doc->writeObject($this->catalogId, \sprintf(
            '<< /Type /Catalog /Pages %d 0 R%s >>',
            $this->pagesId,
            $catalogExtra,
        ));

        // The /Encrypt dictionary is itself never encrypted.
        $encryptId = null;
        $idHex = null;
        if ($this->security !== null) {
            $encryptId = $this->doc->allocateObjectId();
            $this->doc->writeObject($encryptId, $this->security->encryptionDictionary());
            $idHex = $this->security->idHex();
        } elseif ($this->pdfA) {
            $idHex = \bin2hex(\random_bytes(16)); // trailer /ID (required by PDF/A)
        }

        $infoId = $this->writeInfoDictionary();

        $this->doc->finalize($this->catalogId, $encryptId, $idHex, $infoId);
        $this->finalized = true;

        $this->logger->info('Finalized {pages} page(s), {bytes} bytes', [
            'pages' => $total,
            'bytes' => $this->writer->getOffset(),
        ]);
    }

    /**
     * Write the document information dictionary and return its object id. It
     * records the library version in `/Producer` (see {@see Version::producer()}),
     * so an output file alone reveals which build produced it. The `/Creator`
     * and the dates deliberately mirror the XMP packet (see {@see xmpMetadata()}),
     * as PDF/A-1 requires the two to agree for any property present in both.
     *
     * When the credit is hidden, `/Keywords` carries the same licence marker as
     * the invisible page text, so an audit can classify a file from its
     * metadata without parsing any content stream.
     */
    private function writeInfoDictionary(): int
    {
        $infoId = $this->doc->allocateObjectId();
        $date = $this->createdAt->format("\\D\\:YmdHis+00'00'");

        $keywords = $this->credit->enabled
            ? ''
            : \sprintf(' /Keywords %s', $this->pdfTextString($infoId, $this->credit->markerText()));

        $this->doc->writeObject($infoId, \sprintf(
            '<< /Producer %s /Creator %s /CreationDate %s /ModDate %s%s >>',
            $this->pdfTextString($infoId, Version::producer()),
            $this->pdfTextString($infoId, 'MejiroPDF'),
            $this->pdfTextString($infoId, $date),
            $this->pdfTextString($infoId, $date),
            $keywords,
        ));

        return $infoId;
    }

    /**
     * Emit a Standard 14 font object and return its id.
     */
    private function writeStandardFont(StandardFont $font): int
    {
        $id = $this->doc->allocateObjectId();
        $this->doc->writeObject($id, \sprintf(
            '<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>',
            $font->postScriptName(),
        ));

        return $id;
    }

    /**
     * Emit a non-embedded Japanese composite font (a /Type0 dictionary and its
     * descendant /CIDFontType0) and return the /Type0 object id.
     */
    private function writeCidFont(CidFontJapanese $font): int
    {
        $descendantId = $this->doc->allocateObjectId();
        $this->doc->writeObject($descendantId, \sprintf(
            '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /%s '
            . '/CIDSystemInfo << /Registry (%s) /Ordering (%s) /Supplement %d >> /DW %d >>',
            $font->postScriptName(),
            $font->registry(),
            $font->ordering(),
            $font->supplement(),
            $font->defaultWidth(),
        ));

        $type0Id = $this->doc->allocateObjectId();
        $this->doc->writeObject($type0Id, \sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /%s /DescendantFonts [ %d 0 R ] >>',
            $font->postScriptName(),
            $font->cmap(),
            $descendantId,
        ));

        return $type0Id;
    }

    /**
     * Embed a TrueType font as a /Type0 + descendant /CIDFontType2 (Identity-H),
     * with FontFile2, a FontDescriptor, a W widths array and a ToUnicode CMap
     * for the code points actually used. Returns the /Type0 object id.
     */
    private function writeTrueTypeFont(TrueTypeFont $font, CharacterTracker $tracker): int
    {
        // Map used code points to glyph ids (glyph id => a representative cp).
        $gidToCp = [];
        foreach ($tracker->codepoints() as $cp) {
            $gidToCp[$font->gidForCodepoint($cp)] = $cp;
        }
        \ksort($gidToCp);

        // Subset the font to the used glyphs, falling back to a full embed for
        // fonts that cannot be subset (e.g. CFF/OTF without a glyf table).
        $usedGids = \array_keys($gidToCp);
        try {
            $fontBytes = TrueTypeSubsetter::subset($font->rawBytes, $usedGids);
            $baseName = $this->subsetTag($usedGids) . '+' . $font->baseName;
        } catch (FontException) {
            $fontBytes = $font->rawBytes;
            $baseName = $font->baseName;
        }

        // FontFile2 — the (subset) font, Flate-compressed.
        $compressed = \gzcompress($fontBytes, 6);
        if ($compressed === false) {
            $compressed = $fontBytes;
        }
        $fontFileId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject(
            $fontFileId,
            $compressed,
            \sprintf('/Length1 %d /Filter /FlateDecode', \strlen($fontBytes)),
        );

        // CIDSet — a bitmap of the CIDs present in the subset (bit 0 = MSB of
        // byte 0 = CID 0). PDF/A-1b (ISO 19005-1, 6.3.5) requires it for subset
        // CIDFonts; CIDToGIDMap is Identity, so CID == GID.
        $maxGid = $gidToCp === [] ? 0 : \max(\array_keys($gidToCp));
        $cidSet = \array_fill(0, \intdiv($maxGid, 8) + 1, 0);
        $cidSet[0] |= 0x80; // CID 0 (.notdef) is always embedded
        foreach (\array_keys($gidToCp) as $gid) {
            $cidSet[$gid >> 3] |= 0x80 >> ($gid & 7);
        }
        $cidSetId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($cidSetId, \implode('', \array_map('chr', $cidSet)));

        // FontDescriptor.
        $descriptorId = $this->doc->allocateObjectId();
        $this->doc->writeObject($descriptorId, \sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] '
            . '/ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 '
            . '/FontFile2 %d 0 R /CIDSet %d 0 R >>',
            $baseName,
            $font->bbox1000[0],
            $font->bbox1000[1],
            $font->bbox1000[2],
            $font->bbox1000[3],
            $font->ascent1000,
            $font->descent1000,
            $font->capHeight1000,
            $fontFileId,
            $cidSetId,
        ));

        // Widths for the used glyphs.
        $widths = '';
        foreach ($gidToCp as $gid => $cp) {
            $widths .= $gid . ' [' . $font->advanceWidth1000($gid) . '] ';
        }

        $cidFontId = $this->doc->allocateObjectId();
        $this->doc->writeObject($cidFontId, \sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s '
            . '/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
            . '/FontDescriptor %d 0 R /CIDToGIDMap /Identity /DW %d /W [ %s] >>',
            $baseName,
            $descriptorId,
            $font->advanceWidth1000(0),
            $widths,
        ));

        // ToUnicode CMap — keeps the text searchable/copyable.
        $toUnicodeId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($toUnicodeId, $this->buildToUnicode($gidToCp));

        $type0Id = $this->doc->allocateObjectId();
        $this->doc->writeObject($type0Id, \sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H '
            . '/DescendantFonts [ %d 0 R ] /ToUnicode %d 0 R >>',
            $baseName,
            $cidFontId,
            $toUnicodeId,
        ));

        return $type0Id;
    }

    /**
     * A deterministic 6-uppercase-letter subset tag (PDF requires the
     * "ABCDEF+" prefix on the BaseFont of a subsetted font).
     *
     * @param list<int> $gids
     */
    private function subsetTag(array $gids): string
    {
        \sort($gids);
        $hash = \md5(\implode(',', $gids));

        $tag = '';
        for ($i = 0; $i < 6; $i++) {
            $tag .= \chr(65 + (\ord($hash[$i]) % 26));
        }

        return $tag;
    }

    /**
     * Build a ToUnicode CMap mapping glyph ids back to Unicode code points.
     *
     * @param array<int, int> $gidToCp glyph id => code point
     */
    private function buildToUnicode(array $gidToCp): string
    {
        $chunks = \array_chunk($gidToCp, 100, preserve_keys: true);

        $body = '';
        foreach ($chunks as $chunk) {
            $body .= \count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $cp) {
                $utf16 = \bin2hex(\mb_convert_encoding(\mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8'));
                $body .= \sprintf("<%04X> <%s>\n", $gid, \strtoupper($utf16));
            }
            $body .= "endbfchar\n";
        }

        return "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $body
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }

    private function buildFooter(int $physicalPage, int $total, PageSize $size): string
    {
        $ops = '';

        if ($this->credit->enabled) {
            $ops .= $this->creditOps($size);
        }

        if ($this->pageNumbers !== null && $this->pageNumbers->shouldRender($physicalPage)) {
            $ops .= $this->pageNumberOps($physicalPage, $total, $size);
        }

        return $ops;
    }

    /**
     * The font resource, encoder and coverage test furigana should use, as
     * trailing arguments for {@see Typesetter::render()}.
     *
     * Empty when the ruby rides the parent's face (the default), so the
     * typesetter falls back to the text font without a second lookup.
     *
     * @return array{}|array{string, callable(string): string, callable(string): bool}
     */
    private function rubyHandles(TypesetterOptions $options, FontFace $font): array
    {
        $ruby = $options->rubyStyle->font;
        if ($ruby === null || $ruby === $font) {
            return [];
        }

        return [
            $this->fonts->resourceName($ruby),
            fn (string $run): string => $this->showOperand($ruby, $run),
            static fn (string $run): bool => GlyphCoverage::covers($ruby, $run),
        ];
    }

    /**
     * The face a furigana run is actually set in: the configured ruby face when
     * it has the glyphs, otherwise the body face the typesetter falls back to.
     *
     * Resolved here as well as in the typesetter because the glyph has to be
     * registered against the face that will draw it — an embedded font subsets
     * only what it was told about, so tracking the wrong face renders blank.
     */
    private function rubyFace(TypesetterOptions $options, FontFace $font, string $rubyText): FontFace
    {
        $ruby = $options->rubyStyle->font;
        if ($ruby === null || $ruby === $font) {
            return $font;
        }

        return GlyphCoverage::covers($ruby, $rubyText) ? $ruby : $font;
    }

    private function creditOps(PageSize $size): string
    {
        return $this->creditTextOps(
            $size,
            $this->credit->text,
            \sprintf('%.3F g', $this->credit->gray),
        );
    }

    /**
     * The credit drawn in the page background colour so it cannot be seen.
     *
     * The command is emitted rather than skipped on purpose: the string stays
     * in the content stream, so a licence audit can extract it from a published
     * PDF (see CreditOptions). A background-coloured fill is used instead of
     * text render mode 3 precisely because ordinary extractors still pick it
     * up — invisible to a reader, plain to a parser.
     */
    private function creditMarkerOps(PageSize $size): string
    {
        return $this->creditTextOps(
            $size,
            $this->credit->markerText(),
            self::fillOp($this->credit->markerColor ?? '#FFFFFF'),
        );
    }

    /**
     * Shared placement for both the visible credit and the hidden marker: the
     * lower corner opposite the page number, so the two never collide.
     */
    private function creditTextOps(PageSize $size, string $text, string $colorOp): string
    {
        // PDF/A forbids non-embedded fonts, so route the credit through the
        // document's (embedded) default font instead of Standard-14 Helvetica.
        $font = $this->pdfA ? $this->defaultFont : StandardFont::HELVETICA;
        $ref = $this->fonts->resourceName($font);
        $metrics = $this->fonts->metrics($font);
        $width = $metrics->textWidth($text, $this->credit->fontSize);

        // Place the credit on the opposite side of the page number (LEFT by default).
        $align = $this->pageNumbers?->alignment->opposite() ?? Alignment::LEFT;
        $x = $this->alignX($align, $size->width, $width, PageSize::mm($this->credit->marginSide));
        $y = PageSize::mm($this->credit->marginBottom);

        // Register the glyphs so an embedded font subsets them in (and lists
        // their widths); without this the credit would render blank.
        $this->fonts->track($font, $text);

        return $this->textOps($ref, $this->credit->fontSize, $x, $y, $this->showOperand($font, $text), $colorOp);
    }

    /**
     * A non-stroking colour operator for a #RRGGBB string.
     */
    private static function fillOp(string $hex): string
    {
        $rgb = \sscanf($hex, '#%2x%2x%2x');
        $r = \is_array($rgb) ? (int) ($rgb[0] ?? 255) : 255;
        $g = \is_array($rgb) ? (int) ($rgb[1] ?? 255) : 255;
        $b = \is_array($rgb) ? (int) ($rgb[2] ?? 255) : 255;

        return \sprintf('%.3F %.3F %.3F rg', $r / 255.0, $g / 255.0, $b / 255.0);
    }

    private function pageNumberOps(int $physicalPage, int $total, PageSize $size): string
    {
        $opts = $this->pageNumbers;
        \assert($opts !== null);

        $ref = $this->fonts->resourceName($opts->font);
        $metrics = $this->fonts->metrics($opts->font);
        $text = $opts->resolve($physicalPage, $total);
        $width = $metrics->textWidth($text, $opts->fontSize);

        $x = $this->alignX($opts->alignment, $size->width, $width, PageSize::mm($opts->marginSide));
        $y = PageSize::mm($opts->marginBottom);
        $colorOp = $opts->color === null ? null : $this->hexToRg($opts->color);

        // Register the glyphs (a no-op unless the number font is embedded).
        $this->fonts->track($opts->font, $text);

        return $this->textOps($ref, $opts->fontSize, $x, $y, $this->showOperand($opts->font, $text), $colorOp);
    }

    /**
     * Compute the x anchor (pt) for aligned text of the given width within a page.
     */
    private function alignX(Alignment $align, float $pageWidthPt, float $textWidthPt, float $marginPt): float
    {
        return match ($align) {
            Alignment::LEFT => $marginPt,
            Alignment::RIGHT => $pageWidthPt - $marginPt - $textWidthPt,
            Alignment::CENTER => ($pageWidthPt - $textWidthPt) / 2.0,
        };
    }

    /**
     * The Tj operand for a string in the given font: a `<hex>` UTF-16BE string
     * for CIDFonts, or an escaped `(literal)` for Standard 14 fonts.
     */
    private function showOperand(FontFace $font, string $text): string
    {
        return match (true) {
            $font instanceof TrueTypeFont => $font->encodeToGidHex($text),
            $font instanceof CidFontJapanese => JapaneseTextEncoder::toCidHex($text),
            default => '(' . PdfEscaper::literal($text) . ')',
        };
    }

    /**
     * Build a `BT … Tj … ET` text-drawing operator block (all values in points).
     * $operand is an already-delimited PDF string, e.g. "(Hi)" or "<65E5>".
     */
    private function textOps(string $ref, float $sizePt, float $xPt, float $yPt, string $operand, ?string $colorOp): string
    {
        $ops = "q\n";
        if ($colorOp !== null) {
            $ops .= $colorOp . "\n";
        }
        $ops .= "BT\n";
        $ops .= \sprintf("/%s %.2F Tf\n", $ref, $sizePt);
        $ops .= \sprintf("%.2F %.2F Td\n", $xPt, $yPt);
        $ops .= $operand . " Tj\n";
        $ops .= "ET\nQ\n";

        return $ops;
    }

    private function assertNotFinalized(): void
    {
        if ($this->finalized) {
            throw new \LogicException('The document has already been finalized and cannot be modified.');
        }
    }

    /**
     * The /Rect (points, PDF space) for an annotation box given in millimetres.
     */
    private function annotRect(float $x, float $y, float $width, float $height): string
    {
        $this->ensurePage();
        $pageHeight = $this->pageHeightPt();

        return \sprintf(
            '%.2F %.2F %.2F %.2F',
            PageSize::mm($x),
            $pageHeight - PageSize::mm($y + $height),
            PageSize::mm($x + $width),
            $pageHeight - PageSize::mm($y),
        );
    }

    /**
     * @param array{id: int, kind: string, rect: string, url: string, target: int} $annotation
     */
    private function annotationBody(array $annotation, int $pageCount): string
    {
        if ($annotation['kind'] === 'uri') {
            $uri = $this->security !== null
                ? '<' . \bin2hex($this->security->encrypt($annotation['id'], $annotation['url'])) . '>'
                : '(' . PdfEscaper::literal($annotation['url']) . ')';

            return \sprintf(
                '<< /Type /Annot /Subtype /Link /Rect [ %s ] /Border [0 0 0] /A << /S /URI /URI %s >> >>',
                $annotation['rect'],
                $uri,
            );
        }

        $target = \min(\max(0, $this->mapIndex($annotation['target'])), $pageCount - 1);

        return \sprintf(
            '<< /Type /Annot /Subtype /Link /Rect [ %s ] /Border [0 0 0] /A << /S /GoTo /D [ %d 0 R /Fit ] >> >>',
            $annotation['rect'],
            $this->pages[$target]['page'],
        );
    }

    /**
     * Translate a page index recorded during the build (which does not count the
     * yet-to-be-inserted TOC pages) into its final position in {@see $pages}.
     */
    private function mapIndex(int $contentIndex): int
    {
        if ($this->tocPageCount === 0 || $contentIndex < $this->tocSlot) {
            return $contentIndex;
        }

        return $contentIndex + $this->tocPageCount;
    }

    /** Millimetre geometry shared by every TOC page. */
    private const float TOC_MARGIN_MM = 20.0;
    private const float TOC_ROW_STEP_MM = 7.0;
    private const float TOC_FIRST_ROW_MM = 42.0;  // first entry baseline, page 1 (below heading)
    private const float TOC_CONT_ROW_MM = 25.0;   // first entry baseline, continuation pages

    /**
     * Build the table-of-contents page(s) from the collected bookmarks and splice
     * them into {@see $pages} at {@see $tocSlot}. Entries flow onto as many pages
     * as needed; each has a dotted leader and is a clickable link to its target.
     *
     * @param array{heading: string, font: FontFace, fontSize: float} $toc
     */
    private function buildTocPages(array $toc): void
    {
        $size = $this->defaultSize;
        $bottomMm = $size->height / PageSize::MM_TO_PT - self::TOC_MARGIN_MM;
        $rowWidthMm = $size->width / PageSize::MM_TO_PT - 2 * self::TOC_MARGIN_MM;

        // Paginate: greedily lay entries out by baseline, breaking onto new pages.
        /** @var list<list<array{entry: array{title: string, level: int, page: int}, yMm: float}>> $chunks */
        $chunks = [[]];
        $y = self::TOC_FIRST_ROW_MM;
        foreach ($this->bookmarks as $entry) {
            if ($y > $bottomMm) {
                $chunks[] = [];
                $y = self::TOC_CONT_ROW_MM;
            }
            $chunks[\count($chunks) - 1][] = ['entry' => $entry, 'yMm' => $y];
            $y += self::TOC_ROW_STEP_MM;
        }

        $this->tocPageCount = \count($chunks); // set before rendering so mapIndex() is correct

        $records = [];
        foreach ($chunks as $ci => $rows) {
            $contentId = $this->doc->allocateObjectId();
            $footerId = $this->doc->allocateObjectId();

            $annots = [];
            foreach ($rows as $row) {
                $annots[] = [
                    'id' => $this->doc->allocateObjectId(),
                    'kind' => 'goto',
                    'rect' => $this->tocRect(self::TOC_MARGIN_MM, $row['yMm'] - 4.0, $rowWidthMm, 6.0),
                    'url' => '',
                    'target' => $row['entry']['page'],
                ];
            }

            $this->doc->writeStreamObject($contentId, $this->renderTocContent($toc, $ci === 0, $rows));

            $ids = \array_map(static fn (array $a): string => "{$a['id']} 0 R", $annots);
            $annotsRef = $ids === [] ? '' : ' /Annots [ ' . \implode(' ', $ids) . ' ]';
            $pageId = $this->doc->allocateObjectId();
            $this->doc->writeObject($pageId, \sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents [ %d 0 R %d 0 R ]%s >>',
                $this->pagesId,
                $size->width,
                $size->height,
                $contentId,
                $footerId,
                $annotsRef,
            ));

            $records[] = ['page' => $pageId, 'footer' => $footerId, 'size' => $size, 'annots' => $annots];
        }

        \array_splice($this->pages, $this->tocSlot, 0, $records);
        $this->pendingToc = null;
    }

    /**
     * Draw one TOC page: an optional heading plus a title / dotted leader / page
     * number for each row.
     *
     * @param array{heading: string, font: FontFace, fontSize: float}           $toc
     * @param list<array{entry: array{title: string, level: int, page: int}, yMm: float}> $rows
     */
    private function renderTocContent(array $toc, bool $withHeading, array $rows): string
    {
        $size = $this->defaultSize;
        $font = $toc['font'];
        $fontSize = $toc['fontSize'];
        $ref = $this->fonts->resourceName($font);
        $metrics = FontMetrics::for($font);

        $ops = '';
        if ($withHeading) {
            $this->fonts->track($font, $toc['heading']);
            $ops .= $this->textOps(
                $ref,
                $fontSize * 1.6,
                PageSize::mm(self::TOC_MARGIN_MM),
                $size->height - PageSize::mm(25.0),
                $this->showOperand($font, $toc['heading']),
                null,
            );
        }

        foreach ($rows as $row) {
            $entry = $row['entry'];
            $number = (string) ($this->mapIndex($entry['page']) + 1);
            $this->fonts->track($font, $entry['title'] . $number);

            $yPt = $size->height - PageSize::mm($row['yMm']);
            $titleXpt = PageSize::mm(self::TOC_MARGIN_MM + $entry['level'] * 6.0);
            $ops .= $this->textOps($ref, $fontSize, $titleXpt, $yPt, $this->showOperand($font, $entry['title']), null);

            $numberWidth = $metrics->textWidth($number, $fontSize);
            $numberXpt = $size->width - PageSize::mm(self::TOC_MARGIN_MM) - $numberWidth;
            $ops .= $this->textOps($ref, $fontSize, $numberXpt, $yPt, $this->showOperand($font, $number), null);

            // Dotted leader between the title and the page number.
            $leaderStart = $titleXpt + $metrics->textWidth($entry['title'], $fontSize) + PageSize::mm(2.0);
            $leaderEnd = $numberXpt - PageSize::mm(2.0);
            if ($leaderEnd - $leaderStart > PageSize::mm(4.0)) {
                $ops .= \sprintf(
                    "q 0.6 g 0.8 w 1 J [0.4 2.4] 0 d\n%.2F %.2F m %.2F %.2F l S\nQ\n",
                    $leaderStart,
                    $yPt + 1.5,
                    $leaderEnd,
                    $yPt + 1.5,
                );
            }
        }

        return $ops;
    }

    /**
     * Rectangle string (PDF coordinates) for a TOC row, given top-left millimetre
     * coordinates. Standalone version of {@see annotRect} usable without an open page.
     */
    private function tocRect(float $xMm, float $yTopMm, float $widthMm, float $heightMm): string
    {
        $pageHeight = $this->defaultSize->height;

        return \sprintf(
            '%.2F %.2F %.2F %.2F',
            PageSize::mm($xMm),
            $pageHeight - PageSize::mm($yTopMm + $heightMm),
            PageSize::mm($xMm + $widthMm),
            $pageHeight - PageSize::mm($yTopMm),
        );
    }

    /**
     * Build the document outline (bookmarks) as a tree of PDF objects.
     *
     * @return int|null The /Outlines object id, or null when there are no bookmarks.
     */
    private function writeOutline(): ?int
    {
        if ($this->bookmarks === []) {
            return null;
        }

        /** @var list<array{title: string, page: int, parent: int|null, children: list<int>, id: int}> $items */
        $items = [];
        /** @var array<int, int> $lastAtLevel */
        $lastAtLevel = [];
        foreach ($this->bookmarks as $entry) {
            $index = \count($items);
            $parent = null;
            for ($level = $entry['level'] - 1; $level >= 0; $level--) {
                if (isset($lastAtLevel[$level])) {
                    $parent = $lastAtLevel[$level];
                    break;
                }
            }
            $items[] = ['title' => $entry['title'], 'page' => $entry['page'], 'parent' => $parent, 'children' => [], 'id' => 0];
            if ($parent !== null) {
                $items[$parent]['children'][] = $index;
            }
            $lastAtLevel[$entry['level']] = $index;
            foreach (\array_keys($lastAtLevel) as $existing) {
                if ($existing > $entry['level']) {
                    unset($lastAtLevel[$existing]);
                }
            }
        }

        $outlinesId = $this->doc->allocateObjectId();
        foreach ($items as $i => $item) {
            $items[$i]['id'] = $this->doc->allocateObjectId();
        }

        /** @var list<int> $topLevel */
        $topLevel = [];
        foreach ($items as $i => $item) {
            if ($item['parent'] === null) {
                $topLevel[] = $i;
            }
        }

        $pageCount = \count($this->pages);
        foreach ($items as $index => $item) {
            $siblings = $item['parent'] === null ? $topLevel : $items[$item['parent']]['children'];
            $position = \array_search($index, $siblings, true);
            $position = $position === false ? 0 : $position;

            $parentId = $item['parent'] === null ? $outlinesId : $items[$item['parent']]['id'];
            $pageIndex = \min(\max(0, $this->mapIndex($item['page'])), $pageCount - 1);

            $body = \sprintf(
                '<< /Title %s /Parent %d 0 R /Dest [ %d 0 R /Fit ]',
                $this->pdfTextString($item['id'], $item['title']),
                $parentId,
                $this->pages[$pageIndex]['page'],
            );
            if ($position > 0) {
                $body .= \sprintf(' /Prev %d 0 R', $items[$siblings[$position - 1]]['id']);
            }
            if ($position < \count($siblings) - 1) {
                $body .= \sprintf(' /Next %d 0 R', $items[$siblings[$position + 1]]['id']);
            }
            if ($item['children'] !== []) {
                $body .= \sprintf(
                    ' /First %d 0 R /Last %d 0 R /Count %d',
                    $items[$item['children'][0]]['id'],
                    $items[$item['children'][\count($item['children']) - 1]]['id'],
                    $this->countDescendants($items, $index),
                );
            }
            $body .= ' >>';
            $this->doc->writeObject($item['id'], $body);
        }

        $root = '<< /Type /Outlines';
        if ($topLevel !== []) {
            $root .= \sprintf(' /First %d 0 R /Last %d 0 R', $items[$topLevel[0]]['id'], $items[$topLevel[\count($topLevel) - 1]]['id']);
        }
        $root .= \sprintf(' /Count %d >>', \count($items));
        $this->doc->writeObject($outlinesId, $root);

        return $outlinesId;
    }

    /**
     * @param list<array{title: string, page: int, parent: int|null, children: list<int>, id: int}> $items
     */
    private function countDescendants(array $items, int $index): int
    {
        $count = \count($items[$index]['children']);
        foreach ($items[$index]['children'] as $child) {
            $count += $this->countDescendants($items, $child);
        }

        return $count;
    }

    /**
     * Encode a string as a PDF text string for object $objectId: a literal for
     * ASCII, a BOM-prefixed UTF-16BE hex string for anything else, or an
     * encrypted hex string when the document is encrypted.
     */
    private function pdfTextString(int $objectId, string $value): string
    {
        $multibyte = JapaneseTextEncoder::isMultibyte($value);

        if ($this->security !== null) {
            $raw = $multibyte ? "\xFE\xFF" . \mb_convert_encoding($value, 'UTF-16BE', 'UTF-8') : $value;

            return '<' . \bin2hex($this->security->encrypt($objectId, $raw)) . '>';
        }

        return $multibyte
            ? JapaneseTextEncoder::toPdfHex($value)
            : '(' . PdfEscaper::literal($value) . ')';
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function parseBox(string $bbox): array
    {
        $parts = \preg_split('/\s+/', \trim($bbox)) ?: [];

        return [
            (float) ($parts[0] ?? 0),
            (float) ($parts[1] ?? 0),
            (float) ($parts[2] ?? 0),
            (float) ($parts[3] ?? 0),
        ];
    }

    /**
     * Rewrite "N G R" indirect references according to a source→new id map.
     *
     * @param array<int, int> $remap
     */
    private function remapReferences(string $text, array $remap): string
    {
        return \preg_replace_callback(
            '/(\d+)(\s+\d+\s+R)/',
            static function (array $m) use ($remap): string {
                $number = (int) $m[1];

                return isset($remap[$number]) ? $remap[$number] . $m[2] : $m[0];
            },
            $text,
        ) ?? $text;
    }

    /**
     * Write the signature field widget, AcroForm and /Sig dictionary (the last
     * with ByteRange/Contents placeholders that {@see applySignature} fills in).
     */
    private function writeSignatureObjects(): void
    {
        $signature = $this->signature;
        \assert($signature !== null);
        $firstPageId = $this->pages[0]['page'] ?? 0;

        $this->doc->writeObject($this->sigWidgetId, \sprintf(
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (Signature1) /Rect [ 0 0 0 0 ] /V %d 0 R /P %d 0 R /F 132 >>',
            $this->sigDictId,
            $firstPageId,
        ));

        $this->doc->writeObject($this->acroFormId, \sprintf(
            '<< /Fields [ %d 0 R ] /SigFlags 3 >>',
            $this->sigWidgetId,
        ));

        $meta = \sprintf('/Name (%s) /M (%s)', PdfEscaper::literal($signature->name), $this->pdfDate());
        if ($signature->reason !== null) {
            $meta .= \sprintf(' /Reason (%s)', PdfEscaper::literal($signature->reason));
        }
        if ($signature->location !== null) {
            $meta .= \sprintf(' /Location (%s)', PdfEscaper::literal($signature->location));
        }
        if ($signature->contactInfo !== null) {
            $meta .= \sprintf(' /ContactInfo (%s)', PdfEscaper::literal($signature->contactInfo));
        }

        $placeholder = \str_repeat('0', $signature->reservedBytes * 2);
        $this->doc->writeObject($this->sigDictId, \sprintf(
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached %s '
            . '/ByteRange [0 0000000000 0000000000 0000000000] /Contents <%s> >>',
            $meta,
            $placeholder,
        ));
    }

    private function pdfDate(): string
    {
        return 'D:' . \gmdate('YmdHis') . 'Z';
    }

    /**
     * Compute the /ByteRange, sign the covered bytes, and patch the /Contents
     * placeholder with the detached PKCS#7 signature.
     */
    private function applySignature(string $bytes): string
    {
        $signature = $this->signature;
        \assert($signature !== null);

        $reservedHex = $signature->reservedBytes * 2;
        $pos = \strpos($bytes, '/Contents <');
        if ($pos === false) {
            throw SecurityException::encryptionFailed('Signature placeholder not found.');
        }
        $ltPos = $pos + \strlen('/Contents '); // byte offset of '<'
        $gtPos = $ltPos + 1 + $reservedHex;    // byte offset of '>'
        $fileLength = \strlen($bytes);

        // Fill the ByteRange in place (same byte length as its placeholder).
        $byteRange = \sprintf('[0 %010d %010d %010d]', $ltPos + 1, $gtPos, $fileLength - $gtPos);
        $bytes = \str_replace('[0 0000000000 0000000000 0000000000]', $byteRange, $bytes);

        // Sign everything except the Contents hex string.
        $signedData = \substr($bytes, 0, $ltPos + 1) . \substr($bytes, $gtPos, $fileLength - $gtPos);
        $der = Signer::sign($signedData, $signature);
        if (\strlen($der) > $signature->reservedBytes) {
            throw SecurityException::encryptionFailed(\sprintf(
                'Signature (%d bytes) exceeds the %d reserved; raise reservedBytes.',
                \strlen($der),
                $signature->reservedBytes,
            ));
        }

        $hex = \str_pad(\bin2hex($der), $reservedHex, '0');

        return \substr($bytes, 0, $ltPos + 1) . $hex . \substr($bytes, $gtPos);
    }

    /**
     * Emit the PDF/A-1b support objects — an sRGB OutputIntent (with an embedded
     * ICC profile) and an XMP metadata stream carrying the pdfaid identifier —
     * and return the catalog entries that reference them.
     */
    private function writePdfAObjects(): string
    {
        $icc = @\file_get_contents(__DIR__ . '/Data/sRGB.icc');
        if ($icc === false) {
            throw new \RuntimeException('Bundled sRGB ICC profile is missing.');
        }

        $iccId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($iccId, $icc, '/N 3');

        $metaId = $this->doc->allocateObjectId();
        $this->doc->writeStreamObject($metaId, $this->xmpMetadata(), '/Type /Metadata /Subtype /XML');

        return \sprintf(
            ' /Metadata %d 0 R /OutputIntents [ << /Type /OutputIntent /S /GTS_PDFA1 '
            . '/OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) '
            . '/DestOutputProfile %d 0 R >> ]',
            $metaId,
            $iccId,
        );
    }

    /**
     * Build the XMP packet declaring PDF/A-1B conformance (ISO 19005-1).
     */
    private function xmpMetadata(): string
    {
        $now = $this->createdAt->format('Y-m-d\TH:i:s\Z');
        $producer = Version::producer();

        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . "<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">\n"
            . " <rdf:RDF xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\">\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:pdfaid=\"http://www.aiim.org/pdfa/ns/id/\">\n"
            . "   <pdfaid:part>1</pdfaid:part>\n"
            . "   <pdfaid:conformance>B</pdfaid:conformance>\n"
            . "  </rdf:Description>\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\">\n"
            . "   <dc:format>application/pdf</dc:format>\n"
            . "  </rdf:Description>\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">\n"
            . "   <xmp:CreatorTool>MejiroPDF</xmp:CreatorTool>\n"
            . "   <xmp:CreateDate>{$now}</xmp:CreateDate>\n"
            . "   <xmp:ModifyDate>{$now}</xmp:ModifyDate>\n"
            . "  </rdf:Description>\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\">\n"
            . "   <pdf:Producer>{$producer}</pdf:Producer>\n"
            . "  </rdf:Description>\n"
            . " </rdf:RDF>\n"
            . "</x:xmpmeta>\n"
            . '<?xpacket end="w"?>';
    }

    private function hexToRg(string $hex, bool $stroke = false): string
    {
        return $this->hexToRgb($hex) . ' ' . ($stroke ? 'RG' : 'rg');
    }

    /**
     * Convert "#RRGGBB" to three space-separated 0..1 components.
     */
    private function hexToRgb(string $hex): string
    {
        $hex = \ltrim($hex, '#');
        if (\strlen($hex) !== 6 || !\ctype_xdigit($hex)) {
            throw new \InvalidArgumentException("Invalid hex colour: #{$hex}");
        }

        return \sprintf(
            '%.3F %.3F %.3F',
            \hexdec(\substr($hex, 0, 2)) / 255.0,
            \hexdec(\substr($hex, 2, 2)) / 255.0,
            \hexdec(\substr($hex, 4, 2)) / 255.0,
        );
    }

    /**
     * Build a path-drawing block: graphics state, weight/colours, the path and
     * the paint operator (B fills+strokes, f fills, S strokes).
     */
    private function pathBlock(string $path, float $weight, ?string $stroke, ?string $fill): string
    {
        $ops = "q\n" . \sprintf("%.2F w\n", PageSize::mm($weight));
        if ($stroke !== null) {
            $ops .= $this->hexToRg($stroke, stroke: true) . "\n";
        }
        if ($fill !== null) {
            $ops .= $this->hexToRg($fill) . "\n";
        }
        $ops .= $path;
        $ops .= match (true) {
            $fill !== null && $stroke !== null => "B\n",
            $fill !== null => "f\n",
            default => "S\n",
        };

        return $ops . "Q\n";
    }

    /**
     * Write a shading object, register it for the shared /Resources, and return
     * its resource name.
     */
    private function registerShading(string $shadingDict): string
    {
        $id = $this->doc->allocateObjectId();
        $this->doc->writeObject($id, $shadingDict);
        $name = 'Sh' . \count($this->shadings);
        $this->shadings[] = ['name' => $name, 'id' => $id];

        return $name;
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            \fclose($this->stream);
        }
    }
}
