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
 * Turns {@see FormattedLine}s into PDF content-stream operators for horizontal
 * (`horizontal-tb`) or vertical (`vertical-rl`) text: line/column stacking,
 * alignment incl. JUSTIFY, per-run colour, highlight backgrounds, ruby
 * (furigana) beside the parent, emphasis dots, underline/strikethrough, and
 * faux bold/italic (stroke-fill / shear matrix) — the last two so any font
 * shows emphasis without a dedicated bold or italic face.
 *
 * Input coordinates are PDF points with y increasing upward; ($x, $topY) is the
 * top-left of the text box. Widths already live on the units, so no font
 * metrics are needed here; the caller supplies the font resource name and an
 * encoder that wraps a run in its Tj operand (literal or hex).
 */
final class Typesetter
{
    private const float ITALIC_SHEAR = 0.2126;      // tan(12°)
    private const float FAUX_BOLD_WIDTH = 0.03;      // × font size

    /** Punctuation shifted to the cell's upper-right in vertical writing. */
    private const array VERTICAL_UPPER_RIGHT = ['。', '、', '．', '，'];

    /** Font resource for furigana; set per render() call. */
    private string $rubyResource = '';

    /** @var (callable(string): string)|null Tj operand builder for furigana. */
    private $rubyEncode = null;

    /** Font resource of the body text, kept as the furigana fallback face. */
    private string $bodyResource = '';

    /** @var (callable(string): string)|null Tj operand builder for the body face. */
    private $bodyEncode = null;

    /** @var (callable(string): bool)|null True when the ruby face can draw a run. */
    private $rubyCovers = null;

    public function __construct(
        private readonly TypesetterOptions $options = new TypesetterOptions(),
    ) {
    }

    /**
     * @param list<FormattedLine>       $lines
     * @param callable(string): string  $encode     wraps a run in its Tj operand (incl. delimiters)
     * @param callable(string): bool    $rubyCovers whether the ruby face has glyphs for a run;
     *                                              runs it cannot draw fall back to the body face
     */
    public function render(
        array $lines,
        float $x,
        float $topY,
        float $boxWidth,
        float $baseFontSize,
        string $fontResource,
        callable $encode,
        ?string $rubyResource = null,
        ?callable $rubyEncode = null,
        ?callable $rubyCovers = null,
    ): TypesetResult {
        // Ruby may be set in its own face; absent one it rides the parent's.
        $this->rubyResource = $rubyResource ?? $fontResource;
        $this->rubyEncode = $rubyEncode ?? $encode;
        $this->bodyResource = $fontResource;
        $this->bodyEncode = $encode;
        $this->rubyCovers = $rubyCovers;

        return $this->options->writingMode->isVertical()
            ? $this->renderVertical($lines, $x, $topY, $boxWidth, $baseFontSize, $fontResource, $encode)
            : $this->renderHorizontal($lines, $x, $topY, $boxWidth, $baseFontSize, $fontResource, $encode);
    }

    /**
     * @param list<FormattedLine>       $lines
     * @param callable(string): string  $encode
     */
    private function renderHorizontal(array $lines, float $x, float $topY, float $boxWidth, float $baseFontSize, string $fontResource, callable $encode): TypesetResult
    {
        $ops = '';
        $cursorTop = $topY;
        foreach ($lines as $line) {
            $size = $line->isEmpty() ? $baseFontSize : $line->maxFontSize();
            $rubyExtra = $this->rubyBand($line, $size);
            $baseline = $cursorTop - $rubyExtra - $size * 0.8; // 0.8 em ascent

            $available = $boxWidth - $line->indent;
            $gap = $this->justifyGap($line, $available);
            $unitX = $x + $line->indent + ($gap > 0.0 ? 0.0 : $this->alignOffset($line->width, $available));
            $lastUnit = \count($line->units) - 1;
            foreach ($line->units as $position => $unit) {
                $ops .= $this->renderUnit($unit, $unitX, $baseline, $fontResource, $encode);
                $unitX += $unit->width;
                if ($position < $lastUnit) {
                    $unitX += $gap;
                }
            }
            if ($line->endsWithHyphen && $lastUnit >= 0) {
                $tail = $line->units[$lastUnit];
                $ops .= \sprintf(
                    "BT\n%s rg\n/%s %.2F Tf\n1 0 0 1 %.2F %.2F Tm\n%s Tj\nET\n",
                    self::rgb($tail->style->color ?? '#000000'),
                    $fontResource,
                    $tail->fontSize,
                    $unitX,
                    $baseline,
                    $encode('-'),
                );
            }

            $cursorTop -= $rubyExtra + $size * $this->options->lineHeight;
        }

        return new TypesetResult($ops, $topY - $cursorTop, \count($lines));
    }

