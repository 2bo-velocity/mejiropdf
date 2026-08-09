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
 * Immutable configuration for the typesetter: writing direction, alignment, and
 * per-feature toggles for Japanese (ruby, emphasis dots, kinsoku, hanging
 * punctuation) and Western (drop caps, hyphenation, smart quotes, widow/orphan
 * control) composition.
 *
 * Ruby and emphasis marks additionally carry their own style objects
 * ({@see RubyStyle}, {@see EmphasisMarkStyle}) covering size, font, and the gap
 * they keep from the text. Both default to JIS X 4051 proportions, so the
 * common case needs no configuration.
 *
 * Turning a feature off lets the typesetter skip its scanning/parsing entirely,
 * which is how raw business text and logs keep the O(1) fast path (see
 * {@see plain()}). Consumed by the line-breaker and drawing layers; this object
 * itself performs no work beyond validation.
 */
final readonly class TypesetterOptions
{
    public function __construct(
        // Direction & layout.
        public WritingMode $writingMode = WritingMode::HORIZONTAL_TB,
        public TextAlign $align = TextAlign::START,
        public bool $autoTcy = true,
        // Japanese composition.
        public bool $enableRuby = true,
        public bool $enableBouten = true,
        public bool $enableJapaneseKinsoku = true,
        public bool $enableBurasage = true,
        // Western composition.
        public bool $enableDropCaps = false,
        public bool $enableHyphenation = true,
        public bool $enableSmartQuotes = true,
        public int $minLinesAtPageBreak = 2,
        // Common.
        public string $locale = 'ja',
        // Ruby / emphasis-mark appearance (size, font, gap). Last so that
        // positional construction stays source-compatible.
        public RubyStyle $rubyStyle = new RubyStyle(),
        public EmphasisMarkStyle $emphasisMarkStyle = new EmphasisMarkStyle(),
        /**
         * Style every run in the block starts from — a block colour, or an
         * underline covering the whole block. Inline markup layers on top (see
         * {@see TextStyle::over()}); null means "plain black", the default.
         */
        public ?TextStyle $baseStyle = null,
        /** Line advance as a multiple of the font size. */
        public float $lineHeight = 1.6,
        /** What Latin letters and digits do inside a vertical column. */
        public LatinInVertical $latinInVertical = LatinInVertical::UPRIGHT,
        /** Longest Latin run set as one upright cell under {@see LatinInVertical::TCY}. */
        public int $tcyLimit = 2,
        /** How far the characters that move in a vertical column move. */
        public VerticalFormStyle $verticalFormStyle = new VerticalFormStyle(),
        /** Spacing between 和字 and what adjoins it. Off by default. */
        public JapaneseSpacing $japaneseSpacing = JapaneseSpacing::NONE,
        /** Lines of the following paragraph a `.keepnext` heading holds on to. */
        public int $keepWithNextLines = 2,
        /**
         * Quantise every line's advance to a whole number of this many points,
         * so that facing pages put their lines at the same heights. Zero, the
         * default, leaves the advance alone.
         *
         * Measured before it was built: uniform prose already lands on a grid
         * of its own line advance, because every step is that advance and a
         * paragraph break is two of them. What breaks it is a line that is
         * taller than the rest — ruby above all, which threw the pages of a
         * measured sample 2.2 mm out of step. So this earns its place in books
         * with furigana or mixed sizes and does nothing at all in the rest,
         * which is why it is off unless asked for.
         */
        public float $lineGrid = 0.0,
    ) {
        if ($minLinesAtPageBreak < 1) {
            throw new \InvalidArgumentException('minLinesAtPageBreak must be at least 1.');
        }
        if ($lineHeight <= 0.0) {
            throw new \InvalidArgumentException('lineHeight must be greater than 0.');
        }
    }

    /**
     * Small-press / novel preset: vertical right-to-left with ruby and emphasis
     * dots. Pass overrides to tweak (e.g. `novel(align: TextAlign::CENTER)`).
     */
    public static function novel(TextAlign $align = TextAlign::START): self
    {
        return new self(
            writingMode: WritingMode::VERTICAL_RL,
            align: $align,
            enableRuby: true,
            enableBouten: true,
        );
    }

    /**
     * Raw-text preset for business documents and logs: inline markup and
     * typographic substitutions are disabled so the input is drawn verbatim on
     * the parsing-free fast path. Kinsoku/hanging line-breaking (which never
     * rewrites content) stay on.
     */
    public static function plain(): self
    {
        return new self(
            enableRuby: false,
            enableBouten: false,
            enableDropCaps: false,
            enableHyphenation: false,
            enableSmartQuotes: false,
        );
    }

    /**
     * A copy with a different alignment (used per table cell).
     */
    public function withAlign(TextAlign $align): self
    {
        return new self(
            $this->writingMode,
            $align,
            $this->autoTcy,
            $this->enableRuby,
            $this->enableBouten,
            $this->enableJapaneseKinsoku,
            $this->enableBurasage,
            $this->enableDropCaps,
            $this->enableHyphenation,
            $this->enableSmartQuotes,
            $this->minLinesAtPageBreak,
            $this->locale,
            $this->rubyStyle,
            $this->emphasisMarkStyle,
            $this->baseStyle,
            $this->lineHeight,
            $this->latinInVertical,
            $this->tcyLimit,
            $this->verticalFormStyle,
            $this->japaneseSpacing,
            $this->keepWithNextLines,
            $this->lineGrid,
        );
    }

    /**
     * A copy drawn from a different base style — the template renderer uses it
     * to give a whole text box its colour and underline.
     */
    public function withBaseStyle(?TextStyle $baseStyle): self
    {
        return new self(
            $this->writingMode,
            $this->align,
            $this->autoTcy,
            $this->enableRuby,
            $this->enableBouten,
            $this->enableJapaneseKinsoku,
            $this->enableBurasage,
            $this->enableDropCaps,
            $this->enableHyphenation,
            $this->enableSmartQuotes,
            $this->minLinesAtPageBreak,
            $this->locale,
            $this->rubyStyle,
            $this->emphasisMarkStyle,
            $baseStyle,
            $this->lineHeight,
            $this->latinInVertical,
            $this->tcyLimit,
            $this->verticalFormStyle,
            $this->japaneseSpacing,
            $this->keepWithNextLines,
            $this->lineGrid,
        );
    }

    /**
     * True when neither inline-markup class is parsed — the tokenizer can be
     * skipped and the text emitted as a single run.
     */
    public function skipsInlineMarkup(): bool
    {
        return !$this->enableRuby && !$this->enableBouten;
    }
}
