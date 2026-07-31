# MejiroPDF

A modern, stream-first, and memory-efficient PDF generation engine for PHP 8.3+.

> 日本語: [README.ja.md](./README.ja.md)

> Status: **early implementation** (Phase 1 foundation). This package lives inside
> the `mejiropdf-dev` monorepo; the public repository is `mejiropdf`.

## Requirements

- PHP **8.3+** (uses typed class constants, `readonly` classes, enums)
- ext-mbstring
- `psr/log` (interfaces only — the engine ships no logging framework)
- 64-bit

## Install (once published)

```bash
composer require 2bo-velocity/mejiropdf
```

## Quick start

```php
use MejiroPDF\Document;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Orientation;
use MejiroPDF\Page\PageSize;

$pdf = new Document();                              // A4 portrait by default

$pdf->addPage()
    ->text(20, 30, 'Hello, MejiroPDF', size: 28.0, color: '#06B6D4')
    ->line(20, 36, 190, 36, weight: 0.4)
    ->rect(20, 44, 60, 24, stroke: '#333333', fill: '#F0F9FF');

$pdf->addPage(PageSize::a4(Orientation::LANDSCAPE))
    ->text(20, 25, 'Second page', font: StandardFont::TIMES_ROMAN, size: 18.0);

$pdf->save('hello.pdf');                            // or: $bytes = $pdf->output();
```

Coordinates and lengths are **millimetres from the top-left corner** (X right,
Y down); font sizes are in **points**.

See [`examples/hello.php`](examples/hello.php).

## Design

- **O(1) memory**: pages stream straight to the output buffer; the engine never
  holds a document-wide DOM. `SafeStreamWriter` tracks exact byte offsets for the
  xref table.
- **Fail-Fast**: every error carries a stable code (`MP-[COMPONENT]-[####]`, e.g.
  `MP-SIO-3001`) and a diagnostic context array.
- **Standard 14 fonts** ship as pure AFM metrics (no font files parsed).
- Compiled `.mpb` templates (produced by MejiroPDF Studio) load without any
  runtime XML/JSON parsing: the layout work is already done before the file
  reaches the server.

## What's implemented (Phase 1)

| Area | Classes |
| --- | --- |
| Stream | `SafeStreamWriter`, `StreamWriterInterface` |
| Writer | `DocumentWriter` (header / objects / xref / trailer) |
| Facade | `Document` (`addPage`, `text`, `line`, `rect`, `save`, `output`) |
| Fonts | `StandardFont` (Std 14), `CidFontJapanese` (CJK, non-embedded), `TrueTypeFont` (embedded), metrics, `FontRegistry` |
| Text | `TextWrapper`, `JapaneseTextEncoder` (UTF-16BE), `PdfEscaper` |
| Page | `PageSize`, `Orientation`, `Alignment`, `PageNumberOptions`, `CreditOptions` |
| Layout | Page numbers (`{PAGE}/{TOTAL}`, exact total), streaming `Table` (zebra rows, grid/rule/no borders) |
| Graphics | Lines, rects, circle/ellipse, rounded rects, linear/radial gradients |
| Navigation | Web/internal links, bookmarks (nested outline), multi-page TOC (dotted leaders, clickable, 2-pass) |
| Security | AES-128 encryption; detached PKCS#7 signatures (`Signature`); PDF/A-1b (`pdfA: true`) |
| Import | Embed a page from an existing PDF as a vector background (`PdfPageImporter`) |
| Templates | `.mpb` loader/writer (`MpbWriter`, `CompiledTemplateLoader`), `TemplateRenderer` |
| Images | JPEG (`DCTDecode` pass-through) + PNG (`Image`, opaque & alpha/`SMask`) |
| Exceptions | `ErrorCode`, `MejiroPDFException`, `AbstractPdfException`, … |

### Images

```php
$pdf->image('logo.png', x: 20, y: 20, width: 40);   // height auto from aspect
$pdf->image('photo.jpg', x: 20, y: 70, width: 80, height: 50);
```

JPEGs stream in untouched (`/DCTDecode`); PNGs embed via a PNG predictor, and
transparent PNGs are split into a colour image plus a grayscale soft mask — all
with a flat memory footprint. See [`examples/image.php`](examples/image.php).

### Compiled templates (`.mpb`)

MejiroPDF Studio compiles a JSON design into a `.mpb` binary; the engine loads it
without any runtime parsing and pours in per-request data:

```php
use MejiroPDF\Template\CompiledTemplateLoader;

$template = CompiledTemplateLoader::loadFile('invoice.mpb');
$pdf->renderTemplate($template, ['customer' => '株式会社メジロ', 'total' => '¥1,300,000']);
```

See [`examples/template.php`](examples/template.php). The `.mpb` container is the
MPBF format, described in the class docblock of
[`src/Template/Mpb.php`](src/Template/Mpb.php).

### Japanese (CJK)

```php
use MejiroPDF\Font\CidFontJapanese;

$pdf->addPage()->text(20, 30, '請求書', font: CidFontJapanese::HEISEI_KAKU_GO, size: 24.0);
```

Uses the built-in `HeiseiKakuGo-W5` / `HeiseiMin-W3` Adobe-Japan1 CIDFonts — no
embedding, text encoded as UTF-16BE via the `UniJIS-UTF16-H` CMap. See
[`examples/japanese.php`](examples/japanese.php).

### Embedded TrueType

```php
use MejiroPDF\Font\TrueTypeFont;

$font = TrueTypeFont::fromFile('/path/to/DejaVuSans.ttf');
$pdf->addPage()->text(20, 30, 'café — ¥ — “quotes”', font: $font, size: 16.0);
```