    /**
     * Vertical right-to-left: columns advance leftward from the right edge ($x),
     * glyphs stack downward from $topY. Ruby sits to the right of the parent,
     * the underline runs down the left side, emphasis dots go on the right, and
     * $boxWidth is the column height. Glyphs are drawn upright (no vertical CMap
     * yet), which is correct for CJK; Latin is not rotated.
     *
     * @param list<FormattedLine>       $lines
     * @param callable(string): string  $encode
     */
    private function renderVertical(array $lines, float $x, float $topY, float $boxHeight, float $baseFontSize, string $fontResource, callable $encode): TypesetResult
    {
        $ops = '';
        $colRight = $x;
        foreach ($lines as $line) {
            $size = $line->isEmpty() ? $baseFontSize : $line->maxFontSize();
            $rubyExtra = $this->rubyBand($line, $size);
            $centerX = $colRight - $rubyExtra - $size / 2.0;

            $gap = $this->justifyGap($line, $boxHeight);
            $top = $topY - ($gap > 0.0 ? 0.0 : $this->alignOffset($line->width, $boxHeight));
            $lastUnit = \count($line->units) - 1;
            foreach ($line->units as $position => $unit) {
                $ops .= $this->renderUnitVertical($unit, $centerX, $top, $colRight, $rubyExtra, $fontResource, $encode);
                $top -= $unit->width;
                if ($position < $lastUnit) {
                    $top -= $gap;
                }
            }

            $colRight -= $rubyExtra + $size * $this->options->lineHeight;
        }

        return new TypesetResult($ops, $x - $colRight, \count($lines));
    }

    /**
     * The advance (points) this line consumes on the cross axis — matching
     * what {@see render()} steps by — so a paginator can measure without
     * drawing. Includes the ruby band for lines that carry furigana.
     */
    public function lineHeight(FormattedLine $line, float $baseFontSize): float
    {
        $size = $line->isEmpty() ? $baseFontSize : $line->maxFontSize();

        return $this->rubyBand($line, $size) + $size * $this->options->lineHeight;
    }

    /**
     * The extra advance a line needs for its furigana — the ruby itself plus
     * the gap holding it off the parent — or zero when the line carries none.
     *
     * Reserving it here is what keeps an enlarged ruby size or offset from
     * letting one line's ruby collide with the line above it.
     */
    private function rubyBand(FormattedLine $line, float $size): float
    {
        return $line->hasRuby() ? $this->options->rubyStyle->bandFor($size) : 0.0;
    }

    private function alignOffset(float $lineWidth, float $boxWidth): float
    {
        return match ($this->options->align) {
            TextAlign::START, TextAlign::JUSTIFY => 0.0,
            TextAlign::CENTER => ($boxWidth - $lineWidth) / 2.0,
            TextAlign::END => $boxWidth - $lineWidth,
        };
    }

    /**
     * Extra space to insert between each unit so a JUSTIFY line fills the box
     * (均等割付). Zero unless justifying a non-final line with room to spread.
     */
    private function justifyGap(FormattedLine $line, float $boxWidth): float
    {
        $gaps = \count($line->units) - 1;
        if (
            $this->options->align !== TextAlign::JUSTIFY
            || $line->isLastInParagraph
            || $gaps < 1
            || $line->width >= $boxWidth
        ) {
            return 0.0;
        }

        return ($boxWidth - $line->width) / $gaps;
    }

