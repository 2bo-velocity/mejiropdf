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

namespace MejiroPDF\Import;

/**
 * Extracts a single page from an existing PDF for re-embedding as a Form
 * XObject (a vector background).
 *
 * Supports classic cross-reference tables **and** cross-reference streams with
 * PNG predictors (PDF 1.5+), including objects stored in object streams
 * (ObjStm). Content filtered with FlateDecode is inflated. Encrypted sources
 * raise a {@see PdfImportException}.
 *
 * The source is treated as hostile: it is typically a file someone uploaded.
 * Every count, length and reference it declares is checked against what the
 * file can actually hold before it is acted on, decompression is capped by
 * {@see MAX_INFLATED_BYTES}, and the page tree and object streams are walked
 * so that no node is visited twice. A malformed file raises MP-VAL-1004 rather
 * than exhausting memory, spinning, or recursing until the stack runs out.
 *
 * @phpstan-type Location array{t: int, offset?: int, stm?: int, idx?: int}
 */
final class PdfPageImporter
{
    /**
     * Ceiling on the bytes decompressed from one source, across all of its
     * streams. Only cross-reference streams, object streams and the imported
     * page's content are inflated (images and fonts are copied compressed), so
     * a real page stays far below this; a few hundred bytes of Flate data, on
     * the other hand, can claim a gigabyte.
     */
    public const int MAX_INFLATED_BYTES = 64 * 1024 * 1024;

    /** Size of a classic cross-reference entry, in bytes. */
    private const int XREF_ENTRY_LENGTH = 20;

    /** Inflated bytes still allowed under {@see MAX_INFLATED_BYTES}. */
    private int $inflateBudget = self::MAX_INFLATED_BYTES;

    /** @var array<int, array{t: int, offset?: int, stm?: int, idx?: int}> object number => location */
    private array $locations = [];

    /** @var array<int, list<string>> object-stream number => contained object bodies */
    private array $objStmCache = [];

    private string $trailer = '';

    private string $lastTrailerDict = '';

    private function __construct(
        private readonly string $pdf,
    ) {
        $this->readXrefChain();
    }

    public static function import(string $pdf, int $pageNumber = 1): ImportedPage
    {
        return (new self($pdf))->extract($pageNumber);
    }

    /**
     * Return every page's MediaBox ("x0 y0 x1 y1", points), in page order,
     * without collecting page content or objects. Lets a caller discover a
     * source document's page count and per-page size before importing.
     *
     * @return list<string>
     */
    public static function pageBoxes(string $pdf): array
    {
        return (new self($pdf))->boxes();
    }

    /**
     * @return list<string>
     */
    private function boxes(): array
    {
        $rootRef = $this->firstRef($this->trailer, 'Root')
            ?? throw PdfImportException::invalid('Trailer has no /Root.');
        [$catalog] = $this->object($rootRef);
        $pagesRef = $this->firstRef($catalog, 'Pages')
            ?? throw PdfImportException::invalid('Catalog has no /Pages.');

        return \array_map(
            static fn (array $page): string => $page[1],
            $this->collectPages($pagesRef, '0 0 612 792', null, 0, 0),
        );
    }

    private function extract(int $pageNumber): ImportedPage
    {
        if (\preg_match('#/Encrypt\s#', $this->trailer) === 1) {
            throw PdfImportException::unsupported('Encrypted source PDFs cannot be imported.');
        }

        $rootRef = $this->firstRef($this->trailer, 'Root')
            ?? throw PdfImportException::invalid('Trailer has no /Root.');
        [$catalog] = $this->object($rootRef);
        $pagesRef = $this->firstRef($catalog, 'Pages')
            ?? throw PdfImportException::invalid('Catalog has no /Pages.');

        $pages = $this->collectPages($pagesRef, '0 0 612 792', null, 0, 0);
        if ($pageNumber < 1 || $pageNumber > \count($pages)) {
            throw PdfImportException::invalid(
                \sprintf('Page %d is out of range (document has %d page(s)).', $pageNumber, \count($pages)),
            );
        }
        [$pageDict, $bbox, $resourcesValue, $rotate] = $pages[$pageNumber - 1];
        $resources = $this->resolveResources($resourcesValue);

        return new ImportedPage(
            bbox: $bbox,
            content: $this->pageContent($pageDict),
            resources: $resources,
            objects: $this->collectObjects($resources),
            rotate: $rotate,
        );
    }

