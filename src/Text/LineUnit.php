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
 * The smallest positionable atom the {@see LineBreaker} works with: one
 * character, or one ruby group (parent text + furigana), which is unbreakable.
 * {@see $width} is the pre-measured advance in points at {@see $fontSize} — the
 * larger of {@see $parentWidth} and {@see $rubyWidth} — so the typesetter can
 * centre the narrower of parent/ruby within the slot. {@see $canBreakBefore}
 * marks a legal line-break opportunity before this unit (false inside a Latin
 * word so words stay whole).
 */
final readonly class LineUnit
{
    public function __construct(
        public string $text,
        public ?string $rubyText,
        public TextStyle $style,
        public float $fontSize,
        public float $width,
        public bool $canBreakBefore,
        public float $parentWidth,
        public float $rubyWidth,
        public bool $isTcy = false,
        public bool $softHyphen = false,
    ) {
    }
}