    private function renderUnit(LineUnit $unit, float $unitX, float $baseline, string $fontResource, callable $encode): string
    {
        $size = $unit->fontSize;
        $style = $unit->style;
        $fill = self::rgb($style->color ?? '#000000');
        $parentX = $unitX + ($unit->width - $unit->parentWidth) / 2.0;

        $ops = '';
        if ($style->backgroundColor !== null) {
            $ops .= \sprintf(
                "q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n",
                self::rgb($style->backgroundColor),
                $unitX,
                $baseline - $size * 0.2,
                $unit->width,
                $size,
            );
        }

        $ops .= "BT\n" . $fill . " rg\n";
        if ($style->isBold) {
            $ops .= $fill . " RG\n" . \sprintf("%.2F w\n2 Tr\n", $size * self::FAUX_BOLD_WIDTH);
        }
        $ops .= \sprintf('/%s %.2F Tf' . "\n", $fontResource, $size);
        $shear = $style->isItalic ? self::ITALIC_SHEAR : 0.0;
        $ops .= \sprintf("1 0 %.4F 1 %.2F %.2F Tm\n%s Tj\n", $shear, $parentX, $baseline, $encode($unit->text));
        if ($style->isBold) {
            $ops .= "0 Tr\n";
        }
        $ops .= "ET\n";

        if ($unit->rubyText !== null) {
            $ops .= $this->renderRuby($unit, $unitX, $baseline, $fill);
        }
        $ops .= $this->renderDecorations($unit, $unitX, $baseline, $fill);
        if ($style->isBouten) {
            $ops .= $this->renderBouten($unit, $parentX, $baseline);
        }

        return $ops;
    }

    /**
     * The resource and encoder a furigana run is set with: its own face when
     * that face has the glyphs, the body face when it does not.
     *
     * A ruby font picked for its Latin design — or a subset built for display
     * use — routinely has no kana. Falling back to the face already setting the
     * text keeps the reading legible instead of stamping 豆腐 over it. The
     * substitution is confined to the annotation; the body is never re-faced.
     *
     * @return array{string, callable(string): string}
     */
    private function rubyFaceFor(string $text): array
    {
        $covers = $this->rubyCovers;
        if ($covers !== null && !$covers($text)) {
            return [$this->bodyResource, $this->bodyEncode ?? static fn (string $run): string => $run];
        }

        return [$this->rubyResource, $this->rubyEncode ?? static fn (string $run): string => $run];
    }

    private function renderRuby(LineUnit $unit, float $unitX, float $baseline, string $fill): string
    {
        $text = (string) $unit->rubyText;
        [$fontResource, $encode] = $this->rubyFaceFor($text);
        $style = $this->options->rubyStyle;
        $rubySize = $style->sizeFor($unit->fontSize);

        // The gap is measured from the parent's top edge (0.8 em above its
        // baseline, matching the ascent used when the line was placed).
        $rubyBaseline = $baseline + $unit->fontSize * 0.8 + $style->offset;

        // Ruby is drawn at its own size, so the width measured at the parent's
        // size has to be rescaled before it can be placed or spread.
        $rubyWidth = $unit->rubyWidth * ($rubySize / \max($unit->fontSize, 0.01));
        $slack = $unit->width - $rubyWidth;
        $chars = \max(1, \mb_strlen($text, 'UTF-8'));

        // 均等割付: spread the characters across the parent instead of leaving
        // them huddled in the middle. Tc adds the space after every glyph, so
        // the run starts half a step in and the trailing step falls outside.
        $spacing = 0.0;
        $rubyX = $unitX + $slack / 2.0;
        if ($style->alignment === RubyAlignment::JIS_EQUAL_SPACE && $chars > 1 && $slack > 0.0) {
            $spacing = $slack / $chars;
            $rubyX = $unitX + $spacing / 2.0;
        } elseif ($style->alignment === RubyAlignment::START) {
            $rubyX = $unitX;
        }

        return \sprintf(
            "BT\n%s rg\n/%s %.2F Tf\n%.3F Tc\n1 0 0 1 %.2F %.2F Tm\n%s Tj\n0 Tc\nET\n",
            $fill,
            $fontResource,
            $rubySize,
            $spacing,
            $rubyX,
            $rubyBaseline,
            $encode($text),
        );
    }

    private function renderDecorations(LineUnit $unit, float $unitX, float $baseline, string $fill): string
    {
        $ops = '';
        $weight = $unit->fontSize * 0.05;
        if ($unit->style->isUnderline) {
            $ops .= $this->line($unitX, $unitX + $unit->width, $baseline - $unit->fontSize * 0.12, $weight, $fill);
        }
        if ($unit->style->isStrikethrough) {
            $ops .= $this->line($unitX, $unitX + $unit->width, $baseline + $unit->fontSize * 0.28, $weight, $fill);
        }

        return $ops;
    }