    // ------------------------------------------------------------- xref chain

    private function readXrefChain(): void
    {
        $startPos = \strrpos($this->pdf, 'startxref');
        if ($startPos === false) {
            throw PdfImportException::invalid('No startxref found.');
        }
        $offset = (int) \trim(\substr($this->pdf, $startPos + 9, 20));

        $seen = [];
        while ($offset >= 0 && !isset($seen[$offset])) {
            $seen[$offset] = true;
            $prev = $this->readXrefSection($offset);
            if ($this->trailer === '') {
                $this->trailer = $this->lastTrailerDict;
            }
            $offset = $prev;
        }
    }

    /**
     * Read one xref section (classic table or xref stream) at $offset, adding
     * its entries (newest wins), and return the /Prev offset or -1.
     */
    private function readXrefSection(int $offset): int
    {
        $p = $offset;
        while ($p < \strlen($this->pdf) && \ctype_space($this->pdf[$p])) {
            $p++;
        }

        return \substr($this->pdf, $p, 4) === 'xref'
            ? $this->readClassicXref($p + 4)
            : $this->readXrefStream($offset);
    }

    private function readClassicXref(int $p): int
    {
        while ($p < \strlen($this->pdf) && \ctype_space($this->pdf[$p])) {
            $p++;
        }
        while (\substr($this->pdf, $p, 7) !== 'trailer') {
            $eol = \strpos($this->pdf, "\n", $p);
            if ($eol === false) {
                throw PdfImportException::invalid('Malformed xref subsection.');
            }
            $header = \preg_split('/\s+/', \trim(\substr($this->pdf, $p, $eol - $p))) ?: [];
            $start = (int) ($header[0] ?? 0);
            $count = (int) ($header[1] ?? 0);
            $p = $eol + 1;
            // Each entry is a fixed 20 bytes, so the file itself bounds how
            // many a subsection can hold; a header claiming more would
            // otherwise loop for as long as it likes over nothing.
            if ($count < 0 || $start < 0 || $count > \intdiv(\strlen($this->pdf) - $p, self::XREF_ENTRY_LENGTH)) {
                throw PdfImportException::invalid(\sprintf('Cross-reference subsection claims %d entries the file cannot hold.', $count));
            }
            for ($i = 0; $i < $count; $i++) {
                $entry = \substr($this->pdf, $p, 20);
                if (($entry[17] ?? 'f') === 'n' && !isset($this->locations[$start + $i])) {
                    $this->locations[$start + $i] = ['t' => 1, 'offset' => (int) \substr($entry, 0, 10)];
                }
                $p += self::XREF_ENTRY_LENGTH;
            }
            while ($p < \strlen($this->pdf) && \ctype_space($this->pdf[$p])) {
                $p++;
            }
        }

        $trailerEnd = \strpos($this->pdf, 'startxref', $p);
        $classicTrailer = \substr($this->pdf, $p, ($trailerEnd === false ? \strlen($this->pdf) : $trailerEnd) - $p);
        $this->lastTrailerDict = $classicTrailer;

        // Hybrid-reference file: the classic trailer also points, via /XRefStm, to
        // a cross-reference stream indexing the objects held in object streams
        // (which a pure PDF 1.4 reader would never see). Merge those entries too.
        $xrefStm = $this->firstRefValue($classicTrailer, 'XRefStm');
        if ($xrefStm >= 0) {
            $this->readXrefStream($xrefStm);
            $this->lastTrailerDict = $classicTrailer; // keep the classic trailer (/Root) authoritative
        }

        return $this->firstRefValue($classicTrailer, 'Prev');
    }

