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

namespace MejiroPDF\Writer;

use MejiroPDF\Security\StandardSecurityHandler;
use MejiroPDF\Stream\StreamWriterInterface;

/**
 * Writes the low-level structure of a PDF file: header, indirect objects, the
 * cross-reference (xref) table and the trailer.
 *
 * Object ids may be allocated up front (e.g. to reserve the Catalog and Pages
 * ids) and their bodies written later; the xref is assembled from the recorded
 * byte offsets regardless of write order.
 */
final class DocumentWriter
{
    private const string PDF_VERSION = '1.7';

    /** @var array<int, int> object id => byte offset of "id 0 obj". */
    private array $xref = [];

    private int $nextId = 1;

    private ?StandardSecurityHandler $security = null;

    public function __construct(
        private readonly StreamWriterInterface $writer,
    ) {
    }

    /**
     * Turn on standard encryption: from now on, every stream this writer emits
     * is encrypted with the object's key.
     */
    public function enableEncryption(StandardSecurityHandler $security): void
    {
        $this->security = $security;
    }

    public function writer(): StreamWriterInterface
    {
        return $this->writer;
    }

    /**
     * Reserve the next object id without writing anything yet.
     */
    public function allocateObjectId(): int
    {
        return $this->nextId++;
    }

    /**
     * Emit the PDF header: version plus a high-byte binary marker so that naive
     * transports treat the file as binary.
     */
    public function writeHeader(string $version = self::PDF_VERSION): void
    {
        $this->writer->writeLine('%PDF-' . $version);
        $this->writer->writeLine("%\xE2\xE3\xCF\xD3");
    }

    /**
     * Begin an indirect object, recording its offset for the xref table.
     */
    public function startObject(int $objectId): void
    {
        $this->xref[$objectId] = $this->writer->getOffset();
        $this->writer->writeLine("{$objectId} 0 obj");
    }

    public function endObject(): void
    {
        $this->writer->writeLine('endobj');
    }

    /**
     * Write a complete indirect object whose body is a single dictionary/value.
     */
    public function writeObject(int $objectId, string $body): void
    {
        $this->startObject($objectId);
        $this->writer->writeLine($body);
        $this->endObject();
    }

    /**
     * Write a complete stream object with a correct /Length.
     *
     * @param string $dict Extra dictionary entries (without the surrounding <<>>),
     *                     e.g. "/Type /XObject /Subtype /Form".
     */
    public function writeStreamObject(int $objectId, string $streamData, string $dict = ''): void
    {
        if ($this->security !== null) {
            $streamData = $this->security->encrypt($objectId, $streamData);
        }

        $length = \strlen($streamData);
        $extra = $dict === '' ? '' : ' ' . $dict;

        $this->startObject($objectId);
        $this->writer->writeLine("<< /Length {$length}{$extra} >>");
        $this->writer->writeLine('stream');
        $this->writer->write($streamData);
        $this->writer->writeLine("\nendstream");
        $this->endObject();
    }

    /**
     * Write an object copied verbatim from another PDF (used by page import).
     * For stream objects, pass the inner dict WITHOUT /Length (it is added, and
     * the stream is encrypted, here); for others pass the full `<< … >>` dict.
     */
    public function writeRawObject(int $objectId, string $dict, ?string $streamData = null): void
    {
        $this->xref[$objectId] = $this->writer->getOffset();
        $this->writer->writeLine("{$objectId} 0 obj");

        if ($streamData === null) {
            $this->writer->writeLine($dict);
            $this->writer->writeLine('endobj');

            return;
        }

        if ($this->security !== null) {
            $streamData = $this->security->encrypt($objectId, $streamData);
        }
        $this->writer->writeLine(\sprintf('<< %s /Length %d >>', $dict, \strlen($streamData)));
        $this->writer->writeLine('stream');
        $this->writer->write($streamData);
        $this->writer->writeLine("\nendstream");
        $this->writer->writeLine('endobj');
    }

    /**
     * Finalise the document: write the xref table, trailer, startxref and %%EOF.
     *
     * @param int|null    $encryptId Object id of the /Encrypt dictionary, if encrypted.
     * @param string|null $idHex     File identifier as hex (required when encrypted).
     * @param int|null    $infoId    Object id of the /Info (document information) dictionary, if any.
     */
    public function finalize(int $rootObjectId, ?int $encryptId = null, ?string $idHex = null, ?int $infoId = null): void
    {
        $startXref = $this->writer->getOffset();
        $size = $this->nextId; // object 0 (free head) + all allocated objects.

        $this->writer->writeLine('xref');
        $this->writer->writeLine("0 {$size}");
        $this->writer->writeLine('0000000000 65535 f ');

        \ksort($this->xref);
        for ($id = 1; $id < $this->nextId; $id++) {
            $offset = $this->xref[$id] ?? 0;
            $this->writer->writeLine(\sprintf('%010d 00000 n ', $offset));
        }

        $trailer = "<< /Size {$size} /Root {$rootObjectId} 0 R";
        if ($infoId !== null) {
            $trailer .= " /Info {$infoId} 0 R";
        }
        if ($encryptId !== null) {
            $trailer .= " /Encrypt {$encryptId} 0 R";
        }
        if ($idHex !== null) {
            $trailer .= " /ID [ <{$idHex}> <{$idHex}> ]";
        }
        $trailer .= ' >>';

        $this->writer->writeLine('trailer');
        $this->writer->writeLine($trailer);
        $this->writer->writeLine('startxref');
        $this->writer->writeLine((string) $startXref);
        $this->writer->write('%%EOF');
    }
}