    /**
     * Emphasis dots centred above each parent character, one per character.
     */
    private function renderBouten(LineUnit $unit, float $parentX, float $baseline): string
    {
        $style = $this->options->emphasisMarkStyle;
        $chars = \max(1, \mb_strlen($unit->text, 'UTF-8'));
        $slot = $unit->parentWidth / $chars;
        $diameter = $style->sizeFor($unit->fontSize);

        // Sit the mark above the parent's top edge by the configured gap.
        $cy = $baseline + $unit->fontSize * 0.8 + $style->offset + $diameter / 2.0;

        $ops = '';
        for ($i = 0; $i < $chars; $i++) {
            $ops .= $this->mark($parentX + $slot * ($i + 0.5), $cy, $diameter);
        }

        return $ops;
    }

    /**
     * One emphasis mark, drawn as vector geometry so it never depends on the
     * text font carrying the glyph (and so can never come out as tofu).
     */
    private function mark(float $cx, float $cy, float $diameter): string
    {
        $r = $diameter / 2.0;

        return match ($this->options->emphasisMarkStyle->mark) {
            EmphasisMarkStyle::OPEN_CIRCLE => $this->ring($cx, $cy, $r),
            EmphasisMarkStyle::BULLSEYE => $this->ring($cx, $cy, $r) . $this->disc($cx, $cy, $r * 0.45),
            EmphasisMarkStyle::TRIANGLE => $this->triangle($cx, $cy, $r),
            default => $this->disc($cx, $cy, $r),
        };
    }

    /**
     * An unfilled circle (白丸), stroked at a tenth of its radius.
     */
    private function ring(float $cx, float $cy, float $r): string
    {
        $k = $r * 0.5523; // Bezier circle constant.

        return \sprintf(
            "q\n%.3F w\n%.2F %.2F m\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\nS\nQ\n",
            \max(0.1, $r * 0.2),
            $cx + $r,
            $cy,
            $cx + $r,
            $cy + $k,
            $cx + $k,
            $cy + $r,
            $cx,
            $cy + $r,
            $cx - $k,
            $cy + $r,
            $cx - $r,
            $cy + $k,
            $cx - $r,
            $cy,
            $cx - $r,
            $cy - $k,
            $cx - $k,
            $cy - $r,
            $cx,
            $cy - $r,
            $cx + $k,
            $cy - $r,
            $cx + $r,
            $cy - $k,
            $cx + $r,
            $cy,
        );
    }

    /**
     * A filled equilateral-ish triangle pointing up, inscribed in radius $r.
     */
    private function triangle(float $cx, float $cy, float $r): string
    {
        return \sprintf(
            "q\n%.2F %.2F m\n%.2F %.2F l\n%.2F %.2F l\nf\nQ\n",
            $cx,
            $cy + $r,
            $cx + $r * 0.87,
            $cy - $r * 0.5,
            $cx - $r * 0.87,
            $cy - $r * 0.5,
        );
    }

