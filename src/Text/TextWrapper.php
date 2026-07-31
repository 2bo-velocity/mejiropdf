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

namespace MejiroPDF\Text;

use MejiroPDF\Font\FontMetricsInterface;

/**
 * Word-wraps text to a maximum width using real font metrics. A word that is
 * itself wider than the box is broken character-by-character.
 */
final readonly class TextWrapper
{
    public function __construct(
        private FontMetricsInterface $metrics,
        private float $fontSize,
    ) {
    }

    /**
     * Split text into lines that each fit within $maxWidth points.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $maxWidth): array
    {
        if ($text === '' || $maxWidth <= 0.0) {
            return [''];
        }

        $paragraphs = \preg_split('/\r\n|\r|\n/', $text) ?: [$text];
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $this->wrapParagraph($paragraph, $maxWidth, $lines);
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function wrapParagraph(string $paragraph, float $maxWidth, array &$lines): void
    {
        $words = \explode(' ', $paragraph);
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->metrics->textWidth($candidate, $this->fontSize) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            if ($this->metrics->textWidth($word, $this->fontSize) > $maxWidth) {
                $current = $this->breakLongWord($word, $maxWidth, $lines);
            } else {
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }
    }

    /**
     * Break a single over-long word character-by-character; returns the tail
     * that still fits on the current line.
     *
     * @param list<string> $lines
     */
    private function breakLongWord(string $word, float $maxWidth, array &$lines): string
    {
        $current = '';
        $chars = \mb_str_split($word, 1, 'UTF-8');

        foreach ($chars as $char) {
            $candidate = $current . $char;

            if ($this->metrics->textWidth($candidate, $this->fontSize) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $char;
        }

        return $current;
    }
}