    private function readXrefStream(int $offset): int
    {
        [$dict, $stream] = $this->parseObjectAt($offset);
        if ($stream === null) {
            throw PdfImportException::invalid('Cross-reference object has no stream.');
        }
        $this->lastTrailerDict = $dict;

        if (\str_contains($dict, '/FlateDecode')) {
            $stream = $this->inflate($stream);
        }
        $stream = $this->applyPredictor($stream, $dict);

        if (\preg_match('/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]/', $dict, $wm) !== 1) {
            throw PdfImportException::invalid('Cross-reference stream has no /W.');
        }
        $w = [(int) $wm[1], (int) $wm[2], (int) $wm[3]];
        $rowLength = $w[0] + $w[1] + $w[2];

        $size = \preg_match('/\/Size\s+(\d+)/', $dict, $sm) === 1 ? (int) $sm[1] : 0;
        $index = $this->indexArray($dict, $size);

        $pos = 0;
        foreach ($index as [$start, $count]) {
            for ($i = 0; $i < $count; $i++) {
                if ($pos + $rowLength > \strlen($stream)) {
                    break 2;
                }
                $type = $w[0] === 0 ? 1 : $this->beInt($stream, $pos, $w[0]);
                $field2 = $this->beInt($stream, $pos + $w[0], $w[1]);
                $field3 = $this->beInt($stream, $pos + $w[0] + $w[1], $w[2]);
                $pos += $rowLength;

                $number = $start + $i;
                if (isset($this->locations[$number]) || $type === 0) {
                    continue;
                }
                $this->locations[$number] = $type === 2
                    ? ['t' => 2, 'stm' => $field2, 'idx' => $field3]
                    : ['t' => 1, 'offset' => $field2];
            }
        }

        return $this->firstRefValue($dict, 'Prev');
    }

    /**
     * @return list<array{int, int}> [start, count] subsections
     */
    private function indexArray(string $dict, int $size): array
    {
        if (\preg_match('/\/Index\s*\[([^\]]+)\]/', $dict, $im) === 1) {
            $numbers = \array_map('intval', \preg_split('/\s+/', \trim($im[1])) ?: []);
            $pairs = [];
            for ($i = 0; $i + 1 < \count($numbers); $i += 2) {
                $pairs[] = [$numbers[$i], $numbers[$i + 1]];
            }

            return $pairs;
        }

        return [[0, $size]];
    }

    private function applyPredictor(string $data, string $dict): string
    {
        if (\preg_match('/\/Predictor\s+(\d+)/', $dict, $pm) !== 1 || (int) $pm[1] < 10) {
            return $data;
        }
        $columns = \preg_match('/\/Columns\s+(\d+)/', $dict, $cm) === 1 ? (int) $cm[1] : 1;
        // A row wider than the data holds no row at all; refusing it here keeps
        // the zero-filled "previous row" from being sized by the claim alone.
        if ($columns < 1 || $columns >= \strlen($data)) {
            return '';
        }

        $out = '';
        $prev = \str_repeat("\x00", $columns);
        $pos = 0;
        $rowLength = $columns + 1; // 1 PNG filter tag byte per row
        while ($pos + $rowLength <= \strlen($data)) {
            $tag = \ord($data[$pos]);
            $row = \substr($data, $pos + 1, $columns);
            $decoded = '';
            for ($i = 0; $i < $columns; $i++) {
                $value = \ord($row[$i]);
                $up = \ord($prev[$i]);
                $left = $i > 0 ? \ord($decoded[$i - 1]) : 0;
                $upperLeft = $i > 0 ? \ord($prev[$i - 1]) : 0;
                $decoded .= \chr(match ($tag) {
                    1 => $value + $left,
                    2 => $value + $up,
                    3 => $value + \intdiv($left + $up, 2),
                    4 => $value + $this->paeth($left, $up, $upperLeft),
                    default => $value,
                } & 0xFF);
            }
            $out .= $decoded;
            $prev = $decoded;
            $pos += $rowLength;
        }

        return $out;
    }

    // --------------------------------------------------------------- objects