    private function renderUnitVertical(LineUnit $unit, float $centerX, float $top, float $colRight, float $rubyExtra, string $fontResource, callable $encode): string
    {
        if ($unit->isTcy) {
            return $this->renderTcyCell($unit, $centerX, $top, $fontResource, $encode);
        }

        $size = $unit->fontSize;
        $style = $unit->style;
        $fill = self::rgb($style->color ?? '#000000');

        $ops = '';
        if ($style->backgroundColor !== null) {
            $ops .= \sprintf(
                "q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n",
                self::rgb($style->backgroundColor),
                $centerX - $size / 2.0,
                $top - $unit->width,
                $size,
                $unit->width,
            );
        }

        // Parent glyphs stacked downward, centred within the unit's advance.
        $parentTop = $top - ($unit->width - $unit->parentWidth) / 2.0;
        $ops .= $this->stackGlyphs($unit->text, $centerX, $parentTop, $size, $style, $fill, $fontResource, $encode);

        if ($unit->rubyText !== null) {
            $rubyStyle = $this->options->rubyStyle;
            $rubySize = $rubyStyle->sizeFor($size);
            $text = (string) $unit->rubyText;

            // Ruby runs down its own column to the right of the parent; the gap
            // is measured from the parent's right edge outward.
            $rubyCenterX = $centerX + $size / 2.0 + $rubyStyle->offset + $rubySize / 2.0;

            $rubyRun = \mb_strlen($text, 'UTF-8') * $rubySize;
            $slack = $unit->parentWidth - $rubyRun;
            $rubyTop = $parentTop - $slack / 2.0;
            if ($rubyStyle->alignment === RubyAlignment::JIS_EQUAL_SPACE && $slack > 0.0) {
                $rubyTop = $parentTop;
            } elseif ($rubyStyle->alignment === RubyAlignment::START) {
                $rubyTop = $parentTop;
            }

            $advance = $rubyStyle->alignment === RubyAlignment::JIS_EQUAL_SPACE && $slack > 0.0
                ? $unit->parentWidth / \max(1, \mb_strlen($text, 'UTF-8'))
                : $rubySize;

            [$rubyResource, $rubyEncode] = $this->rubyFaceFor($text);
            $ops .= $this->stackGlyphs(
                $text,
                $rubyCenterX,
                $rubyTop,
                $rubySize,
                new TextStyle(color: $style->color),
                $fill,
                $rubyResource,
                $rubyEncode,
                $advance,
            );
        }

        $ops .= $this->renderDecorationsVertical($unit, $centerX, $top);
        if ($style->isBouten) {
            $ops .= $this->renderBoutenVertical($unit, $centerX, $parentTop);
        }

        return $ops;
    }

    /**
     * Tate-chu-yoko: draw the run horizontally, upright, centred in one em cell
     * so short numbers/letters read normally inside a vertical column.
     *
     * @param callable(string): string $encode
     */
    private function renderTcyCell(LineUnit $unit, float $centerX, float $top, string $fontResource, callable $encode): string
    {
        $size = $unit->fontSize;
        $style = $unit->style;
        $fill = self::rgb($style->color ?? '#000000');
        $runWidth = \mb_strlen($unit->text, 'UTF-8') * $size * 0.5; // half-width estimate
        $left = $centerX - $runWidth / 2.0;
        $baseline = $top - $size * 0.78;

        $ops = '';
        if ($style->backgroundColor !== null) {
            $ops .= \sprintf(
                "q\n%s rg\n%.2F %.2F %.2F %.2F re\nf\nQ\n",
                self::rgb($style->backgroundColor),
                $centerX - $size / 2.0,
                $top - $unit->width,
                $size,
                $unit->width,
            );
        }
        $ops .= "BT\n" . $fill . " rg\n";
        if ($style->isBold) {
            $ops .= $fill . " RG\n" . \sprintf("%.2F w\n2 Tr\n", $size * self::FAUX_BOLD_WIDTH);
        }
        $ops .= \sprintf('/%s %.2F Tf' . "\n", $fontResource, $size);
        $ops .= \sprintf("1 0 0 1 %.2F %.2F Tm\n%s Tj\n", $left, $baseline, $encode($unit->text));
        if ($style->isBold) {
            $ops .= "0 Tr\n";
        }

        return $ops . "ET\n";
    }

    /**
     * Draw each character of $text stacked downward from $top, centred on
     * $centerX (full-width assumption). $advance overrides the per-character
     * step, which is how ruby spreads itself over a longer parent run.
     *
     * @param callable(string): string $encode
     */
    private function stackGlyphs(string $text, float $centerX, float $top, float $size, TextStyle $style, string $fill, string $fontResource, callable $encode, ?float $advance = null): string
    {
        $step = $advance ?? $size;
        $ops = '';
        $index = 0;
        foreach (\mb_str_split($text, 1, 'UTF-8') as $char) {
            $x = $centerX - $size / 2.0;
            $baseline = $top - $index * $step - $size * 0.85;
            if (\in_array($char, self::VERTICAL_UPPER_RIGHT, true)) {
                // Full-stop / comma sit at the cell's upper-right in vertical
                // setting (a positional approximation of the vertical-form glyph).
                $x += $size * 0.45;
                $baseline += $size * 0.5;
            }
            $ops .= $this->glyph($char, $x, $baseline, $size, $style, $fill, $fontResource, $encode);
            $index++;
        }

        return $ops;
    }

