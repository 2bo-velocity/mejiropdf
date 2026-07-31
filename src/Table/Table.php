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

namespace MejiroPDF\Table;

use MejiroPDF\Document;
use MejiroPDF\Font\FontFace;
use MejiroPDF\Font\FontMetrics;
use MejiroPDF\Font\FontMetricsInterface;
use MejiroPDF\Font\StandardFont;
use MejiroPDF\Page\Alignment;
use MejiroPDF\Page\PageSize;
use MejiroPDF\Text\TextWrapper;

/**
 * Row-by-row streaming table.
 *
 * Each row is measured (with real font metrics, so cells wrap), drawn, and
 * immediately handed to the {@see Document} for streaming. When a row would
 * cross the bottom limit a new page is started automatically and the header row
 * is repeated — so a 100,000-row report costs no more memory than one row.
 *
 * All geometry is in millimetres (font size in points).
 */
final class Table
{
    private readonly FontMetricsInterface $metrics;
    private readonly TextWrapper $wrapper;
    private readonly float $startY;

    private float $currentY;
    private float $lineHeight = 1.3;
    private float $bottomMargin = 15.0;
    private ?string $borderColor = '#333333';
    private float $borderWeight = 0.2;
    private ?string $headerFill = '#EEEEEE';
    private TableBorder $borderStyle = TableBorder::GRID;

    /** @var array{0: ?string, 1: ?string}|null Alternating data-row fills. */
    private ?array $zebra = null;

    private int $dataRowCount = 0;

    /** @var list<string>|null Header cells, retained for repeat-on-break. */
    private ?array $headerCells = null;

    /**
     * @param list<Column> $columns
     */
    public function __construct(
        private readonly Document $doc,
        private readonly array $columns,
        private readonly float $startX,
        float $startY,
        private readonly FontFace $font = StandardFont::HELVETICA,
        private readonly float $fontSize = 9.0,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException('A table needs at least one column.');
        }

        $this->metrics = FontMetrics::for($font);
        $this->wrapper = new TextWrapper($this->metrics, $fontSize);
        $this->startY = $startY;
        $this->currentY = $startY;
    }

    public function lineHeight(float $factor): self
    {
        $this->lineHeight = $factor;

        return $this;
    }

    public function bottomMargin(float $millimeters): self
    {
        $this->bottomMargin = $millimeters;

        return $this;
    }

    public function border(?string $hexColor, float $weight = 0.2): self
    {
        $this->borderColor = $hexColor;
        $this->borderWeight = $weight;

        return $this;
    }

    public function headerFill(?string $hexColor): self
    {
        $this->headerFill = $hexColor;

        return $this;
    }

    /**
     * Alternate data-row background colours (pass null for a transparent band).
     */
    public function zebra(?string $first = '#F5F7FA', ?string $second = null): self
    {
        $this->zebra = [$first, $second];

        return $this;
    }

    public function borders(TableBorder $style): self
    {
        $this->borderStyle = $style;

        return $this;
    }

    /**
     * Draw (and remember, for page-break repeats) the header row.
     *
     * @param list<string> $cells
     */
    public function header(array $cells): self
    {
        $this->headerCells = $cells;
        $this->drawRow($cells, isHeader: true);

        return $this;
    }

    /**
     * Draw one data row, starting a new page first if it would overflow.
     *
     * @param list<string> $cells
     */
    public function row(array $cells): self
    {
        $this->drawRow($cells, isHeader: false);

        return $this;
    }

    /**
     * The Y position (mm from the page top) just below the last drawn row.
     */
    public function currentY(): float
    {
        return $this->currentY;
    }

    /**
     * @param list<string> $cells
     */
    private function drawRow(array $cells, bool $isHeader): void
    {
        $fontSizeMm = $this->fontSize / PageSize::MM_TO_PT;
        $lineHeightMm = $fontSizeMm * $this->lineHeight;
        $padMm = $this->maxPadding();

        // 1. Wrap each cell and find the tallest.
        $wrapped = [];
        $maxLines = 1;
        foreach ($this->columns as $i => $col) {
            $printableMm = \max(0.0, $col->width - 2 * $col->padding);
            $lines = $this->wrapper->wrap($cells[$i] ?? '', PageSize::mm($printableMm));
            $wrapped[$i] = $lines;
            $maxLines = \max($maxLines, \count($lines));
        }

        $rowHeightMm = $maxLines * $lineHeightMm + 2 * $padMm;

        // 2. Page break (never on the very first row of a fresh page).
        $limit = $this->doc->pageHeightMm() - $this->bottomMargin;
        if ($this->currentY > $this->startY && ($this->currentY + $rowHeightMm) > $limit) {
            $this->doc->addPage();
            $this->currentY = $this->startY;
            if (!$isHeader && $this->headerCells !== null) {
                $this->drawRow($this->headerCells, isHeader: true);
            }
        }

        // 3. Row background.
        $totalWidth = 0.0;
        foreach ($this->columns as $col) {
            $totalWidth += $col->width;
        }
        $fill = $isHeader ? $this->headerFill : $this->rowFill();
        if ($fill !== null) {
            $this->doc->rect($this->startX, $this->currentY, $totalWidth, $rowHeightMm, 0.0, fill: $fill);
        }

        // 4. Borders.
        if ($this->borderStyle === TableBorder::GRID && $this->borderColor !== null) {
            $x = $this->startX;
            foreach ($this->columns as $col) {
                $this->doc->rect($x, $this->currentY, $col->width, $rowHeightMm, $this->borderWeight, stroke: $this->borderColor);
                $x += $col->width;
            }
        } elseif ($this->borderStyle === TableBorder::HORIZONTAL && $this->borderColor !== null) {
            $bottom = $this->currentY + $rowHeightMm;
            $this->doc->line($this->startX, $bottom, $this->startX + $totalWidth, $bottom, $this->borderWeight, $this->borderColor);
        }

        // 5. Cell text.
        $x = $this->startX;
        foreach ($this->columns as $i => $col) {
            $baseline = $this->currentY + $padMm + $fontSizeMm;
            foreach ($wrapped[$i] as $line) {
                $textWidthMm = $this->metrics->textWidth($line, $this->fontSize) / PageSize::MM_TO_PT;
                $cellX = match ($col->align) {
                    Alignment::LEFT => $x + $col->padding,
                    Alignment::RIGHT => $x + $col->width - $col->padding - $textWidthMm,
                    Alignment::CENTER => $x + ($col->width - $textWidthMm) / 2.0,
                };
                $this->doc->text($cellX, $baseline, $line, $this->font, $this->fontSize);
                $baseline += $lineHeightMm;
            }
            $x += $col->width;
        }

        $this->currentY += $rowHeightMm;
    }

    /**
     * The background colour for the current data row (advances the zebra count).
     */
    private function rowFill(): ?string
    {
        if ($this->zebra === null) {
            return null;
        }

        $color = $this->dataRowCount % 2 === 0 ? $this->zebra[0] : $this->zebra[1];
        $this->dataRowCount++;

        return $color;
    }

    private function maxPadding(): float
    {
        $max = 0.0;
        foreach ($this->columns as $col) {
            $max = \max($max, $col->padding);
        }

        return $max;
    }
}
