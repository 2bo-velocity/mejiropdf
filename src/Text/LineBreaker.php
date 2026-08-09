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
 * Breaks a {@see TextToken} stream into {@see FormattedLine}s that fit a maximum
 * advance (points), one paragraph (`\n`-separated) at a time. Latin words stay
 * whole; CJK breaks per character. Japanese line composition is applied when
 * enabled in {@see TypesetterOptions}:
 *
 * - **行頭禁則 (kinsoku)**: a character that may not begin a line is kept off the
 *   line start by *pushing out* — moving the break one unit earlier.
 * - **ぶら下げ (burasage)**: a small set of punctuation that overflows is allowed
 *   to hang past the box edge instead of wrapping.
 *
 * Only one paragraph's worth of units is held at a time, so memory stays flat
 * with respect to the whole document. Horizontal widths come from the supplied
 * base-font metrics (per-style bold/italic advance differences are ignored for
 * now); vertical advance is one em per character (全角送り). The fill constraint
 * ($maxWidth) is the line length for horizontal and the column height for
 * vertical.
 */
final readonly class LineBreaker
{
    /** Characters that must not start a line (行頭禁則, JIS X 4051 subset). */
    private const string LINE_START_PROHIBITED =
        '、。，．・：；？！）」』】〕〉》］｝〙〗ぁぃぅぇぉっゃゅょゎヵヶゝゞ々ーヽヾ,.!?):;]}';

    /** Punctuation allowed to hang past the line end (ぶら下げ). */
    private const string HANGING = '、。，．,.';

    /** @var array<string, true> */
    private array $lineStartProhibited;

    /** @var array<string, true> */
    private array $hanging;

    public function __construct(
        private FontMetricsInterface $metrics,
        private float $fontSize,
        private TypesetterOptions $options = new TypesetterOptions(),
        private ?Hyphenator $hyphenator = null,
    ) {
        $this->lineStartProhibited = self::charSet(self::LINE_START_PROHIBITED);
        $this->hanging = self::charSet(self::HANGING);
    }

    private function hyphenationEnabled(): bool
    {
        return $this->options->enableHyphenation
            && $this->hyphenator !== null
            && !$this->options->writingMode->isVertical();
    }

    /**
     * @param  list<TextToken>   $tokens
     * @return list<FormattedLine>
     */
    /**
     * @param  list<TextToken>   $tokens
     * @param  float             $indent      leading space (points) reserved on
     *                                        the first $indentLines lines
     * @param  int               $indentLines how many leading lines are indented
     * @return list<FormattedLine>
     */
    public function break(array $tokens, float $maxWidth, float $indent = 0.0, int $indentLines = 0): array
    {
        $lines = [];
        foreach ($this->paragraphs($tokens, $maxWidth, $indent, $indentLines) as $paragraph) {
            foreach ($paragraph as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * The same line breaking, yielded a paragraph at a time so a caller can
     * draw and discard each one instead of holding a whole book's lines.
     *
     * A paragraph is the unit because it is the largest span any decision here
     * reaches across — and the same is true of pagination's widow/orphan rules
     * ({@see Pagination::place()}), so the two stream together.
     *
     * @param  list<TextToken> $tokens
     * @return \Generator<int, list<FormattedLine>>
     */
    public function paragraphs(array $tokens, float $maxWidth, float $indent = 0.0, int $indentLines = 0): \Generator
    {
        if ($tokens === [] || $maxWidth <= 0.0) {
            return;
        }

        $lineNo = 0;
        foreach ($this->toSegments($tokens) as $segment) {
            if ($segment === []) {
                $lineNo++;
                yield [new FormattedLine([], 0.0, isLastInParagraph: true)]; // blank line from "\n\n"
                continue;
            }

            $paragraph = $this->breakSegment($segment, $maxWidth, $indent, $indentLines, $lineNo);
            $last = \count($paragraph) - 1;
            // The paragraph's final line must not be justified.
            $paragraph[$last] = new FormattedLine(
                $paragraph[$last]->units,
                $paragraph[$last]->width,
                isLastInParagraph: true,
                indent: $paragraph[$last]->indent,
            );

            yield $paragraph;
        }
    }

    /**
     * Flatten tokens into per-paragraph unit lists, splitting on newlines.
     *
     * Yielded rather than returned: building every paragraph's units up front
     * costs memory in proportion to the whole text, which is precisely what
     * {@see paragraphs()} exists to avoid. A paragraph is emitted as soon as
     * its newline is seen, so only the one being assembled is held.
     *
     * @param  list<TextToken>                 $tokens
     * @return \Generator<int, list<LineUnit>>
     */
    private function toSegments(array $tokens): \Generator
    {
        $current = [];
        $prev = null;

        $vertical = $this->options->writingMode->isVertical();
        foreach ($tokens as $token) {
            if ($token->rubyText !== null) {
                $unit = $this->rubyUnit($token, $prev);
                $current[] = $unit;
                $prev = $unit;
                continue;
            }

            // A footnote's mark is one small run that must not be broken from
            // the word it follows, and it carries the note to whichever page
            // it lands on.
            if ($token->footnote !== null) {
                $size = ($token->style->fontSize ?? $this->fontSize);
                $unit = new LineUnit(
                    text: $token->text,
                    rubyText: null,
                    style: $token->style,
                    fontSize: $size,
                    width: $this->measure($token->text, $size),
                    canBreakBefore: false,
                    parentWidth: $this->measure($token->text, $size),
                    rubyWidth: 0.0,
                    footnote: $token->footnote,
                );
                $current[] = $unit;
                $prev = $unit;
                continue;
            }

            // An explicit ^…^ / .tcy run is one upright cell in vertical mode;
            // in horizontal mode it is ordinary text.
            if ($token->style->isTcy && $vertical) {
                $unit = $this->tcyUnit($token->text, $token->style, $prev);
                $current[] = $unit;
                $prev = $unit;
                continue;
            }

            $text = \str_replace(["\r\n", "\r"], "\n", $token->text);
            $pieces = \explode("\n", $text);
            foreach ($pieces as $index => $piece) {
                if ($index > 0) {
                    yield $this->spaced($current);
                    $current = [];
                    $prev = null;
                }
                $this->emitChars($piece, $token->style, $vertical, $current, $prev);
            }
        }

        yield $this->spaced($current);
    }

    /**
     * Fill in each unit's gap to its neighbour, once the whole paragraph is
     * built and every neighbour is therefore known.
     *
     * Done here rather than while emitting because a pair can straddle a token
     * boundary — bold Latin following plain 和字 is two tokens and one seam.
     *
     * @param  list<LineUnit> $units
     * @return list<LineUnit>
     */
    private function spaced(array $units): array
    {
        if ($this->options->japaneseSpacing === JapaneseSpacing::NONE) {
            return $units;
        }

        $last = \count($units) - 1;
        for ($i = 0; $i < $last; $i++) {
            $unit = $units[$i];
            $next = $units[$i + 1];
            // Runs are compared by the characters that actually touch.
            $left = \mb_substr($unit->text, -1, 1, 'UTF-8');
            $right = \mb_substr($next->text, 0, 1, 'UTF-8');
            $gap = $this->options->japaneseSpacing->between($left, $right) * $unit->fontSize;
            if ($gap === 0.0) {
                continue;
            }

            $units[$i] = new LineUnit(
                $unit->text,
                $unit->rubyText,
                $unit->style,
                $unit->fontSize,
                $unit->width,
                $unit->canBreakBefore,
                $unit->parentWidth,
                $unit->rubyWidth,
                $unit->isTcy,
                $unit->softHyphen,
                $unit->isRotatedRun,
                $gap,
            );
        }

        return $units;
    }

    /**
     * Append the units for one newline-free piece, grouping a run of exactly two
     * ASCII digits into a tate-chu-yoko cell when auto-TCY is on and vertical.
     *
     * @param list<LineUnit> $current
     */
    private function emitChars(string $piece, TextStyle $style, bool $vertical, array &$current, ?LineUnit &$prev): void
    {
        $chars = \mb_str_split($piece, 1, 'UTF-8');
        $count = \count($chars);
        $autoTcy = $vertical && $this->options->autoTcy;

        $latin = $vertical ? $this->options->latinInVertical : LatinInVertical::UPRIGHT;

        $i = 0;
        while ($i < $count) {
            if ($autoTcy && \ctype_digit($chars[$i])) {
                $run = $i;
                while ($run < $count && \ctype_digit($chars[$run])) {
                    $run++;
                }
                if ($run - $i === 2) { // exactly two digits → one upright cell
                    $unit = $this->tcyUnit($chars[$i] . $chars[$i + 1], $style, $prev);
                    $current[] = $unit;
                    $prev = $unit;
                } else {
                    // A run of any other length is set as ordinary digits; consume
                    // it whole so it is never partly re-grouped into a TCY cell.
                    for ($k = $i; $k < $run; $k++) {
                        $unit = $this->charUnit($chars[$k], $style, $prev);
                        $current[] = $unit;
                        $prev = $unit;
                    }
                }
                $i = $run;
                continue;
            }

            // A run of Latin letters and digits, handled as one decision: the
            // choice between standing it up, turning it and widening it is
            // about the run, not about its characters.
            //
            // Checked after auto-TCY so the narrower rule keeps its say: a bare
            // pair of digits stays tate-chu-yoko in every mode, which is what
            // an author who left `autoTcy` on has already asked for.
            if ($latin !== LatinInVertical::UPRIGHT && self::isLatin($chars[$i])) {
                $run = $i;
                while ($run < $count && self::isLatin($chars[$run])) {
                    $run++;
                }
                $text = \implode('', \array_slice($chars, $i, $run - $i));
                $length = $run - $i;

                if ($latin === LatinInVertical::FULLWIDTH) {
                    foreach (\mb_str_split(LatinInVertical::toFullWidth($text), 1, 'UTF-8') as $wide) {
                        $unit = $this->charUnit($wide, $style, $prev);
                        $current[] = $unit;
                        $prev = $unit;
                    }
                } elseif ($latin->standsUprightInOneCell($length, $this->options->tcyLimit)) {
                    $unit = $this->tcyUnit($text, $style, $prev);
                    $current[] = $unit;
                    $prev = $unit;
                } else {
                    $unit = $this->rotatedRunUnit($text, $style, $prev);
                    $current[] = $unit;
                    $prev = $unit;
                }

                $i = $run;
                continue;
            }

            // A run of ASCII letters may carry hyphenation break points.
            if ($this->hyphenationEnabled() && \ctype_alpha($chars[$i])) {
                $run = $i;
                while ($run < $count && \ctype_alpha($chars[$run])) {
                    $run++;
                }
                \assert($this->hyphenator !== null);
                $breaks = \array_flip($this->hyphenator->hyphenate(\implode('', \array_slice($chars, $i, $run - $i))));
                for ($k = $i; $k < $run; $k++) {
                    $unit = $this->charUnit($chars[$k], $style, $prev, isset($breaks[$k - $i]));
                    $current[] = $unit;
                    $prev = $unit;
                }
                $i = $run;
                continue;
            }

            $unit = $this->charUnit($chars[$i], $style, $prev);
            $current[] = $unit;
            $prev = $unit;
            $i++;
        }
    }

    /**
     * A letter or digit of a script that reads sideways in a vertical column.
     *
     * Not just ASCII: Greek and Cyrillic run the same way, and Unicode says so
     * ({@see VerticalOrientation}). Characters the standard leaves upright —
     * kanji, kana, full-width forms — are excluded, since they are already
     * cells in their own right.
     */
    private static function isLatin(string $char): bool
    {
        return \preg_match('/^[\p{L}\p{N}]$/u', $char) === 1
            && VerticalOrientation::of(\mb_ord($char, 'UTF-8')) !== VerticalOrientation::UPRIGHT_LETTER;
    }

    /**
     * A Latin run turned on its side: one unbreakable piece whose advance down
     * the column is the width it would have had set horizontally.
     */
    private function rotatedRunUnit(string $run, TextStyle $style, ?LineUnit $prev): LineUnit
    {
        $size = $style->fontSize ?? $this->fontSize;
        $width = $this->measure($run, $size);

        return new LineUnit(
            text: $run,
            rubyText: null,
            style: $style,
            fontSize: $size,
            width: $width,
            canBreakBefore: $prev !== null,
            parentWidth: $width,
            rubyWidth: 0.0,
            isRotatedRun: true,
        );
    }

    private function tcyUnit(string $run, TextStyle $style, ?LineUnit $prev): LineUnit
    {
        $size = $style->fontSize ?? $this->fontSize;

        // The cell advances one em down the column regardless of run length.
        return new LineUnit(
            text: $run,
            rubyText: null,
            style: $style,
            fontSize: $size,
            width: $size,
            canBreakBefore: $prev !== null,
            parentWidth: $size,
            rubyWidth: 0.0,
            isTcy: true,
        );
    }

    private function charUnit(string $char, TextStyle $style, ?LineUnit $prev, bool $softHyphen = false): LineUnit
    {
        $size = $style->fontSize ?? $this->fontSize;
        $width = $this->measure($char, $size);

        return new LineUnit(
            text: $char,
            rubyText: null,
            style: $style,
            fontSize: $size,
            width: $width,
            // A hyphenation point is a legal break even mid-word.
            canBreakBefore: $softHyphen ? true : $this->canBreakBefore($prev, $char),
            parentWidth: $width,
            rubyWidth: 0.0,
            softHyphen: $softHyphen && $prev !== null,
        );
    }

    private function rubyUnit(TextToken $token, ?LineUnit $prev): LineUnit
    {
        $size = $token->style->fontSize ?? $this->fontSize;
        $parentWidth = $this->measure($token->text, $size);
        $rubyWidth = $this->measure((string) $token->rubyText, $size * 0.5);

        return new LineUnit(
            text: $token->text,
            rubyText: $token->rubyText,
            style: $token->style,
            fontSize: $size,
            width: \max($parentWidth, $rubyWidth),
            canBreakBefore: $prev !== null,
            parentWidth: $parentWidth,
            rubyWidth: $rubyWidth,
        );
    }

    /**
     * Advance of $text along the flow at $size: measured glyph widths for
     * horizontal, one em per character for vertical (full-width 全角送り).
     */
    private function measure(string $text, float $size): float
    {
        return $this->options->writingMode->isVertical()
            ? \mb_strlen($text, 'UTF-8') * $size
            : $this->metrics->textWidth($text, $size);
    }

    /**
     * A break is allowed before a unit unless it would split a Latin word (two
     * adjacent ASCII alphanumerics). Ruby atoms are always breakable at edges.
     */
    private function canBreakBefore(?LineUnit $prev, string $char): bool
    {
        if ($prev === null) {
            return false;
        }
        if ($prev->rubyText !== null) {
            return true;
        }

        return !($this->isWordChar($prev->text) && $this->isWordChar($char));
    }

    private function isWordChar(string $char): bool
    {
        return \strlen($char) === 1 && \ctype_alnum($char);
    }

    /**
     * Greedy fit of one paragraph's units into lines. The first $indentLines
     * lines (counted globally via $lineNo) reserve $indent points of leading
     * space, so text can flow beside a drop cap.
     *
     * @param  list<LineUnit>      $units
     * @return list<FormattedLine>
     */
    private function breakSegment(array $units, float $maxWidth, float $indent, int $indentLines, int &$lineNo): array
    {
        $lines = [];
        $count = \count($units);
        $start = 0;
        $index = 0;
        $width = 0.0;

        while ($index < $count) {
            // The current line's reserved indent and available width.
            $effIndent = $lineNo < $indentLines ? $indent : 0.0;
            $max = $maxWidth - $effIndent;
            $unit = $units[$index];

            if ($index === $start) {
                // Always place at least one unit, even if it alone overflows.
                $width += $unit->width + $unit->spaceAfter;
                $index++;
                if ($width > $max) {
                    $lines[] = $this->emit($units, $start, $index, $effIndent);
                    $start = $index;
                    $width = 0.0;
                    $lineNo++;
                }
                continue;
            }

            // The candidate's own trailing gap is left out of the test: if it
            // ends the line the gap is not there to measure.
            if ($width + $unit->width <= $max) {
                $width += $unit->width + $unit->spaceAfter;
                $index++;
                continue;
            }

            // Overflow with a non-empty line.
            if ($this->options->enableBurasage && isset($this->hanging[$unit->text])) {
                $lines[] = $this->emit($units, $start, $index + 1, $effIndent); // hang the punctuation
                $start = $index + 1;
                $index++;
                $width = 0.0;
                $lineNo++;
                continue;
            }

            // A space at the wrap point is consumed by the break, not carried to
            // the next line as leading whitespace.
            if ($unit->text === ' ') {
                $lines[] = $this->emit($units, $start, $index, $effIndent);
                $start = $index + 1;
                $index++;
                $width = 0.0;
                $lineNo++;
                continue;
            }

            // Prefer the latest opportunity that fits — including a hyphenation
            // point, whose trailing hyphen must also fit.
            $chosen = $this->chooseBreak($units, $start, $index, $max);
            if ($chosen >= 0) {
                $breakAt = $this->pushOutForKinsoku($units, $start, $chosen);
                $hyphen = $breakAt === $chosen && $units[$breakAt]->softHyphen;
            } else {
                $breakAt = $unit->canBreakBefore ? $index : $this->lastBreakOpportunity($units, $start, $index);
                $breakAt = $this->pushOutForKinsoku($units, $start, $breakAt);
                $hyphen = false;
            }

            $lines[] = $this->emit($units, $start, $breakAt, $effIndent, $hyphen);
            $start = $breakAt;
            $width = $this->sumWidth($units, $start, $index);
            $lineNo++;
            // $index is not advanced: re-evaluate the overflowing unit on the new line.
        }

        if ($start < $count) {
            $lines[] = $this->emit($units, $start, $count, $lineNo < $indentLines ? $indent : 0.0);
            $lineNo++;
        }

        return $lines;
    }

    /**
     * Move the break earlier while the character that would start the next line
     * is line-start-prohibited, keeping at least one unit on the emitted line.
     *
     * @param list<LineUnit> $units
     */
    private function pushOutForKinsoku(array $units, int $start, int $breakAt): int
    {
        if (!$this->options->enableJapaneseKinsoku) {
            return $breakAt;
        }
        while ($breakAt > $start + 1 && isset($this->lineStartProhibited[$units[$breakAt]->text])) {
            $breakAt--;
        }

        return $breakAt;
    }

    /**
     * The latest break opportunity in (start, index] whose line — plus a
     * trailing hyphen at a hyphenation point — fits within $max; -1 if none.
     *
     * @param list<LineUnit> $units
     */
    private function chooseBreak(array $units, int $start, int $index, float $max): int
    {
        for ($k = $index; $k > $start; $k--) {
            if (!$units[$k]->canBreakBefore) {
                continue;
            }
            $extra = $units[$k]->softHyphen ? $this->metrics->textWidth('-', $units[$k - 1]->fontSize) : 0.0;
            if ($this->sumWidth($units, $start, $k) + $extra <= $max) {
                return $k;
            }
        }

        return -1;
    }

    /**
     * The last legal break opportunity in (start, index]; index itself if none
     * (forcing a hard character break inside an over-long word).
     *
     * @param list<LineUnit> $units
     */
    private function lastBreakOpportunity(array $units, int $start, int $index): int
    {
        for ($k = $index; $k > $start; $k--) {
            if ($units[$k]->canBreakBefore) {
                return $k;
            }
        }

        return $index;
    }

    /**
     * @param list<LineUnit> $units
     */
    private function emit(array $units, int $from, int $to, float $indent = 0.0, bool $hyphen = false): FormattedLine
    {
        $slice = \array_values(\array_slice($units, $from, $to - $from));
        $width = $this->sumWidth($units, $from, $to);
        if ($hyphen && $to > $from) {
            $width += $this->metrics->textWidth('-', $units[$to - 1]->fontSize);
        }

        return new FormattedLine($slice, $width, indent: $indent, endsWithHyphen: $hyphen);
    }

    /**
     * @param list<LineUnit> $units
     */
    private function sumWidth(array $units, int $from, int $to): float
    {
        $width = 0.0;
        for ($k = $from; $k < $to; $k++) {
            $width += $units[$k]->width;
            // The gap after the final unit separates nothing: a line end is
            // not a place where two characters meet.
            if ($k < $to - 1) {
                $width += $units[$k]->spaceAfter;
            }
        }

        return $width;
    }

    /**
     * @return array<string, true>
     */
    private static function charSet(string $chars): array
    {
        $set = [];
        foreach (\mb_str_split($chars, 1, 'UTF-8') as $char) {
            $set[$char] = true;
        }

        return $set;
    }
}