    /**
     * A single vertically-set glyph (upright). Faux bold/italic as elsewhere,
     * with italic slanting along the vertical axis (matrix b term).
     *
     * @param callable(string): string $encode
     */
    private function glyph(string $char, float $x, float $baseline, float $size, TextStyle $style, string $fill, string $fontResource, callable $encode): string
    {
        $ops = "BT\n" . $fill . " rg\n";
        if ($style->isBold) {
            $ops .= $fill . " RG\n" . \sprintf("%.2F w\n2 Tr\n", $size * self::FAUX_BOLD_WIDTH);
        }
        $ops .= \sprintf('/%s %.2F Tf' . "\n", $fontResource, $size);
        $shear = $style->isItalic ? self::ITALIC_SHEAR : 0.0;
        $ops .= \sprintf("1 %.4F 0 1 %.2F %.2F Tm\n%s Tj\n", $shear, $x, $baseline, $encode($char));
        if ($style->isBold) {
            $ops .= "0 Tr\n";
        }

        return $ops . "ET\n";
    }

    private function renderDecorationsVertical(LineUnit $unit, float $centerX, float $top): string
    {
        $ops = '';
        $fill = self::rgb($unit->style->color ?? '#000000');
        $weight = $unit->fontSize * 0.05;
        $bottom = $top - $unit->width;
        if ($unit->style->isUnderline) {
            // The underline runs down the left of the column in vertical setting.
            $ops .= $this->vline($centerX - $unit->fontSize * 0.58, $top, $bottom, $weight, $fill);
        }
        if ($unit->style->isStrikethrough) {
            $ops .= $this->vline($centerX, $top, $bottom, $weight, $fill);
        }

        return $ops;
    }

    private function renderBoutenVertical(LineUnit $unit, float $centerX, float $parentTop): string
    {
        $style = $this->options->emphasisMarkStyle;
        $chars = \max(1, \mb_strlen($unit->text, 'UTF-8'));
        $size = $unit->fontSize;
        $diameter = $style->sizeFor($size);

        // Marks run down the parent's right side, held off by the gap.
        $cx = $centerX + $size / 2.0 + $style->offset + $diameter / 2.0;

        $ops = '';
        for ($i = 0; $i < $chars; $i++) {
            $ops .= $this->mark($cx, $parentTop - $i * $size - $size * 0.5, $diameter);
        }

        return $ops;
    }

    private function vline(float $x, float $y1, float $y2, float $weight, string $fill): string
    {
        return \sprintf(
            "q\n%s RG\n%.2F w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
            $fill,
            $weight,
            $x,
            $y1,
            $x,
            $y2,
        );
    }

    private function line(float $x1, float $x2, float $y, float $weight, string $fill): string
    {
        return \sprintf(
            "q\n%s RG\n%.2F w\n%.2F %.2F m\n%.2F %.2F l\nS\nQ\n",
            $fill,
            $weight,
            $x1,
            $y,
            $x2,
            $y,
        );
    }

    private function disc(float $cx, float $cy, float $r): string
    {
        $k = $r * 0.5522847498;

        return \sprintf(
            "q\n0 0 0 rg\n%.2F %.2F m\n%.2F %.2F %.2F %.2F %.2F %.2F c\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\n%.2F %.2F %.2F %.2F %.2F %.2F c\n"
            . "%.2F %.2F %.2F %.2F %.2F %.2F c\nf\nQ\n",
            $cx + $r, $cy,
            $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r,
            $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy,
            $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r,
            $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy,
        );
    }

    /**
     * "#RRGGBB" → "r g b" components in 0..1.
     */
    private static function rgb(string $hex): string
    {
        $hex = \ltrim($hex, '#');
        if (\strlen($hex) !== 6 || !\ctype_xdigit($hex)) {
            throw new \InvalidArgumentException("Invalid hex colour: #{$hex}");
        }

        return \sprintf(
            '%.3F %.3F %.3F',
            \hexdec(\substr($hex, 0, 2)) / 255.0,
            \hexdec(\substr($hex, 2, 2)) / 255.0,
            \hexdec(\substr($hex, 4, 2)) / 255.0,
        );
    }
}
