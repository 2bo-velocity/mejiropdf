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

/**
 * The inline visual state carried by a {@see TextToken}: emphasis, decoration,
 * colour, size override and an optional hyperlink. Colours are "#RRGGBB";
 * `fontSize`/`letterSpacing` are in points (null = inherit the caller's value).
 */
final readonly class TextStyle
{
    public function __construct(
        public bool $isBold = false,
        public bool $isItalic = false,
        public bool $isUnderline = false,
        public bool $isStrikethrough = false,
        public bool $isBouten = false,
        public bool $isTcy = false,
        public bool $isDropCap = false,
        public ?string $color = null,
        public ?string $backgroundColor = null,
        public ?float $fontSize = null,
        public ?float $letterSpacing = null,
        public ?string $linkUrl = null,
    ) {
    }

    /**
     * Return a copy with the given attributes overridden. Only non-null
     * arguments take effect (null = "leave unchanged"), so this method **sets**
     * — it never clears — a style attribute, which is exactly what nesting
     * inline spans (bold inside a link, etc.) requires.
     */
    public function with(
        ?bool $isBold = null,
        ?bool $isItalic = null,
        ?bool $isUnderline = null,
        ?bool $isStrikethrough = null,
        ?bool $isBouten = null,
        ?bool $isTcy = null,
        ?bool $isDropCap = null,
        ?string $color = null,
        ?string $backgroundColor = null,
        ?float $fontSize = null,
        ?float $letterSpacing = null,
        ?string $linkUrl = null,
    ): self {
        return new self(
            $isBold ?? $this->isBold,
            $isItalic ?? $this->isItalic,
            $isUnderline ?? $this->isUnderline,
            $isStrikethrough ?? $this->isStrikethrough,
            $isBouten ?? $this->isBouten,
            $isTcy ?? $this->isTcy,
            $isDropCap ?? $this->isDropCap,
            $color ?? $this->color,
            $backgroundColor ?? $this->backgroundColor,
            $fontSize ?? $this->fontSize,
            $letterSpacing ?? $this->letterSpacing,
            $linkUrl ?? $this->linkUrl,
        );
    }

    /**
     * Layer this style over $base: attributes this style leaves unset fall
     * through to the base, and boolean marks accumulate.
     *
     * Unlike {@see with()} — which asks "override these attributes" — this asks
     * "what should a run styled by inline markup look like inside a block that
     * already has a colour, or is underlined as a whole?". Booleans are OR-ed
     * because a base that underlines the block must not be cancelled by a run
     * that simply never mentioned underlining. `isDropCap` is excluded: it
     * belongs to one character, never to a whole block.
     */
    public function over(self $base): self
    {
        return new self(
            $this->isBold || $base->isBold,
            $this->isItalic || $base->isItalic,
            $this->isUnderline || $base->isUnderline,
            $this->isStrikethrough || $base->isStrikethrough,
            $this->isBouten || $base->isBouten,
            $this->isTcy || $base->isTcy,
            $this->isDropCap,
            $this->color ?? $base->color,
            $this->backgroundColor ?? $base->backgroundColor,
            $this->fontSize ?? $base->fontSize,
            $this->letterSpacing ?? $base->letterSpacing,
            $this->linkUrl ?? $base->linkUrl,
        );
    }
}
