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
use MejiroPDF\Font\VerticalCidFont;
use MejiroPDF\Font\TrueTypeSubsetter;
use MejiroPDF\Image\Image;
use MejiroPDF\Image\RasterImage;
use MejiroPDF\Layout\Anchor;
use MejiroPDF\Layout\BorderBox;
use MejiroPDF\Layout\BorderStyle;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Layout\GridColumn;
use MejiroPDF\Layout\GridTable;
use MejiroPDF\Layout\LineStyle;
use MejiroPDF\Layout\Section;
use MejiroPDF\Import\ImportedPage;
use MejiroPDF\Import\PdfImportException;
use MejiroPDF\Import\PdfPageImporter;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\BindingEdge;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\DocumentInfo;
use MejiroPDF\Page\NumberStyle;
use MejiroPDF\Page\OpenFit;
use MejiroPDF\Page\ReadingDirection;
use MejiroPDF\Page\RunningHeadLevel;
use MejiroPDF\Page\RunningHeadOptions;
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
use MejiroPDF\Text\FootnoteStyle;
use MejiroPDF\Text\FormattedLine;
use MejiroPDF\Text\LineBreaker;
use MejiroPDF\Text\Pagination;
use MejiroPDF\Text\PdfEscaper;
use MejiroPDF\Text\SmartPunctuation;
use MejiroPDF\Text\TextToken;
use MejiroPDF\Text\TextTokenizer;
use MejiroPDF\Text\Typesetter;
use MejiroPDF\Text\TypesetterOptions;
use MejiroPDF\Text\VerticalFaces;
use MejiroPDF\Text\TypesetResult;
use MejiroPDF\Text\WritingMode;
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

    /** The /ByteRange a signature dictionary carries until the file is complete. */
    private const string SIG_BYTE_RANGE_PLACEHOLDER = '[0 0000000000 0000000000 0000000000]';

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
    private ?RunningHeadOptions $runningHead = null;

    /** @var array<string, int> Descendant CIDFont object per PostScript name, shared by the H and V parents. */
    private array $cidDescendants = [];

    /** Set once anything is typeset vertically; {@see ReadingDirection::AUTO} reads it. */
    private bool $vertical = false;

    /**
     * Page-numbering sequences by the page they start on, in order. Kept as
     * ranges rather than per page, for the same reason as the running heads:
     * a book has a handful of sequences and a great many pages.
     *
     * @var list<array{from: int, style: NumberStyle, start: int}>
     */
    private array $numberingRestarts = [];

    /** Footnotes numbered so far, so a second flow continues the sequence. */
    private int $footnoteCount = 0;

    /**
     * Running heads by the page they start on. Kept as ranges rather than
     * per page: a book has few chapters and many pages.
     *
     * The face travels with the words. A running head carries the author's
     * own text, and the document's default face is very often Helvetica while
     * the book is set in something that can spell it.
     *
     * @var list<array{from: int, text: string, font: FontFace|null}>
     */
    private array $runningHeads = [];

    /**
     * Subheadings by the page they fall on, the recto's half of a spread.
     * Same shape and same reasoning as {@see $runningHeads}.
     *
     * @var list<array{from: int, text: string, font: FontFace|null}>
     */
    private array $runningSections = [];

    private ?PageSize $currentSize = null;

    /** The open page's /Rotate, in degrees clockwise: 0, 90, 180 or 270. */
    private int $pageRotation = 0;

    /** How many {@see rotated} groups are open around the drawing calls. */
    private int $rotationDepth = 0;

    private string $content = '';

    /** Length of {@see $content} when the current page was still blank. */
    private int $blankContentLength = 0;
    private bool $pageOpen = false;
    private bool $finalized = false;

    /**
     * Where the last flow left off on the page that is still open, so a chapter
     * asked to run on can start from there instead of from the region's edge.
     *
     * The position is the planner's own coordinate — signed for vertical text,
     * as {@see FlowRegion::planStart()} gives it — which is why the mode is kept
     * with it: a value from a flow set the other way round means nothing here.
     * It is read only when `page` is still the open page, so anything that turns
     * the leaf discards it without having to say so.
     *
     * @var array{page: int, end: float, mode: WritingMode}|null
     */
    private ?array $flowCursor = null;

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
     * Byte offsets, in the finished file, of the /Sig dictionary's /ByteRange
     * placeholder and of the `<` opening its /Contents placeholder. Recorded
     * as they are written, because searching for them afterwards finds the
     * first look-alike instead — and page text is free to contain one.
     */
    private int $sigByteRangeOffset = -1;
    private int $sigContentsOffset = -1;

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
        private DocumentInfo $info = new DocumentInfo(),
        private readonly ReadingDirection $direction = ReadingDirection::AUTO,
        private readonly OpenFit $openFit = OpenFit::VIEWER_DEFAULT,
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

    /**
     * Replace the document's title, author and the rest, up until output.
     *
     * The constructor is the usual place, but a compiled template carries its
     * own — and the template is not known until it is loaded, which is after
     * the document exists. Values may hold `{{ field }}` there, so they are
     * only settled once the record's data is in hand.
     */
    public function setDocumentInfo(DocumentInfo $info): self
    {
        $this->assertNotFinalized();
        $this->info = $info;

        return $this;
    }

    /**
     * Repeat each chapter's name at the top of its pages. The text comes from
     * the {@see Section}s themselves; this decides how it is drawn.
     */
    public function setRunningHead(RunningHeadOptions $options): self
    {
        $this->assertNotFinalized();
        $this->runningHead = $options;

        return $this;
    }

    /**
     * Begin a new page-numbering sequence at the next page: the front matter
     * runs i, ii, iii and the body starts again at 1, so material can be added
     * to either without renumbering the other.
     *
     * Pages before the first call keep the sequence
     * {@see PageNumberOptions} describes. `{TOTAL}` counts the pages of the
     * sequence a page belongs to, not the document — the only reading that
     * makes sense once there is more than one.
     */
    public function restartPageNumbering(NumberStyle $style, int $from = 1): self
    {
        $this->assertNotFinalized();
        $this->numberingRestarts[] = [
            // The sequence begins with the *next* page opened — the call sits
            // between the front matter and the body, not inside either.
            // pageCount() counts the open page, which count($this->pages) does
            // not: a page joins that list only when the next one commits it.
            'from' => $this->pageCount() + 1,
            'style' => $style,
            'start' => $from,
        ];

        return $this;
    }

    public function setPageNumbers(PageNumberOptions $options): self
    {
        $this->pageNumbers = $options;

        return $this;
    }

    /**
     * Open a new page, optionally of a different size and turned for display.
     *
     * $rotation is written to the page's /Rotate: an instruction to the viewer
     * and the printer to turn the finished sheet clockwise by that many degrees.
     * It is how a document made of portrait sheets carries one wide page — laid
     * out landscape, presented turned — without the reader's page width jumping
     * about. It does **not** move the drawing origin: every coordinate below is
     * still measured on the untuned sheet, exactly as on any other page. To turn
     * the marks rather than the paper, use {@see rotated}.
     *
     * Must be a multiple of 90; other values are refused rather than rounded,
     * because a viewer would ignore them and the page would silently come out
     * upright.
     */
    public function addPage(?PageSize $size = null, int $rotation = 0): self
    {
        $this->assertNotFinalized();

        if ($rotation % 90 !== 0) {
            throw new \InvalidArgumentException(
                \sprintf('Page rotation must be a multiple of 90 degrees; %d given.', $rotation),
            );
        }
        if ($this->rotationDepth > 0) {
            throw new \LogicException('A page cannot be opened inside rotated(); close the group first.');
        }

        // Counted before the page opens, so the limit is the number of pages
        // that exist rather than the number that were attempted.
        if ($this->maxPageLimit > 0 && $this->pageCount() >= $this->maxPageLimit) {
            throw DocumentException::pageLimitExceeded($this->maxPageLimit);
        }

        $this->commitPage();

        $this->currentSize = $size ?? $this->defaultSize;
        $this->pageRotation = ($rotation % 360 + 360) % 360;
        $this->content = '';
        $this->pageOpen = true;

        // Hidden credit: emit the marker first so it lands on the back layer.
        // PDF has no z-index — the order in the content stream is the order of
        // painting — so anything drawn afterwards covers it. The visible credit
        // takes the opposite route: it goes into the footer stream, which the
        // page lists after the body (see buildFooter).
        if (!$this->credit->enabled) {
            $this->content .= $this->creditMarkerOps($this->currentSize, \count($this->pages) + 1);
        }
        // Anything beyond this point is the page's own content, which is how
        // section() can tell a fresh page from one already written on.
        $this->blankContentLength = \strlen($this->content);

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
     * Flow multi-paragraph text through a region and **auto-paginate**: lines
     * stack from the region's start edge, and when the next one would cross its
     * limit a new page is started and the text continues. Paragraphs are kept
     * off page edges per {@see TypesetterOptions::$minLinesAtPageBreak}
     * (widow/orphan control).
     *
     * Works in either direction — `vertical-rl` stacks columns leftward from
     * the region's right edge — because {@see FlowRegion} holds the mapping
     * from the text's own axes onto the page's.
     *
     * Returns the block-axis coordinate (mm) just past the last line on the
     * final page: below it when horizontal, left of it when vertical.
     */
    public function flowInto(
        FlowRegion $region,
        string $text,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
        float $gutter = 0.0,
        ?FootnoteStyle $footnotes = null,
    ): float {
        return $this->renderFlow($region, $text, $font, $size, $options, $gutter, 0.0, $footnotes);
    }

    /**
     * Flow multi-paragraph text and auto-paginate, addressed by four scalars
     * instead of a {@see FlowRegion} — kept because it is the older spelling.
     *
     * The arguments follow the reading flow, exactly as {@see typeset()} does:
     * horizontally (x, $topY) is the top-left corner, $width is the line width
     * and $bottomY is the lowest the text may reach; vertically (x, $topY) is
     * the top-**right** corner, $width is the column height and $bottomY is the
     * **leftmost x** the columns may reach. Prefer {@see flowInto()} when
     * setting vertically — `FlowRegion::vertical()` names its arguments and
     * this signature cannot.
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
        $mode = ($options ?? new TypesetterOptions())->writingMode;

        return $this->renderFlow(
            FlowRegion::for($mode, $x, $topY, $width, $bottomY),
            $text,
            $font,
            $size,
            $options,
        );
    }

    /**
     * The one implementation behind {@see flowInto()} and {@see flowText()}.
     *
     * Nothing here branches on writing mode: line breaking measures along the
     * inline axis, {@see Typesetter::lineHeight()} measures along the block
     * axis, and {@see Pagination} plans in block-axis **offsets**. Only the
     * region knows which page coordinate an offset lands on.
     */
    private function renderFlow(
        FlowRegion $region,
        string $text,
        ?FontFace $font,
        float $size,
        ?TypesetterOptions $options,
        float $gutter = 0.0,
        float $firstPageInset = 0.0,
        ?FootnoteStyle $footnotes = null,
    ): float {
        $this->ensurePage();
        $font ??= $this->defaultFont;
        $options ??= new TypesetterOptions();
        $this->vertical = $this->vertical || $options->writingMode->isVertical();
        if ($options->writingMode !== $region->writingMode) {
            throw new \LogicException('The flow region and the typesetter options disagree about the writing mode.');
        }

        $tokens = TextTokenizer::tokenize($text);
        if ($footnotes !== null && ($footnotes->reserve === null || $footnotes->reserve > 0.0)) {
            $tokens = $this->numberFootnotes($tokens, $footnotes, $size);
        } else {
            $tokens = self::literalFootnotes($tokens);
        }
        if ($options->enableSmartQuotes) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken(SmartPunctuation::apply($t->text), $t->rubyText, $t->style, $t->footnote),
                $tokens,
            );
        }

        if ($region->columns > 1 && $footnotes !== null && !$footnotes->inMargin()) {
            // The band belongs to the page, not to a column, so its depth can
            // only be settled once every column of the page has been filled —
            // and the columns already drawn would have to be laid out again
            // against it. Refusing is better than drawing one band per column
            // on top of another. Margin notes have no such trouble: they sit
            // outside the region and take nothing from it.
            throw new \LogicException('Footnotes in a multi-column flow are not supported yet.');
        }
        if ($footnotes !== null && $footnotes->inMargin() && $region->isVertical()) {
            // Vertical text stacks its columns across the very margin a side
            // note would occupy, so where the note should go is undecided
            // rather than merely unimplemented.
            throw new \LogicException('Margin notes are supported in horizontal writing only.');
        }

        $breaker = new LineBreaker(FontMetrics::for($font), $size, $options, $options->enableHyphenation ? $this->hyphenator() : null);
        $typesetter = new Typesetter($options, $this->verticalFaces($options, $font));
        // The region signs the block axis so the planner always sees it
        // increasing; it never learns that vertical text runs leftward.
        $paginator = new Pagination(
            $region->planStart(),
            $region->planLimit(),
            $options->minLinesAtPageBreak,
            // A heading already standing at the top of the first page pushes
            // the body down; the pages after it start where they always would.
            $firstPageInset > 0.0 ? $region->planStart() + $firstPageInset : null,
        );

        // Resolved once, before any glyph is tracked, so font resources are
        // numbered in the order they were before this flow became incremental.
        $resource = $this->fonts->resourceName($font);
        $encode = fn (string $run): string => $this->showOperand($font, $run);

        // Resolved once per page: the band a footnote needs is either stated
        // or worked out by laying the page out again (see below).
        // A margin note is drawn outside the region, so it keeps no band and
        // never sends a page back to be laid out again.
        $stated = $footnotes !== null && $footnotes->inMargin() ? 0.0 : $footnotes?->reserve;
        $automatic = $footnotes !== null && $stated === null;

        // Paragraphs are pulled as the pages ask for them and held only while
        // the page they touch is still being decided, so memory follows the
        // size of a page rather than the length of the book.
        $paragraphs = $breaker->paragraphs($tokens, PageSize::mm($region->columnExtent()));
        /** @var list<array{lines: list<FormattedLine>, heights: list<float>, keepWithNext: bool}> $queue */
        $queue = [];
        $pull = function () use ($paragraphs, &$queue, $typesetter, $size, $font, $options): bool {
            if (!$paragraphs->valid()) {
                return false;
            }
            $paragraph = $paragraphs->current();
            $paragraphs->next();

            $heights = [];
            foreach ($paragraph as $line) {
                // Track every rendered glyph (incl. ruby) so an embedded font
                // subsets them in — otherwise they would render blank.
                foreach ($line->units as $unit) {
                    $this->fonts->track($font, $unit->text);
                    if ($unit->rubyText !== null) {
                        $this->fonts->track($this->rubyFace($options, $font, $unit->rubyText), $unit->rubyText);
                    }
                }
                $heights[] = $typesetter->lineHeight($line, $size) / PageSize::MM_TO_PT;
            }
            // A subheading must not end a page: noted here so the page being
            // filled can check what follows it.
            $keep = false;
            foreach ($paragraph as $line) {
                foreach ($line->units as $unit) {
                    $keep = $keep || $unit->style->keepWithNext;
                }
            }
            $queue[] = ['lines' => $paragraph, 'heights' => $heights, 'keepWithNext' => $keep];

            return true;
        };

        // The planner still fills one block after another; what a block *is*
        // becomes a column rather than a page. Slot n lands on page n div
        // columns, column n mod columns — so a column break is a page break one
        // step shallower, and the planner never learns the difference.
        $columns = $region->columns;
        $slot = 0;
        $pageCursor = $firstPageInset > 0.0 ? $region->planStart() + $firstPageInset : $region->planStart();
        $currentPage = 0;
        $lastEnd = null;

        while ($queue !== [] || $pull()) {
            $reserve = $stated ?? 0.0;
            // Set when a subheading turns out to end the page: the page is
            // filled again, stopping short of it. -1 means no such limit.
            /** @var int $stopBefore */
            $stopBefore = -1;

            // Filling the page can change what it owes: a line carrying a note
            // may be the one that no longer fits once the note's room is taken
            // out. Laying it out again converges because the band only grows.
            for ($attempt = 0; ; $attempt++) {
                $paginator->startPage($slot, $pageCursor);
                $paginator->setBottom($region->planLimit() - $reserve);

                /** @var list<FormattedLine> $lines */
                $lines = [];
                /** @var list<array{0: string, 1: string, 2: float}> $notes */
                $notes = [];
                $retry = false;
                $startAt = null;
                $endAt = 0.0;
                $whole = 0;
                $split = null;

                for ($index = 0; ; $index++) {
                    if ($stopBefore >= 0 && $index >= $stopBefore) {
                        break;
                    }
                    if (!isset($queue[$index]) && !$pull()) {
                        break;
                    }
                    $item = $queue[$index];
                    $places = $paginator->place($item['heights']);

                    $taken = 0;
                    foreach ($places as $place) {
                        if ($place['page'] !== $slot) {
                            break;
                        }
                        $startAt ??= $place['y'];
                        $endAt = $place['y'] + $item['heights'][$taken];
                        foreach ($item['lines'][$taken]->units as $unit) {
                            if ($unit->footnote !== null) {
                                $notes[] = [$unit->text, $unit->footnote, $place['y']];
                            }
                        }
                        $lines[] = $item['lines'][$taken];
                        $taken++;
                    }

                    // A heading holds on to the opening of what follows it. Too
                    // little of that on this page and the heading goes over the
                    // leaf as well — unless it opens the page, where there is
                    // nowhere earlier to put it.
                    if ($index > 1
                        && $queue[$index - 1]['keepWithNext']
                        && $taken < \min($options->keepWithNextLines, \count($places))
                        && $stopBefore < 0
                    ) {
                        $stopBefore = $index - 1;
                        $retry = true;
                        break;
                    }

                    if ($taken < \count($places)) {
                        $split = [$index, $taken];
                        break;
                    }
                    $whole = $index + 1;
                }

                if ($retry) {
                    $retry = false;
                    continue;
                }

                if (!$automatic || $footnotes === null) {
                    break;
                }
                $needed = $notes === [] ? 0.0 : $this->footnotesDepth($notes, $region, $footnotes, $font, $options);
                // Only ever grows, so this settles; the cap is a backstop
                // against a page too shallow to hold even one line and a note.
                if ($needed <= $reserve + 1.0e-6 || $attempt >= 7) {
                    break;
                }
                $reserve = $needed;
            }

            if ($lines !== []) {
                while ($currentPage < \intdiv($slot, $columns)) {
                    $this->addPage();
                    $currentPage++;
                }
                $this->drawFlowPage($lines, $notes, (float) $startAt, $endAt, $reserve, $region->column($slot % $columns), $typesetter, $size, $font, $options, $resource, $encode, $gutter, $footnotes);
                $lastEnd = $endAt;
                // Now that the page is settled rather than being tried, any
                // subheading on it names it. The last one wins: it is where the
                // reader stands when they leave the page.
                $this->registerSubheadings($lines, $font);
            }

            // Whole paragraphs are done with; a paragraph the page cut through
            // goes back at the front as its own remainder, to be placed from
            // the top of the next page.
            $queue = \array_values(\array_slice($queue, $whole));
            if ($split !== null && $split[1] > 0) {
                $queue[0] = [
                    'lines' => \array_values(\array_slice($queue[0]['lines'], $split[1])),
                    'heights' => \array_values(\array_slice($queue[0]['heights'], $split[1])),
                    // The heading is on the page just drawn; its remainder is
                    // ordinary text now.
                    'keepWithNext' => false,
                ];
            }

            if ($queue === [] && !$paragraphs->valid()) {
                break;
            }
            $slot++;
            $pageCursor = $region->planStart();
        }

        if ($lastEnd !== null) {
            $this->rememberFlowEnd($lastEnd, $region->writingMode);
        }

        return $lastEnd === null ? $region->blockStart : $region->blockAt($lastEnd);
    }

    /**
     * Note where a block of text stopped on the page that is still open.
     *
     * Only {@see section()} reads this, and only to run a chapter on from where
     * the last one ended. Everything else addresses the page by coordinate, so
     * there is deliberately no public cursor to fall out of step with.
     */
    private function rememberFlowEnd(float $planned, WritingMode $mode): void
    {
        $this->flowCursor = ['page' => $this->pageCount(), 'end' => $planned, 'mode' => $mode];
    }

    /**
     * Where a chapter that runs on should start, or null when there is nothing
     * on this page to run on from.
     *
     * A flow set the other way round, or one that ended on a leaf already
     * turned, says nothing about this region — in both cases the chapter starts
     * at the region's own edge, exactly as it did before it could run on.
     */
    private function flowResumePoint(FlowRegion $region): ?float
    {
        $cursor = $this->flowCursor;
        if ($cursor === null || $cursor['page'] !== $this->pageCount() || $cursor['mode'] !== $region->writingMode) {
            return null;
        }

        // Text drawn outside the region cannot push a chapter backwards.
        return \max($cursor['end'], $region->planStart());
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

        [$cos, $sin] = self::rotationCosSin($rotation);
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
     * Draw a group of elements turned as one: everything $draw emits is rotated
     * $degrees counter-clockwise about the pivot (x, y) in millimetres.
     *
     * ```php
     * $pdf->rotated(90.0, x: 20, y: 250, function (Document $pdf): void {
     *     $pdf->drawBox($box, 0, 0, 180);
     *     $pdf->grid($columns, 0, 40)->row(['…'])->close();
     * });
     * ```
     *
     * This exists because what people want turned is rarely a lone element: it
     * is a label, an obi band, a sideways table — a frame, its rules and its
     * text, which have to keep their positions relative to each other. Passing
     * an angle to each of them separately cannot do that, since each would turn
     * about its own centre and the assembly would come apart.
     *
     * Inside the group, coordinates are the ones you would have used unturned,
     * measured from the same page origin; the rotation is applied on the way
     * out. Groups nest, and the angles add up.
     *
     * Three things follow from the fact that this is one PDF transform:
     *
     * - `strictCanvas` stands down inside the group. Only the pivot is checked,
     *   on the way in; a turned frame's page coordinates no longer describe
     *   where the marks land, so the check has nothing true to say about them.
     * - Opening a page inside the group is refused. A page break would split the
     *   transform across two content streams and leave the first unbalanced.
     * - Link annotations do not turn. PDF fixes an annotation's /Rect to the
     *   page axes, so a link added here stays an upright rectangle over turned
     *   text. The document says so once, in a warning on the logger.
     */
    public function rotated(float $degrees, float $x, float $y, callable $draw): self
    {
        $this->ensurePage();

        // The pivot is the one point that has to be on the sheet; what the group
        // then swings over the edge is the whole point of turning it.
        $this->assertOnCanvas('Rotated group pivot', $x, $y, 0.0, 0.0);

        $this->content .= self::rotationOps(
            $degrees,
            PageSize::mm($x),
            $this->pageHeightPt() - PageSize::mm($y),
        );
        $this->rotationDepth++;

        try {
            $draw($this);
        } finally {
            // Closed even when $draw throws: an unbalanced `q` would leave every
            // later mark on the page turned, so a caller that catches the
            // exception and carries on must still get an upright page.
            $this->rotationDepth--;
            $this->content .= "Q\n";
        }

        return $this;
    }

    /**
     * `q` plus the `cm` that turns everything up to the matching `Q` by
     * $degrees counter-clockwise about the point (cx, cy) in PDF points.
     *
     * Useful on anything that already emits a balanced, self-positioning run of
     * operators — {@see image} and the template painter's fields both place
     * themselves and are then simply turned.
     */
    private static function rotationOps(float $degrees, float $cxPt, float $cyPt): string
    {
        [$cos, $sin] = self::rotationCosSin($degrees);

        return \sprintf(
            "q\n%.5F %.5F %.5F %.5F %.2F %.2F cm\n",
            $cos,
            $sin,
            -$sin,
            $cos,
            $cxPt - ($cxPt * $cos - $cyPt * $sin),
            $cyPt - ($cxPt * $sin + $cyPt * $cos),
        );
    }

    /**
     * Cosine and sine of $degrees, with the quarter turns returned exactly.
     *
     * Those four angles are most of what anyone asks for, and deg2rad() leaves
     * a 1e-17 behind on each of them; giving back a clean 0 and 1 keeps the
     * matrices readable and the arithmetic that builds on them exact.
     *
     * @return array{0: float, 1: float}
     */
    private static function rotationCosSin(float $degrees): array
    {
        $turn = \fmod($degrees, 360.0);
        if ($turn < 0.0) {
            $turn += 360.0;
        }

        return match ($turn) {
            0.0 => [1.0, 0.0],
            90.0 => [0.0, 1.0],
            180.0 => [-1.0, 0.0],
            270.0 => [0.0, -1.0],
            default => [\cos(\deg2rad($degrees)), \sin(\deg2rad($degrees))],
        };
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
        // Inside a rotated() group the coordinates are local to a turned frame,
        // so comparing them against the page box would reject work that lands
        // perfectly well and pass work that does not. The pivot was checked when
        // the group opened; that is the honest part of the question.
        if (!$this->strictCanvas || $this->rotationDepth > 0) {
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

        $this->vertical = $this->vertical || $options->writingMode->isVertical();

        $tokens = $options->skipsInlineMarkup()
            ? [new TextToken($text)]
            : self::literalFootnotes(TextTokenizer::tokenize($text));

        // A block-level style (colour, underline) sits under whatever inline
        // markup produced, so a run can still override the colour it inherits.
        $base = $options->baseStyle;
        if ($base !== null) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken($t->text, $t->rubyText, $t->style->over($base), $t->footnote),
                $tokens,
            );
        }

        if ($options->enableSmartQuotes) {
            $tokens = \array_map(
                static fn (TextToken $t): TextToken => new TextToken(SmartPunctuation::apply($t->text), $t->rubyText, $t->style, $t->footnote),
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

        $result = (new Typesetter($options, $this->verticalFaces($options, $font)))->render(
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
     * The vertical faces for a block, or null when they would make no
     * difference: horizontal writing, or a face with no vertical CMap.
     *
     * Only the built-in Japanese CIDFonts have one. An embedded TrueType is
     * addressed through `Identity-H`, where a CID *is* a glyph id and no
     * mapping to vertical forms exists — those runs keep stacking glyph by
     * glyph until the engine reads `vert`/`vrt2` itself.
     */
    private function verticalFaces(TypesetterOptions $options, FontFace $font): ?VerticalFaces
    {
        if (!$options->writingMode->isVertical()) {
            return null;
        }

        $body = VerticalCidFont::of($font);
        if (!$body instanceof VerticalCidFont) {
            return null;
        }

        $ruby = $options->rubyStyle->font;
        $rubyVertical = $ruby === null || $ruby === $font ? null : VerticalCidFont::of($ruby);

        return new VerticalFaces(
            $this->fonts->resourceName($body),
            fn (string $run): string => $this->showOperand($body, $run),
            $rubyVertical instanceof VerticalCidFont ? $this->fonts->resourceName($rubyVertical) : null,
            $rubyVertical instanceof VerticalCidFont
                ? fn (string $run): string => $this->showOperand($rubyVertical, $run)
                : null,
        );
    }

    /**
     * Set a chapter: break the page, place the heading at the start of the
     * region, leave a gap, register a bookmark, and flow the body through what
     * remains — with the body starting below the heading on this page and at
     * the region's own start on every page after it.
     *
     * A chapter told not to break ({@see Section::$startsOnNewPage}) starts
     * from where the last flow on this page stopped, one gap further on, rather
     * than from the region's edge — otherwise it would be laid over the text
     * already standing there. Should the heading not fit in what is left, the
     * page turns after all: a heading is the one thing that must not be broken
     * or pushed off the sheet.
     *
     * Returns the block-axis coordinate just past the last line, as
     * {@see flowInto()} does, so anything following knows where the chapter
     * ended.
     */
    public function section(
        Section $section,
        FlowRegion $region,
        ?FontFace $font = null,
        float $size = 11.0,
        ?TypesetterOptions $options = null,
        float $gutter = 0.0,
        ?FootnoteStyle $footnotes = null,
    ): float {
        $font ??= $this->defaultFont;
        $options ??= new TypesetterOptions();
        $gap = $section->gap * $size * $options->lineHeight / PageSize::MM_TO_PT;

        $this->ensurePage();
        if ($section->startsOnNewPage && \strlen($this->content) > $this->blankContentLength) {
            $this->addPage(); // only when this page has already been written on
        }

        // Where the heading goes, on the planner's axis. A chapter that runs on
        // resumes a gap past the previous one; one that opens a page — and one
        // with nothing of its own to follow — starts at the region's edge.
        $start = $region->planStart();
        $resume = $section->startsOnNewPage ? null : $this->flowResumePoint($region);
        if ($resume !== null) {
            $start = $resume + $gap;
            $heading = $this->measureTextHeight(
                $section->title,
                $region->columnExtent(),
                $section->titleFont ?? $font,
                $section->titleSize ?? $size,
                $options,
            );
            // Measured rather than guessed at, because a heading is drawn in one
            // piece and would otherwise run off the edge of the sheet unnoticed.
            if ($start + $heading > $region->planLimit()) {
                $this->addPage();
                $start = $region->planStart();
            }
        }

        // The heading occupies the first column's first line, then the gap.
        // It does not span the columns: only the first slot is inset for it, so
        // a spanning heading would have the second column run over it.
        $headingRegion = $region->column(0)->shiftedForSpread(
            $gutter,
            \count($this->pages) + 1,
            $this->direction->resolve($this->vertical || $options->writingMode->isVertical()) === 'R2L',
        );
        [$headX, $headY] = $headingRegion->originAt($start);
        $consumed = $this->flow(
            $section->title,
            $headX,
            $headY,
            $region->columnExtent(),
            $section->titleFont ?? $font,
            $section->titleSize ?? $size,
            $options,
        );
        // Standing where the heading ends, so a chapter with no body at all
        // still moves the next one along instead of being written over.
        $this->rememberFlowEnd($start + $consumed, $region->writingMode);

        if ($section->bookmarkLevel !== null) {
            $this->bookmark($section->title, $section->bookmarkLevel);
        }

        $head = $section->runningHeadText();
        if ($head !== null) {
            $this->runningHeads[] = [
                'from' => \count($this->pages) + 1,
                'text' => $head,
                'font' => $section->titleFont ?? $font,
            ];
            // A section belongs to its chapter. Left standing, the last section
            // of one chapter would name the rectos of the next until that
            // chapter happened to have a subheading of its own.
            $this->runningSections[] = [
                'from' => \count($this->pages) + 1,
                'text' => '',
                'font' => null,
            ];
        }

        // Measured from the region's edge, which is where the planner counts
        // its first page from — so a chapter that ran on carries the distance
        // it had already travelled as well.
        $inset = $start - $region->planStart() + $consumed + $gap;

        return $this->renderFlow($region, $section->body, $font, $size, $options, $gutter, $inset, $footnotes);
    }

    /**
     * Name the section the pages from here on belong to, for the running head.
     *
     * Flowed prose needs no such call — a `.subhead` paragraph names its own
     * page. This is for a subheading drawn some other way, and for clearing the
     * name again with an empty string when a chapter's last section ends.
     */
    public function runningSection(?string $text, ?FontFace $font = null): self
    {
        $this->ensurePage();
        $this->runningSections[] = [
            'from' => $this->pageCount(),
            'text' => $text ?? '',
            'font' => $font,
        ];

        return $this;
    }

    /**
     * Record the subheadings of a page that has just been drawn.
     *
     * @param list<FormattedLine> $lines
     */
    private function registerSubheadings(array $lines, FontFace $font): void
    {
        $page = $this->pageCount();
        foreach ($lines as $line) {
            $text = '';
            foreach ($line->units as $unit) {
                if ($unit->style->isSubheading) {
                    $text .= $unit->text;
                }
            }
            if ($text === '') {
                continue;
            }
            // A subheading wrapping onto a second line extends the same entry
            // rather than starting another.
            $last = \array_key_last($this->runningSections);
            if ($last !== null && $this->runningSections[$last]['from'] === $page) {
                $this->runningSections[$last]['text'] .= $text;
                // Whatever stood here — a cleared entry carries no face — the
                // words are the flow's and must be set in the flow's face.
                $this->runningSections[$last]['font'] = $font;

                continue;
            }
            $this->runningSections[] = ['from' => $page, 'text' => $text, 'font' => $font];
        }
    }

    /**
     * Whichever range covers this page, or null when none does or it is blank.
     *
     * @param  list<array{from: int, text: string, font: FontFace|null}> $ranges
     * @return array{from: int, text: string, font: FontFace|null}|null
     */
    private static function rangeAt(array $ranges, int $physicalPage): ?array
    {
        $found = null;
        foreach ($ranges as $range) {
            if ($range['from'] > $physicalPage) {
                break;
            }
            $found = $range;
        }

        return $found === null || $found['text'] === '' ? null : $found;
    }

    /**
     * The running head for this page: the chapter it falls in on one side of
     * the spread and the section on the other, or nothing when no chapter has
     * started, none was asked for, or the page is a chapter's own opening —
     * where the heading already says it.
     *
     * A side asks for a level and takes the other when that level has nothing
     * to say, so a book without subheadings sets its chapter on every page,
     * exactly as it did before there were two levels.
     */
    private function runningHeadOps(int $physicalPage, PageSize $size): string
    {
        $opts = $this->runningHead;
        if ($opts === null || ($this->runningHeads === [] && $this->runningSections === [])) {
            return '';
        }

        foreach ($this->runningHeads as $chapter) {
            if ($chapter['from'] > $physicalPage) {
                break;
            }
            if ($opts->skipChapterOpenings && $chapter['from'] === $physicalPage) {
                return '';
            }
        }

        // Verso is the back of a leaf, so the even page, whichever edge the
        // book is bound on.
        $wanted = $physicalPage % 2 === 0 ? $opts->verso : $opts->recto;
        if ($wanted === RunningHeadLevel::NONE) {
            return '';
        }
        $chapter = self::rangeAt($this->runningHeads, $physicalPage);
        $section = self::rangeAt($this->runningSections, $physicalPage);

        $range = $wanted === RunningHeadLevel::CHAPTER
            ? $chapter ?? $section
            : $section ?? $chapter;
        if ($range === null) {
            return '';
        }
        $text = $range['text'];

        $font = $opts->font ?? $range['font'] ?? $this->defaultFont;
        $ref = $this->fonts->resourceName($font);
        $width = $this->fonts->metrics($font)->textWidth($text, $opts->fontSize);
        $x = $this->alignX($this->side($opts->alignment, $physicalPage), $size->width, $width, PageSize::mm($opts->marginSide));
        $y = $size->height - PageSize::mm($opts->marginTop);
        $this->fonts->track($font, $text);

        return $this->textOps(
            $ref,
            $opts->fontSize,
            $x,
            $y,
            $this->showOperand($font, $text),
            $opts->color === null ? null : $this->hexToRg($opts->color),
        );
    }

    /**
     * Put `((…))` back as it was written, for the calls that cannot set a note
     * at the foot of a page.
     *
     * Only a flow paginates, and only a flow can therefore place a footnote;
     * {@see typeset()} and its relatives draw one block wherever they are told
     * to. Swallowing the markup there would delete the author's words without
     * saying so, which is worse than printing the brackets.
     *
     * @param  list<TextToken> $tokens
     * @return list<TextToken>
     */
    private static function literalFootnotes(array $tokens): array
    {
        foreach ($tokens as $index => $token) {
            if ($token->footnote !== null) {
                $tokens[$index] = new TextToken('((' . $token->footnote . '))', $token->rubyText, $token->style);
            }
        }

        return $tokens;
    }

    /**
     * Draw one page's worth of flowed lines, and the notes it owes beneath
     * them.
     *
     * @param list<FormattedLine>               $lines
     * @param list<array{0: string, 1: string, 2: float}> $notes
     */
    private function drawFlowPage(
        array $lines,
        array $notes,
        float $startAt,
        float $endAt,
        float $reserve,
        FlowRegion $region,
        Typesetter $typesetter,
        float $size,
        FontFace $font,
        TypesetterOptions $options,
        string $resource,
        callable $encode,
        float $gutter,
        ?FootnoteStyle $footnotes,
    ): void {
        // Mirrored margins move the block without resizing it, so the shift
        // belongs here and never reaches the planner.
        $drawn = $region->shiftedForSpread(
            $gutter,
            \count($this->pages) + 1,
            $this->direction->resolve($this->vertical) === 'R2L',
        );

        [$originX, $originY] = $drawn->originAt($startAt);
        [$boxX, $boxY, $boxW, $boxH] = $drawn->boxAt($startAt, $endAt - $startAt);

        $this->assertOnCanvas('Flowed text', $boxX, $boxY, $boxW, $boxH);
        $this->content .= $typesetter->render(
            $lines,
            PageSize::mm($originX),
            $this->pageHeightPt() - PageSize::mm($originY),
            PageSize::mm($drawn->inlineExtent),
            $size,
            $resource,
            $encode,
            ...$this->rubyHandles($options, $font),
        )->operators;
        // One frame per page, so a paginated flow shows where each page's
        // share of the text actually landed.
        $this->debugGuide($boxX, $boxY, $boxW, $boxH, self::GUIDE_TEXT);

        if ($footnotes !== null && $notes !== []) {
            if ($footnotes->inMargin()) {
                $this->drawMarginNotes($notes, $drawn, $footnotes, $font, $options);
            } else {
                $this->drawFootnotes($notes, $drawn->withBlockLimit($reserve), $footnotes, $reserve, $font, $options);
            }
        }
    }

    /**
     * Set this page's notes in the fore-edge margin, each beside the line that
     * refers to it — the 傍注 of a Japanese textbook.
     *
     * The margin is outside the region, so unlike a band at the foot this
     * costs the body nothing and never sends the page back to be laid out
     * again. What it does need is a rule for two references close together:
     * notes are set in order and each is pushed down far enough to clear the
     * one before, so a run of them drifts below their marks rather than
     * printing on top of one another.
     *
     * @param list<array{0: string, 1: string, 2: float}> $notes
     */
    private function drawMarginNotes(
        array $notes,
        FlowRegion $region,
        FootnoteStyle $style,
        FontFace $font,
        TypesetterOptions $options,
    ): void {
        $outer = $this->side(BindingEdge::OUTER, $this->pageCount());
        $x = $outer === Alignment::LEFT
            ? $region->inlineStart - $style->marginGap - $style->marginWidth
            : $region->inlineStart + $region->inlineExtent + $style->marginGap;

        $plain = $options->withBaseStyle(null);
        $lowest = null;
        foreach ($notes as [$label, $text, $planned]) {
            $y = $region->blockAt($planned);
            if ($lowest !== null && $y < $lowest) {
                $y = $lowest;
            }
            $y += $this->flow($label . ' ' . $text, $x, $y, $style->marginWidth, $font, $style->fontSize, $plain);
            $lowest = $y + $style->noteGap;
        }

        $overflow = ($lowest ?? 0.0) - $region->blockLimit;
        if ($overflow > 0.0) {
            $this->logger->warning(
                'Margin notes on page {page} run {overflow} mm past the foot of the body.',
                ['page' => $this->pageCount(), 'overflow' => \round($overflow, 2)],
            );
        }
    }

    /**
     * How deep a band this page's notes need, measured without drawing them.
     *
     * @param list<array{0: string, 1: string, 2: float}> $notes
     */
    private function footnotesDepth(array $notes, FlowRegion $region, FootnoteStyle $style, FontFace $font, TypesetterOptions $options): float
    {
        $depth = $style->gapAbove + $style->gapBelow;
        $plain = $options->withBaseStyle(null);
        foreach ($notes as [$label, $text]) {
            $depth += $this->measureTextHeight($label . ' ' . $text, $region->inlineExtent, $font, $style->fontSize, $plain)
                + $style->noteGap;
        }

        return $depth;
    }

    /**
     * Give every footnote its number and its mark, in the order the marks
     * appear.
     *
     * Done before line breaking because the mark has a width: what the note is
     * called has to be settled before anyone can ask how wide the line is.
     *
     * @param  list<TextToken> $tokens
     * @return list<TextToken>
     */
    private function numberFootnotes(array $tokens, FootnoteStyle $style, float $size): array
    {
        foreach ($tokens as $index => $token) {
            if ($token->footnote === null) {
                continue;
            }
            $this->footnoteCount++;
            $tokens[$index] = new TextToken(
                $style->label($this->footnoteCount),
                null,
                $token->style->with(fontSize: $size * $style->markerScale, raise: $style->markerRaise),
                $token->footnote,
            );
        }

        return $tokens;
    }

    /**
     * Set a page's notes in the band kept for them, under a short rule.
     *
     * The band's depth is what the caller reserved, so the notes are laid out
     * against it rather than the other way round; a page whose notes outgrow it
     * says so in the log rather than printing over the body.
     *
     * @param list<array{0: string, 1: string, 2: float}> $notes label, text, planned position
     */
    private function drawFootnotes(array $notes, FlowRegion $region, FootnoteStyle $style, float $reserve, FontFace $font, TypesetterOptions $options): void
    {
        $x = $region->isVertical() ? $region->blockLimit : $region->inlineStart;
        $width = $region->isVertical() ? $region->inlineExtent : $region->inlineExtent;
        $top = $region->isVertical() ? $region->inlineStart : $region->blockLimit;

        $y = $top + $style->gapAbove;
        if ($style->ruleLength > 0.0) {
            $this->line($x, $y, $x + \min($style->ruleLength, $width), $y, $style->ruleWeight);
        }
        $y += $style->gapBelow;

        $plain = $options->withBaseStyle(null);
        foreach ($notes as [$label, $text]) {
            $y += $this->flow(
                $label . ' ' . $text,
                $x,
                $y,
                $width,
                $font,
                $style->fontSize,
                $plain,
            ) + $style->noteGap;
        }

        $overflow = $y - ($top + $reserve);
        if ($overflow > 0.0) {
            $this->logger->warning(
                'Footnotes on page {page} overrun their reserve by {overflow} mm; deepen FootnoteStyle::$reserve.',
                ['page' => $this->pageCount(), 'overflow' => \round($overflow, 2)],
            );
        }
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
     *
     * $rotation turns the image counter-clockwise, in degrees, about the centre
     * of the box it was given — the same pivot {@see textObject} uses, so a
     * caption set at the same angle stays parallel to the picture.
     */
    public function image(
        RasterImage|string $image,
        float $x,
        float $y,
        ?float $width = null,
        ?float $height = null,
        float $rotation = 0.0,
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

        $rotated = $rotation !== 0.0;
        if ($rotated) {
            // Only the pivot is checked when the image turns: a rotated picture
            // is free to swing its corners off the sheet — the same latitude
            // {@see textObject} gives a diagonal watermark — but the point it
            // was placed on has to be on the page.
            $this->assertOnCanvas('Image', $x + $width / 2.0, $y + $height / 2.0, 0.0, 0.0);
            $this->content .= self::rotationOps($rotation, $px + $displayW / 2.0, $py + $displayH / 2.0);
        } else {
            $this->assertOnCanvas('Image', $x, $y, $width, $height);
        }

        // The placement below is a self-contained `q … Do Q`, so wrapping it in
        // a rotation is all turning it takes. The debug frame rides inside that
        // wrapper and therefore turns with the picture instead of boxing it in
        // page space.
        $this->content .= \sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/%s Do\nQ\n", $displayW, $displayH, $px, $py, $name);
        $this->debugGuide($x, $y, $width, $height, self::GUIDE_IMAGE);

        if ($rotated) {
            $this->content .= "Q\n";
        }

        return $this;
    }

    /**
     * Import page $pageNumber of an existing PDF and paint it, scaled to fill the
     * current page, as a vector background. Call this before drawing foreground
     * content so it sits behind everything.
     *
     * The source's /Rotate is honoured, so the page arrives the way its author
     * meant it to be read rather than on its side.
     *
     * Scope: classic-xref (PDF ≤ 1.4), unencrypted sources; cross-reference
     * streams (PDF 1.5+) and encrypted sources raise a PdfImportException.
     */
    public function importPageAsBackground(string $path, int $pageNumber = 1): self
    {
        [$name, $importedW, $importedH, $matrix] = $this->embedImportedPage($path, $pageNumber);

        // Scale the imported page to fill the current one.
        $size = $this->currentSize ?? $this->defaultSize;
        $scaleX = $importedW != 0.0 ? $size->width / $importedW : 1.0;
        $scaleY = $importedH != 0.0 ? $size->height / $importedH : 1.0;
        $this->content .= self::placeImported($matrix, $scaleX, $scaleY, 0.0, 0.0, $name);

        return $this;
    }

    /**
     * Import page $pageNumber of an existing PDF and place it with its top-left
     * corner at (x, y) millimetres. If only one of width/height is given the
     * other follows the imported aspect ratio; if neither, the page is placed at
     * its native size (1 pt = 1 pt).
     *
     * "Native size" and "aspect ratio" mean the page as displayed, so a source
     * carrying /Rotate 90 is measured — and placed — landscape.
     */
    public function importPage(
        string $path,
        int $pageNumber,
        float $x,
        float $y,
        ?float $width = null,
        ?float $height = null,
    ): self {
        [$name, $importedW, $importedH, $matrix] = $this->embedImportedPage($path, $pageNumber);

        $nativeW = $importedW / PageSize::MM_TO_PT;
        $nativeH = $importedH / PageSize::MM_TO_PT;
        $aspect = $nativeW != 0.0 ? $nativeH / $nativeW : 1.0;

        if ($width === null && $height === null) {
            $width = $nativeW;
            $height = $nativeH;
        } elseif ($width === null) {
            $width = $aspect != 0.0 ? $height / $aspect : $nativeW;
        } elseif ($height === null) {
            $height = $width * $aspect;
        }

        // Map the displayed page onto the target rectangle (top-left origin, mm).
        $scaleX = $importedW != 0.0 ? PageSize::mm($width) / $importedW : 1.0;
        $scaleY = $importedH != 0.0 ? PageSize::mm($height) / $importedH : 1.0;
        $tx = PageSize::mm($x);
        $ty = $this->pageHeightPt() - PageSize::mm($y + $height);

        $this->content .= self::placeImported($matrix, $scaleX, $scaleY, $tx, $ty, $name);

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
     * The new page takes the source's *displayed* size, so a scan stored as a
     * portrait MediaBox plus /Rotate 90 opens landscape with its content the
     * right way up — and the millimetres a caller then stamps in are the ones
     * they measured off the screen.
     *
     * Same source scope as {@see importPage}: classic-xref or cross-reference
     * stream (PDF 1.5+), unencrypted.
     */
    public function editTemplatePage(string $path, int $pageNumber = 1): self
    {
        $this->assertNotFinalized();
        $imported = PdfPageImporter::import($this->readSource($path), $pageNumber);
        [$displayW, $displayH, $matrix] = $this->displayedPage($imported);

        $this->addPage(new PageSize(\max(1.0, $displayW), \max(1.0, $displayH)));

        $name = $this->embedImported($imported);
        $this->content .= self::placeImported($matrix, 1.0, 1.0, 0.0, 0.0, $name);

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
     * Read, import and embed a PDF page as a Form XObject.
     *
     * @return array{0: string, 1: float, 2: float, 3: array{float, float, float, float, float, float}}
     *         resource name, displayed width and height (points), and the
     *         matrix described by {@see displayedPage}
     */
    private function embedImportedPage(string $path, int $pageNumber): array
    {
        $this->ensurePage();

        $imported = PdfPageImporter::import($this->readSource($path), $pageNumber);
        $name = $this->embedImported($imported);
        [$width, $height, $matrix] = $this->displayedPage($imported);

        return [$name, $width, $height, $matrix];
    }

    /**
     * An imported page as a viewer shows it: its size in points, and the matrix
     * carrying source page coordinates onto [0, width] x [0, height].
     *
     * Two corrections are folded in. A MediaBox need not start at 0 0, so the
     * origin moves to its lower-left corner; and /Rotate — which a viewer
     * applies before showing anything, and which scanners and office suites
     * write far more often than a landscape MediaBox — becomes a quarter turn,
     * swapping width and height at 90 and 270.
     *
     * Expressing the turn here rather than in the Form XObject keeps the
     * imported page byte-identical to its source, which is the whole promise of
     * the non-destructive overlay model.
     *
     * @return array{0: float, 1: float, 2: array{float, float, float, float, float, float}}
     */
    private function displayedPage(ImportedPage $page): array
    {
        [$x0, $y0, $x1, $y1] = $this->parseBox($page->bbox);
        $width = $x1 - $x0;
        $height = $y1 - $y0;

        return match ($page->rotate) {
            90 => [$height, $width, [0.0, -1.0, 1.0, 0.0, -$y0, $x1]],
            180 => [$width, $height, [-1.0, 0.0, 0.0, -1.0, $x1, $y1]],
            270 => [$height, $width, [0.0, 1.0, -1.0, 0.0, $y1, -$x0]],
            default => [$width, $height, [1.0, 0.0, 0.0, 1.0, -$x0, -$y0]],
        };
    }

    /**
     * Paint an imported page, concatenating a scale and translation onto its
     * display matrix so the whole placement stays a single `cm`.
     *
     * @param array{float, float, float, float, float, float} $m
     */
    private static function placeImported(
        array $m,
        float $scaleX,
        float $scaleY,
        float $x,
        float $y,
        string $name,
    ): string {
        return \sprintf(
            "q\n%.4F %.4F %.4F %.4F %.4F %.4F cm\n/%s Do\nQ\n",
            $m[0] * $scaleX,
            $m[1] * $scaleY,
            $m[2] * $scaleX,
            $m[3] * $scaleY,
            $m[4] * $scaleX + $x,
            $m[5] * $scaleY + $y,
            $name,
        );
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
     *
     * The area is always axis-aligned: see {@see rotated} for what that means
     * inside a turned group.
     */
    public function link(float $x, float $y, float $width, float $height, string $url): self
    {
        $this->warnAboutRotatedLink($x, $y);
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
        $this->warnAboutRotatedLink($x, $y);
        $this->pendingAnnots[] = [
            'kind' => 'goto',
            'rect' => $this->annotRect($x, $y, $width, $height),
            'url' => '',
            'target' => \max(0, $pageNumber - 1),
        ];

        return $this;
    }

    /**
     * Say once, per link, that a clickable area placed inside {@see rotated}
     * will not turn with the group.
     *
     * PDF pins an annotation's /Rect to the page axes, so there is nothing to
     * fix here — but the mismatch between where the text ends up and where the
     * click works is invisible until someone tries it, which is exactly the kind
     * of thing a log line is for. It stays proportional to the number of links,
     * not to the size of the document.
     */
    private function warnAboutRotatedLink(float $x, float $y): void
    {
        if ($this->rotationDepth === 0) {
            return;
        }

        $this->logger->warning(
            'Link area added inside rotated(); PDF annotations are axis-aligned and will not turn with the group',
            ['x' => $x, 'y' => $y, 'page' => $this->pageCount()],
        );
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

        // Omitted when upright, so pages that do not ask to be turned are
        // byte-for-byte what they always were.
        $rotate = $this->pageRotation !== 0 ? \sprintf(' /Rotate %d', $this->pageRotation) : '';

        $pageId = $this->doc->allocateObjectId();
        $this->doc->writeObject($pageId, \sprintf(
            '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F]%s /Contents [ %d 0 R %d 0 R ]%s >>',
            $this->pagesId,
            $size->width,
            $size->height,
            $rotate,
            $contentId,
            $footerId,
            $annotsRef,
        ));

        $this->pages[] = ['page' => $pageId, 'footer' => $footerId, 'size' => $size, 'annots' => $annots];
        $this->pageOpen = false;
        $this->pageRotation = 0;
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
                $font instanceof CidFontJapanese, $font instanceof VerticalCidFont => $this->writeCidFont($font),
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

        if ($this->info->language !== null) {
            $catalogExtra .= \sprintf(' /Lang %s', $this->pdfTextString($this->catalogId, $this->info->language));
        }

        $direction = $this->direction->resolve($this->vertical);
        if ($direction !== null) {
            $catalogExtra .= \sprintf(' /ViewerPreferences << /Direction /%s >>', $direction);
        }

        $destination = $this->openFit->destination();
        if ($destination !== null && $this->pages !== []) {
            $catalogExtra .= \sprintf(' /OpenAction [ %d 0 R %s ]', $this->pages[0]['page'], $destination);
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

        $authored = '';
        foreach (['Title' => $this->info->title, 'Author' => $this->info->author, 'Subject' => $this->info->subject] as $key => $value) {
            if ($value !== null) {
                $authored .= \sprintf(' /%s %s', $key, $this->pdfTextString($infoId, $value));
            }
        }

        $keywords = $this->keywordsValue();
        if ($keywords !== null) {
            $authored .= \sprintf(' /Keywords %s', $this->pdfTextString($infoId, $keywords));
        }

        $this->doc->writeObject($infoId, \sprintf(
            '<< /Producer %s /Creator %s /CreationDate %s /ModDate %s%s >>',
            $this->pdfTextString($infoId, Version::producer()),
            $this->pdfTextString($infoId, 'MejiroPDF'),
            $this->pdfTextString($infoId, $date),
            $this->pdfTextString($infoId, $date),
            $authored,
        ));

        return $infoId;
    }

    /**
     * `/Keywords`, or null when there is nothing to write.
     *
     * The licence marker goes **first** and the author's value follows after a
     * semicolon. First, because it is then found by a reader that stops early,
     * cannot be pushed out of sight by a long value, and needs no scan of
     * someone else's text to locate.
     *
     * The author's value is never inspected, de-duplicated or edited. A rule
     * that stripped text matching the marker would be a rule that deletes the
     * marker for anyone who types it out — handing over the lever in the act of
     * tidying up. Prepend, and look away.
     *
     * Note for anyone auditing: once a non-ASCII keyword is present the whole
     * string is written as UTF-16BE hex, so the marker has to be looked for in
     * a decoded string object rather than in the file's bytes.
     */
    private function keywordsValue(): ?string
    {
        $marker = $this->credit->enabled ? null : $this->credit->markerText();
        $authored = $this->info->keywords;

        if ($marker === null) {
            return $authored;
        }

        return $authored === null ? $marker : $marker . '; ' . $authored;
    }

    /**
     * The authored Dublin Core properties, mirroring `/Title`, `/Author` and
     * `/Subject`. PDF/A requires a property present in both the info dictionary
     * and XMP to carry the same value, so these are written from the same
     * source and escaped for XML.
     *
     * `dc:title` and `dc:description` are language alternatives and
     * `dc:creator` an ordered list — the shapes the standard defines for them,
     * not free text.
     */
    private function xmpDublinCore(): string
    {
        $xml = '';
        foreach (['dc:title' => $this->info->title, 'dc:description' => $this->info->subject] as $tag => $value) {
            if ($value !== null) {
                $xml .= \sprintf(
                    "   <%s><rdf:Alt><rdf:li xml:lang=\"x-default\">%s</rdf:li></rdf:Alt></%s>\n",
                    $tag,
                    self::xmlText($value),
                    $tag,
                );
            }
        }
        if ($this->info->author !== null) {
            $xml .= \sprintf(
                "   <dc:creator><rdf:Seq><rdf:li>%s</rdf:li></rdf:Seq></dc:creator>\n",
                self::xmlText($this->info->author),
            );
        }

        return $xml;
    }

    /**
     * `pdf:Keywords`, carrying exactly what `/Keywords` carries — including the
     * licence marker, since the two must agree.
     */
    private function xmpKeywords(): string
    {
        $keywords = $this->keywordsValue();

        return $keywords === null
            ? ''
            : \sprintf("   <pdf:Keywords>%s</pdf:Keywords>\n", self::xmlText($keywords));
    }

    private static function xmlText(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
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
    private function writeCidFont(CidFontJapanese|VerticalCidFont $font): int
    {
        // One descendant per typeface, shared by the horizontal and vertical
        // parents. They differ only in which CMap reads it.
        $descendantId = $this->cidDescendants[$font->postScriptName()] ?? null;
        if ($descendantId === null) {
            $descendantId = $this->doc->allocateObjectId();
            $this->cidDescendants[$font->postScriptName()] = $descendantId;
            $this->doc->writeObject($descendantId, \sprintf(
                '<< /Type /Font /Subtype /CIDFontType0 /BaseFont /%s '
                . '/CIDSystemInfo << /Registry (%s) /Ordering (%s) /Supplement %d >> /DW %d >>',
                $font->postScriptName(),
                $font->registry(),
                $font->ordering(),
                $font->supplement(),
                $font->defaultWidth(),
            ));
        }

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
            $ops .= $this->creditOps($size, $physicalPage);
        }

        $number = $this->pageNumberText($physicalPage, $total);
        if ($number !== null && $number !== '') {
            $ops .= $this->pageNumberOps($number, $physicalPage, $size);
        }

        $ops .= $this->runningHeadOps($physicalPage, $size);

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

    private function creditOps(PageSize $size, int $physicalPage): string
    {
        return $this->creditTextOps(
            $size,
            $this->credit->text,
            \sprintf('%.3F g', $this->credit->gray),
            $physicalPage,
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
    private function creditMarkerOps(PageSize $size, int $physicalPage): string
    {
        return $this->creditTextOps(
            $size,
            $this->credit->markerText(),
            self::fillOp($this->credit->markerColor ?? '#FFFFFF'),
            $physicalPage,
        );
    }

    /**
     * Shared placement for both the visible credit and the hidden marker: the
     * lower corner opposite the page number, so the two never collide.
     */
    private function creditTextOps(PageSize $size, string $text, string $colorOp, int $physicalPage = 1): string
    {
        // PDF/A forbids non-embedded fonts, so route the credit through the
        // document's (embedded) default font instead of Standard-14 Helvetica.
        $font = $this->pdfA ? $this->defaultFont : StandardFont::HELVETICA;
        $ref = $this->fonts->resourceName($font);
        $metrics = $this->fonts->metrics($font);
        $width = $metrics->textWidth($text, $this->credit->fontSize);

        // Place the credit on the opposite side of the page number (LEFT by
        // default). Resolving the page number's side first is what makes the
        // credit follow it to the gutter when the number sits on the fore-edge.
        $numbers = $this->pageNumbers;
        $align = $numbers === null
            ? Alignment::LEFT
            : $this->side($numbers->alignment, $physicalPage)->opposite();
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

    /**
     * What this page's number reads, or null when it carries none.
     *
     * With no restart registered this is exactly what {@see PageNumberOptions}
     * has always produced, so a document that does not ask for sequences comes
     * out byte for byte as before.
     */
    private function pageNumberText(int $physicalPage, int $total): ?string
    {
        $opts = $this->pageNumbers;
        if ($opts === null) {
            return null;
        }
        if ($this->numberingRestarts === []) {
            return $opts->shouldRender($physicalPage) ? $opts->resolve($physicalPage, $total) : null;
        }

        // The last sequence that has begun by this page; none means the page
        // is still in the leading run the options describe.
        $current = null;
        $end = $total;
        foreach ($this->numberingRestarts as $sequence) {
            if ($sequence['from'] > $physicalPage) {
                $end = $sequence['from'] - 1;
                break;
            }
            $current = $sequence;
        }

        if ($current === null) {
            $head = $this->numberingRestarts[0]['from'] - 1;

            return $opts->shouldRender($physicalPage) ? $opts->resolve($physicalPage, $head) : null;
        }

        $offset = $physicalPage - $current['from'];

        return $opts->fill(
            $current['start'] + $offset,
            $current['start'] + ($end - $current['from']),
            $current['style'],
        );
    }

    private function pageNumberOps(string $text, int $physicalPage, PageSize $size): string
    {
        $opts = $this->pageNumbers;
        \assert($opts !== null);

        $ref = $this->fonts->resourceName($opts->font);
        $metrics = $this->fonts->metrics($opts->font);
        $width = $metrics->textWidth($text, $opts->fontSize);

        $x = $this->alignX($this->side($opts->alignment, $physicalPage), $size->width, $width, PageSize::mm($opts->marginSide));
        $y = PageSize::mm($opts->marginBottom);
        $colorOp = $opts->color === null ? null : $this->hexToRg($opts->color);

        // Register the glyphs (a no-op unless the number font is embedded).
        $this->fonts->track($opts->font, $text);

        return $this->textOps($ref, $opts->fontSize, $x, $y, $this->showOperand($opts->font, $text), $colorOp);
    }

    /**
     * Compute the x anchor (pt) for aligned text of the given width within a page.
     */
    /**
     * The physical side a page-number alignment names on this page.
     *
     * {@see BindingEdge} answers differently on rectos and versos, and which
     * side the gutter falls on follows the binding — right-bound for a book
     * that reads right to left.
     */
    private function side(Alignment|BindingEdge $alignment, int $physicalPage): Alignment
    {
        return $alignment instanceof BindingEdge
            ? $alignment->resolve($physicalPage, $this->direction->resolve($this->vertical) === 'R2L')
            : $alignment;
    }

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
            // Both CMaps read UTF-16BE; only which CID it lands on differs.
            $font instanceof CidFontJapanese, $font instanceof VerticalCidFont => JapaneseTextEncoder::toCidHex($text),
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

        // The placeholders go last, after the signer's own strings, so their
        // positions follow from the length of what precedes them.
        $head = \sprintf('<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached %s /ByteRange ', $meta);
        $body = $head . self::SIG_BYTE_RANGE_PLACEHOLDER . ' /Contents <'
            . \str_repeat('0', $signature->reservedBytes * 2) . '> >>';

        // writeObject() emits "N 0 obj\n" and then the body.
        $bodyOffset = $this->writer->getOffset() + \strlen("{$this->sigDictId} 0 obj\n");
        $this->sigByteRangeOffset = $bodyOffset + \strlen($head);
        $this->sigContentsOffset = $this->sigByteRangeOffset
            + \strlen(self::SIG_BYTE_RANGE_PLACEHOLDER . ' /Contents ');
        $this->doc->writeObject($this->sigDictId, $body);
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
        $ltPos = $this->sigContentsOffset;  // byte offset of '<'
        $gtPos = $ltPos + 1 + $reservedHex; // byte offset of '>'
        $rangePos = $this->sigByteRangeOffset;
        $rangeLength = \strlen(self::SIG_BYTE_RANGE_PLACEHOLDER);
        if (
            $ltPos < 0
            || \substr($bytes, $ltPos, 1) !== '<'
            || \substr($bytes, $gtPos, 1) !== '>'
            || \substr($bytes, $rangePos, $rangeLength) !== self::SIG_BYTE_RANGE_PLACEHOLDER
        ) {
            throw SecurityException::encryptionFailed('Signature placeholder not found where it was written.');
        }
        $fileLength = \strlen($bytes);

        // Fill the ByteRange in place (same byte length as its placeholder).
        $byteRange = \sprintf('[0 %010d %010d %010d]', $ltPos + 1, $gtPos, $fileLength - $gtPos);
        $bytes = \substr_replace($bytes, $byteRange, $rangePos, $rangeLength);

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
            . $this->xmpDublinCore()
            . "  </rdf:Description>\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:xmp=\"http://ns.adobe.com/xap/1.0/\">\n"
            . "   <xmp:CreatorTool>MejiroPDF</xmp:CreatorTool>\n"
            . "   <xmp:CreateDate>{$now}</xmp:CreateDate>\n"
            . "   <xmp:ModifyDate>{$now}</xmp:ModifyDate>\n"
            . "  </rdf:Description>\n"
            . "  <rdf:Description rdf:about=\"\" xmlns:pdf=\"http://ns.adobe.com/pdf/1.3/\">\n"
            . "   <pdf:Producer>{$producer}</pdf:Producer>\n"
            . $this->xmpKeywords()
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