The font is **subset** to the glyphs you actually use (composite glyphs and
their dependencies included) and embedded as a CIDFontType2 (Identity-H) with a
ToUnicode CMap — so accented Latin/symbols render everywhere and stay
copy-pasteable, at a fraction of the file size (a 757 KB DejaVuSans → ~50 KB for
a typical page). See [`examples/truetype.php`](examples/truetype.php).

### Navigation

```php
$pdf->tableOfContents('Contents');          // filled in at finalize (2-pass)
$pdf->addPage()->bookmark('Chapter 1');     // nested outline; level for children
$pdf->link(20, 55, 60, 7, 'https://mejiropdf.com');   // clickable web link
$pdf->linkToPage(20, 65, 60, 7, 3);          // jump to page 3
```

The table of contents is built from the `bookmark()` entries at finalize (page
numbers resolved 2-pass); it flows onto as many pages as needed, draws a dotted
leader from each title to its page number, and makes every entry a clickable
link to its target. Page numbers throughout the document account for the pages
the TOC itself occupies. See [`examples/report.php`](examples/report.php).

### Encryption

```php
use MejiroPDF\Security\Encryption;

$pdf = new Document(encryption: new Encryption(
    userPassword: 'secret',           // required to open
    ownerPassword: 'owner',           // unlocks full permissions
    permissions: Encryption::NO_COPY, // e.g. disallow copying text
));
```

Every string and stream is encrypted with AES-128 (standard security handler,
`/V 4 /R 4`, AESV2). See [`examples/encrypted.php`](examples/encrypted.php).

### Digital signatures

```php
use MejiroPDF\Security\Signature;

$pdf = new Document(signature: Signature::fromFiles('cert.pem', 'key.pem', name: 'Acme'));
$pdf->addPage()->text(20, 30, 'Signed');
$pdf->save('signed.pdf');   // verify: pdfsig signed.pdf
```

Adds an AcroForm signature field and a detached PKCS#7/CMS signature
(`adbe.pkcs7.detached`, SHA-256) over the document byte range via ext-openssl.
See [`examples/signed.php`](examples/signed.php).

### PDF/A-1b (archival)

```php
$pdf = new Document(pdfA: true);
$pdf->setDefaultFont(TrueTypeFont::fromFile('DejaVuSans.ttf')); // must be embedded
$pdf->addPage()->text(20, 30, 'Archived');
$pdf->save('archive.pdf');   // validate: verapdf --flavour 1b archive.pdf
```

Produces PDF 1.4 with pdfaid XMP metadata (part 1, conformance B), an sRGB
`GTS_PDFA1` output intent (embedded ICC profile) and a document `/ID`. PDF/A
forbids non-embedded fonts and encryption, so every font must be a
`TrueTypeFont` — Standard-14 fonts, CJK CID fonts, page numbers and encryption
are rejected with a clear error. See [`examples/pdfa.php`](examples/pdfa.php).

### PDF background import

```php
$pdf->addPage()
    ->importPageAsBackground('letterhead.pdf')     // fill the page
    ->text(60, 40, 'INVOICE', size: 24.0);         // dynamic data on top

$pdf->importPage('logo.pdf', 1, x: 150, y: 12, width: 40); // positioned
```

The source page becomes a Form XObject (its resources are copied and remapped).
Handles classic xref, cross-reference / object streams with PNG predictors
(PDF 1.5+), **hybrid-reference** files (classic table + `/XRefStm`), and
`/Resources` inherited from an ancestor `/Pages` node. Encrypted sources raise a
clear error. See [`examples/import_background.php`](examples/import_background.php).

### Logging

The engine takes any PSR-3 logger and, given none, records nothing.

```php
use MejiroPDF\Log\FileLogger;

$pdf = new Document(logger: $monolog);                        // your framework's logger
$pdf = new Document(logger: new FileLogger('/var/log/mp'));   // → mp_20260726.log
$pdf = new Document();                                        // NullLogger: silence
```

`FileLogger` is a convenience, not a policy: it appends to one daily file and
does **not** rotate, compress or prune — pass Monolog if you need that. It never
throws, because losing a diagnostic must not cost you the document.

Log volume tracks *problems*, not output size. A correct document logs two
lines (one at construction, one at finalize) whether it is one page or a
hundred thousand; everything else is emitted only when the engine degrades
something, such as skipping a template image it could not resolve.

There is deliberately **no `.env` support**. Configuration arrives through
constructor arguments, `readonly` option objects and explicit setters — reading
the environment from a library would make the settings hidden global state.

### Limits

```php
$pdf->setMaxPageLimit(5000);   // MP-CORE-PAGE_OVERFLOW on the page that crosses it
$pdf->setMaxPageLimit(0);      // no ceiling (the default)
```

A safety valve for reports driven by a query that unexpectedly returns a
million rows: without it, the run streams pages until the request times out and
the failure says nothing about its cause.

### Roadmap (next)

- Phase 2/3: chunk-streamed image scaling, template-PDF text editing, more.

## Development

```bash
composer install
composer test     # PHPUnit 10
composer stan     # PHPStan level 8
```

## Contributing and support

**Pull requests are not accepted** — the dual license requires the copyright to
stay with a single author, so third-party code cannot be merged. **Bug reports
are welcome** and are used to improve the library; the fix is then written by the
author. There is **no support commitment**: this is maintained by one person
alongside other work, and no response time is promised.

Please read [CONTRIBUTING.md](./CONTRIBUTING.md) before opening anything. Report
security vulnerabilities privately to <support@2bo-velocity.com>, not as a public
issue.

## License

Dual-licensed: **AGPL-3.0-or-later** (open source, requires the "Powered by
MejiroPDF" footer credit) **or a commercial license** (credit removal, priority
support). See the repository `LICENSE.md`.

© 2026 Yusuke Tsubonoue — https://mejiropdf.com
