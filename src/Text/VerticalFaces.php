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
 * The font resources a vertical run should be drawn with when the faces in play
 * offer a vertical CMap ({@see \MejiroPDF\Font\VerticalCidFont}).
 *
 * Given these, the typesetter stops arranging glyphs itself: the CMap yields
 * the vertical form of each character and advances a full em downward, so a
 * column is one `Tj` and its shapes come from the typeface. Without them it
 * falls back to stacking glyph by glyph and approximating the few forms it
 * knows about.
 *
 * Tate-chu-yoko keeps the horizontal face regardless — those characters are
 * deliberately upright and are set across the column, not down it.
 */
final readonly class VerticalFaces
{
    /**
     * @param string                   $bodyResource PDF resource name of the vertical body face
     * @param callable(string): string $bodyEncode   wraps a run in its Tj operand
     * @param string|null              $rubyResource vertical face for furigana, when it has its own
     * @param (callable(string): string)|null $rubyEncode
     */
    public function __construct(
        public string $bodyResource,
        public mixed $bodyEncode,
        public ?string $rubyResource = null,
        public mixed $rubyEncode = null,
    ) {
    }

    /**
     * The vertical furigana face, falling back to the vertical body face — a
     * ruby run set in the body's own typeface still wants vertical shapes.
     *
     * @return array{0: string, 1: callable(string): string}
     */
    public function ruby(): array
    {
        /** @var callable(string): string $encode */
        $encode = $this->rubyResource !== null && \is_callable($this->rubyEncode)
            ? $this->rubyEncode
            : $this->bodyEncode;

        /** @var callable(string): string $encode */
        return [$this->rubyResource ?? $this->bodyResource, $encode];
    }
}