    /**
     * @return array{string, string|null} dictionary and stream data
     */
    private function object(int $number): array
    {
        $loc = $this->locations[$number] ?? throw PdfImportException::invalid("Referenced object {$number} is missing.");

        if ($loc['t'] === 2) {
            return [$this->objectFromStream($loc['stm'] ?? 0, $loc['idx'] ?? 0), null];
        }

        return $this->parseObjectAt($loc['offset'] ?? 0);
    }

    /**
     * @return array{string, string|null}
     */
    private function parseObjectAt(int $offset): array
    {
        if ($offset < 0 || $offset >= \strlen($this->pdf)) {
            throw PdfImportException::invalid("Object offset {$offset} lies outside the file.");
        }
        $objPos = \strpos($this->pdf, 'obj', $offset);
        if ($objPos === false) {
            throw PdfImportException::invalid('Object has no \'obj\' keyword.');
        }
        $start = $objPos + 3;
        $end = \strpos($this->pdf, 'endobj', $start);
        if ($end === false) {
            throw PdfImportException::invalid('Object has no \'endobj\'.');
        }
        $body = \substr($this->pdf, $start, $end - $start);

        if (\preg_match('/stream\r?\n/', $body, $m, \PREG_OFFSET_CAPTURE) === 1) {
            $dict = \substr($body, 0, (int) $m[0][1]);
            $streamStart = $start + (int) $m[0][1] + \strlen($m[0][0]);
            \preg_match('/\/Length\s+(\d+)/', $dict, $lengthMatch);

            return [\trim($dict), \substr($this->pdf, $streamStart, (int) ($lengthMatch[1] ?? 0))];
        }

        return [\trim($body), null];
    }

    private function objectFromStream(int $streamNumber, int $index): string
    {
        if (!isset($this->objStmCache[$streamNumber])) {
            // An object stream may not itself live in an object stream (PDF
            // 32000-1 §7.5.7). Holding sources to that is also what keeps a
            // stream that claims to contain itself from recursing forever.
            if (($this->locations[$streamNumber]['t'] ?? null) !== 1) {
                throw PdfImportException::invalid("Object stream {$streamNumber} is not a top-level object.");
            }
            $this->objStmCache[$streamNumber] = $this->parseObjectStream($streamNumber);
        }

        return $this->objStmCache[$streamNumber][$index]
            ?? throw PdfImportException::invalid("Object stream {$streamNumber} has no item {$index}.");
    }

    /**
     * @return list<string>
     */
    private function parseObjectStream(int $streamNumber): array
    {
        [$dict, $stream] = $this->object($streamNumber);
        if ($stream === null) {
            throw PdfImportException::invalid("Object stream {$streamNumber} has no data.");
        }
        if (\str_contains($dict, '/FlateDecode')) {
            $stream = $this->inflate($stream);
        }

        $count = \preg_match('/\/N\s+(\d+)/', $dict, $nm) === 1 ? (int) $nm[1] : 0;
        $first = \preg_match('/\/First\s+(\d+)/', $dict, $fm) === 1 ? (int) $fm[1] : 0;

        $header = \array_map('intval', \preg_split('/\s+/', \trim(\substr($stream, 0, $first))) ?: []);
        // The header lists a number/offset pair per object, so /N can be no
        // larger than what the header actually carries.
        if ($count < 0 || $count * 2 > \count($header)) {
            throw PdfImportException::invalid(\sprintf('Object stream %d claims %d objects but indexes %d.', $streamNumber, $count, \intdiv(\count($header), 2)));
        }
        $objects = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $first + ($header[$i * 2 + 1] ?? 0);
            $end = $i + 1 < $count ? $first + ($header[($i + 1) * 2 + 1] ?? 0) : \strlen($stream);
            $objects[] = \trim(\substr($stream, $start, $end - $start));
        }

