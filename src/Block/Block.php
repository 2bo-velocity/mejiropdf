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

namespace MejiroPDF\Block;

/**
 * One structural piece of a report: a titled group of freely-placed elements
 * that draws once, once per row, or side by side with its neighbour.
 *
 * A block answers three questions the free-placement layer cannot: **does this
 * appear at all** ({@see $condition}), **where does it start** ({@see $positionMode}),
 * and **how many times** ({@see $kind}). Everything inside it keeps the absolute
 * coordinates a designer laid out, now measured from the block's own corner.
 *
 * Use the named constructors rather than the constructor: each kind reads only
 * some of the fields, and the factories make clear which.
 */
final readonly class Block
{
    /**
     * @param ElementSet|null       $header  REPEATER: column headings, redrawn per page when asked.
     * @param ElementSet|null       $item    REPEATER: one row, drawn per element of {@see $dataSource}.
     * @param ElementSet|null       $footer  REPEATER: a subtotal below the rows.
     * @param ElementSet|null       $headerPage2 REPEATER: a compact heading for continuation pages.
     * @param list<BlockColumn>     $columns COLUMNS: the side-by-side tracks.
     */
    private function __construct(
        public string $id,
        public BlockKind $kind,
        public ElementSet $elements,
        public PositionMode $positionMode = PositionMode::FLOW,
        public ?string $condition = null,
        public bool $visible = true,
        public float $x = 0.0,
        public float $y = 0.0,
        public float $width = 0.0,
        public float $marginBottom = 0.0,
        public string $name = '',
        public string $dataSource = '',
        public bool $repeatHeaderOnPageBreak = true,
        public ?ElementSet $header = null,
        public ?ElementSet $item = null,
        public ?ElementSet $footer = null,
        public ?ElementSet $headerPage2 = null,
        public array $columns = [],
        public bool $equalHeight = true,
    ) {
    }

    /**
     * A block drawn once — a title, an address panel, a bank-details box.
     */
    public static function single(
        string $id,
        ElementSet $elements,
        PositionMode $positionMode = PositionMode::FLOW,
        ?string $condition = null,
        float $x = 0.0,
        float $y = 0.0,
        float $width = 0.0,
        float $marginBottom = 0.0,
        bool $visible = true,
        string $name = '',
    ): self {
        return new self(
            id: $id,
            kind: BlockKind::SINGLE,
            elements: $elements,
            positionMode: $positionMode,
            condition: $condition,
            visible: $visible,
            x: $x,
            y: $y,
            width: $width,
            marginBottom: $marginBottom,
            name: $name,
        );
    }

    /**
     * A block drawn once per row of $dataSource.
     *
     * $header is redrawn at the top of each continuation page unless
     * $repeatHeaderOnPageBreak is false — a table whose headings appear only on
     * page one is unreadable by page two.
     */
    public static function repeater(
        string $id,
        string $dataSource,
        ElementSet $item,
        ?ElementSet $header = null,
        ?ElementSet $footer = null,
        ?ElementSet $headerPage2 = null,
        bool $repeatHeaderOnPageBreak = true,
        PositionMode $positionMode = PositionMode::FLOW,
        ?string $condition = null,
        float $x = 0.0,
        float $y = 0.0,
        float $width = 0.0,
        float $marginBottom = 0.0,
        bool $visible = true,
        string $name = '',
    ): self {
        return new self(
            id: $id,
            kind: BlockKind::REPEATER,
            elements: new ElementSet(),
            positionMode: $positionMode,
            condition: $condition,
            visible: $visible,
            x: $x,
            y: $y,
            width: $width,
            marginBottom: $marginBottom,
            name: $name,
            dataSource: $dataSource,
            repeatHeaderOnPageBreak: $repeatHeaderOnPageBreak,
            header: $header,
            item: $item,
            footer: $footer,
            headerPage2: $headerPage2,
        );
    }

    /**
     * A block whose columns sit side by side.
     *
     * With $equalHeight the container is as tall as its tallest column, so the
     * next flow block clears all of them — without it, a long address on the
     * left would be overwritten by whatever comes next.
     *
     * @param list<BlockColumn> $columns
     */
    public static function columns(
        string $id,
        array $columns,
        bool $equalHeight = true,
        PositionMode $positionMode = PositionMode::FLOW,
        ?string $condition = null,
        float $x = 0.0,
        float $y = 0.0,
        float $width = 0.0,
        float $marginBottom = 0.0,
        bool $visible = true,
        string $name = '',
    ): self {
        return new self(
            id: $id,
            kind: BlockKind::COLUMNS,
            elements: new ElementSet(),
            positionMode: $positionMode,
            condition: $condition,
            visible: $visible,
            x: $x,
            y: $y,
            width: $width,
            marginBottom: $marginBottom,
            name: $name,
            columns: \array_values($columns),
            equalHeight: $equalHeight,
        );
    }

    /**
     * Whether this block draws for the given record.
     *
     * {@see $visible} is the designer's switch in the editor; {@see $condition}
     * is the per-record rule. Both must pass.
     *
     * @param array<string, mixed> $data
     */
    public function appearsIn(array $data): bool
    {
        return $this->visible && Condition::isTrue($this->condition, $data);
    }
}