        return $objects;
    }

    // ------------------------------------------------------------ page model

    /**
     * @param  string|null $inheritedResources raw /Resources value inherited from an ancestor node
     * @param  int         $inheritedRotate    /Rotate inherited from an ancestor node
     * @param  array<int, true> $visited      every node already walked
     * @return list<array{string, string, string|null, int}> [pageDict, mediaBox, resourcesValue, rotate]
     */
    private function collectPages(
        int $node,
        string $inheritedBox,
        ?string $inheritedResources,
        int $inheritedRotate,
        int $depth,
        array &$visited = [],
    ): array {
        if ($depth > 50) {
            throw PdfImportException::invalid('Page tree nesting too deep.');
        }
        // The page tree is a tree: no node has two parents. Without this a
        // node listing the same child twice at every level doubles the walk
        // per level, and 40 levels of a few kilobytes become 2^40 pages.
        if (isset($visited[$node])) {
            throw PdfImportException::invalid("Page tree reaches object {$node} more than once.");
        }
        $visited[$node] = true;
        [$dict] = $this->object($node);
        $box = $this->mediaBox($dict) ?? $inheritedBox;
        // /Resources and /Rotate are both inheritable attributes (PDF 32000-1
        // §7.7.3.4): a producer may set the rotation once on the page tree root
        // and leave every page silent about it.
        $resources = $this->rawValue($dict, 'Resources') ?? $inheritedResources;
        $rotate = $this->rotate($dict) ?? $inheritedRotate;

        if (\preg_match('#/Type\s*/Pages\b#', $dict) === 1 || \preg_match('#/Kids\b#', $dict) === 1) {
            $pages = [];
            \preg_match_all('/(\d+)\s+\d+\s+R/', $this->arrayValue($dict, 'Kids'), $kids);
            foreach ($kids[1] as $kid) {
                foreach ($this->collectPages((int) $kid, $box, $resources, $rotate, $depth + 1, $visited) as $page) {
                    $pages[] = $page;
                }
            }

            return $pages;
        }

        return [[$dict, $box, $resources, $rotate]];
    }

    private function pageContent(string $pageDict): string
    {
        $content = '';
        \preg_match_all('/(\d+)\s+\d+\s+R/', $this->arrayValue($pageDict, 'Contents'), $refs);
        foreach ($refs[1] as $ref) {
            [$dict, $stream] = $this->object((int) $ref);
            if ($stream === null) {
                continue;
            }
            if (\str_contains($dict, '/FlateDecode')) {
                $stream = $this->inflate($stream);
            }
            $content .= $stream . "\n";
        }

        return $content;
    }

    /**
     * Resolve a (possibly inherited) raw /Resources value — an inline dictionary
     * or an indirect reference — to a dictionary string.
     */
    private function resolveResources(?string $value): string
    {
        if ($value === null || $value === '') {
            return '<< >>';
        }
        if ($value[0] === '<') {
            return $value;
        }
        if (\preg_match('/^(\d+)\s+\d+\s+R/', $value, $m) === 1) {
            [$dict] = $this->object((int) $m[1]);

            return $dict;
        }

        return '<< >>';
    }

    /**
     * @return array<int, array{dict: string, stream: string|null}>
     */
    private function collectObjects(string $dict): array
    {
        $collected = [];
        $stack = $this->refs($dict);
        while ($stack !== []) {
            $number = \array_pop($stack);
            if (isset($collected[$number])) {
                continue;
            }
            [$objectDict, $stream] = $this->object($number);

            if ($stream !== null) {
                $inner = \preg_replace('/^\s*<<(.*)>>\s*$/s', '$1', $objectDict) ?? $objectDict;
                $inner = \preg_replace('/\/Length\s+\d+/', '', $inner) ?? $inner;
                $collected[$number] = ['dict' => \trim($inner), 'stream' => $stream];
            } else {
                $collected[$number] = ['dict' => $objectDict, 'stream' => null];
            }

            foreach ($this->refs($objectDict) as $ref) {
                if (!isset($collected[$ref])) {
                    $stack[] = $ref;
                }
            }
        }

        return $collected;
    }

    // ---------------------------------------------------------------- helpers

    private function mediaBox(string $dict): ?string
    {
        return \preg_match('/\/MediaBox\s*\[([^\]]+)\]/', $dict, $m) === 1 ? \trim($m[1]) : null;
    }

    /**
     * The node's own /Rotate, normalised to 0, 90, 180 or 270, or null when it
     * does not carry one and should inherit.
     *
     * Scanners and office suites routinely write a portrait MediaBox plus
     * /Rotate 90 rather than a landscape box, and negative values are just as
     * common as positive ones, so both have to survive the trip.
     */
    private function rotate(string $dict): ?int
    {
        if (\preg_match('/\/Rotate\s+(-?\d+)/', $dict, $m) !== 1) {
            return null;
        }

        $degrees = ((int) $m[1] % 360 + 360) % 360;

        // The spec admits only multiples of 90. Anything else is a broken
        // producer, and turning the page by a guess would lose more than
        // leaving it as written.
        return $degrees % 90 === 0 ? $degrees : 0;
    }

    private function firstRef(string $dict, string $key): ?int
    {
        return \preg_match('#/' . $key . '\s+(\d+)\s+\d+\s+R#', $dict, $m) === 1 ? (int) $m[1] : null;
    }

    private function firstRefValue(string $dict, string $key): int
    {
        return \preg_match('#/' . $key . '\s+(\d+)#', $dict, $m) === 1 ? (int) $m[1] : -1;
    }

    private function beInt(string $data, int $offset, int $length): int
    {
        $value = 0;
        for ($i = 0; $i < $length; $i++) {
            $value = ($value << 8) | \ord($data[$offset + $i]);
        }

        return $value;
    }

    /**
     * Inflate a FlateDecode stream within the remaining budget.
     *
     * Decoded incrementally so that a stream breaching the budget is stopped
     * as it grows rather than after it has been fully allocated. Data that is
     * not valid zlib is returned as is, as it always was: some producers flag
     * streams they never compressed, and the caller copes with raw bytes.
     */
    private function inflate(string $data): string
    {
        $context = \inflate_init(\ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            return $data;
        }

        $out = '';
        foreach (\str_split($data, 8192) as $chunk) {
            $piece = @\inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($piece === false) {
                return $data;
            }
            $out .= $piece;
            if (\strlen($out) > $this->inflateBudget) {
                throw PdfImportException::unsupported(\sprintf(
                    'Source decompresses to more than %d bytes.',
                    self::MAX_INFLATED_BYTES,
                ));
            }
            if (\inflate_get_status($context) === \ZLIB_STREAM_END) {
                break;
            }
        }
        $this->inflateBudget -= \strlen($out);

        return $out;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = \abs($p - $a);
        $pb = \abs($p - $b);
        $pc = \abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
    }

    private function rawValue(string $dict, string $key): ?string
    {
        $pos = \strpos($dict, '/' . $key);
        if ($pos === false) {
            return null;
        }
        $p = $pos + \strlen($key) + 1;
        while ($p < \strlen($dict) && \ctype_space($dict[$p])) {
            $p++;
        }
        if ($p >= \strlen($dict)) {
            return null;
        }
        if ($dict[$p] === '<' && ($dict[$p + 1] ?? '') === '<') {
            return \substr($dict, $p, $this->balanced($dict, $p) - $p);
        }

        return \trim(\substr($dict, $p, 40));
    }

    private function arrayValue(string $dict, string $key): string
    {
        $value = $this->rawValue($dict, $key);
        if ($value === null) {
            return '';
        }
        if ($value[0] === '[') {
            $end = \strpos($value, ']');

            return $end === false ? $value : \substr($value, 0, $end);
        }

        return $value;
    }

    /**
     * @return list<int>
     */
    private function refs(string $text): array
    {
        \preg_match_all('/(\d+)\s+\d+\s+R/', $text, $m);

        return \array_map('intval', $m[1]);
    }

    private function balanced(string $s, int $i): int
    {
        $depth = 0;
        $n = \strlen($s);
        while ($i < $n - 1) {
            if ($s[$i] === '<' && $s[$i + 1] === '<') {
                $depth++;
                $i += 2;
            } elseif ($s[$i] === '>' && $s[$i + 1] === '>') {
                $depth--;
                $i += 2;
                if ($depth === 0) {
                    return $i;
                }
            } else {
                $i++;
            }
        }

        return $n;
    }
}
