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
 * Liang's hyphenation algorithm (the method TeX uses): each competing pattern
 * casts odd/even "votes" at the gaps between letters, and a hyphen is allowed
 * where the winning vote is odd. Odd wins over even from longer/later patterns
 * via a running maximum. An exceptions list overrides patterns for tricky words.
 *
 * Patterns and exceptions are injectable, so a fuller public-domain pattern set
 * (e.g. TeX's `hyphen.tex`) can be supplied; the bundled default is a compact
 * English subset. Only lowercase ASCII words at least (leftMin + rightMin)
 * letters long are hyphenated, and never within leftMin of the start or
 * rightMin of the end — so results are conservative rather than wrong.
 */
final class Hyphenator
{
    /** @var array<string, list<int>> pattern letters => per-gap votes */
    private array $patterns;

    /** @var array<string, list<int>> exception word => allowed break offsets */
    private array $exceptions;

    /**
     * @param string|null $patterns   whitespace-separated Liang patterns
     * @param string|null $exceptions whitespace-separated pre-hyphenated words (`as-so-ciate`)
     */
    public function __construct(
        ?string $patterns = null,
        ?string $exceptions = null,
        private readonly int $leftMin = 2,
        private readonly int $rightMin = 3,
    ) {
        $this->patterns = self::compilePatterns($patterns ?? self::DEFAULT_PATTERNS);
        $this->exceptions = self::compileExceptions($exceptions ?? self::DEFAULT_EXCEPTIONS);
    }

    /**
     * Offsets (0-based) at which a hyphen may be inserted: an offset k means a
     * break between $word[k-1] and $word[k]. Empty for words too short, mixed
     * case/non-ASCII, or with no admissible break.
     *
     * @return list<int>
     */
    public function hyphenate(string $word): array
    {
        $length = \strlen($word);
        if ($length < $this->leftMin + $this->rightMin || \preg_match('/^[A-Za-z]+$/', $word) !== 1) {
            return [];
        }

        $lower = \strtolower($word);
        if (isset($this->exceptions[$lower])) {
            return $this->filter($this->exceptions[$lower], $length);
        }

        $work = '.' . $lower . '.';
        $span = \strlen($work);
        $votes = \array_fill(0, $span + 1, 0);
        for ($i = 0; $i < $span; $i++) {
            for ($j = $i + 1; $j <= $span; $j++) {
                $piece = \substr($work, $i, $j - $i);
                if (!isset($this->patterns[$piece])) {
                    continue;
                }
                foreach ($this->patterns[$piece] as $gap => $vote) {
                    if ($vote > $votes[$i + $gap]) {
                        $votes[$i + $gap] = $vote;
                    }
                }
            }
        }

        // votes index (gap g) sits before work[g]; word index a maps to work a+1.
        $breaks = [];
        for ($a = 1; $a < $length; $a++) {
            if ($votes[$a + 1] % 2 === 1) {
                $breaks[] = $a;
            }
        }

        return $this->filter($breaks, $length);
    }

    /**
     * @param  list<int> $breaks
     * @return list<int>
     */
    private function filter(array $breaks, int $length): array
    {
        return \array_values(\array_filter(
            $breaks,
            fn (int $a): bool => $a >= $this->leftMin && $a <= $length - $this->rightMin,
        ));
    }

    /**
     * @return array<string, list<int>>
     */
    private static function compilePatterns(string $source): array
    {
        $compiled = [];
        foreach (\preg_split('/\s+/', \trim($source)) ?: [] as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $letters = '';
            $votes = [0];
            foreach (\str_split($pattern) as $char) {
                if (\ctype_digit($char)) {
                    $votes[\count($votes) - 1] = (int) $char;
                } else {
                    $letters .= $char;
                    $votes[] = 0;
                }
            }
            $compiled[$letters] = $votes;
        }

        return $compiled;
    }

    /**
     * @return array<string, list<int>>
     */
    private static function compileExceptions(string $source): array
    {
        $compiled = [];
        foreach (\preg_split('/\s+/', \trim($source)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            $word = \str_replace('-', '', $entry);
            $offsets = [];
            $offset = 0;
            foreach (\str_split($entry) as $char) {
                if ($char === '-') {
                    $offsets[] = $offset;
                } else {
                    $offset++;
                }
            }
            $compiled[\strtolower($word)] = $offsets;
        }

        return $compiled;
    }

    /**
     * The bundled default carries no patterns: a hand-verified exception list is
     * always correct, whereas an incomplete pattern subset would mis-break words
     * it does not fully cover. Inject a full public-domain pattern set (TeX's
     * `hyphen.tex`) for broad automatic coverage.
     */
    private const string DEFAULT_PATTERNS = '';

    /** Common English words, pre-hyphenated (Merriam-Webster syllabification). */
    private const string DEFAULT_EXCEPTIONS = <<<'WORDS'
        al-though be-cause be-fore be-tween beau-ti-ful char-ac-ter com-mon
        com-po-nent com-put-er con-fig-u-ra-tion con-tent cre-at-ing de-fault
        de-scrip-tion de-vel-op-ment doc-u-ment doc-u-ments doc-u-men-ta-tion
        el-e-ment en-gine ex-am-ple gen-er-a-tion hy-phen-ation im-ple-ment
        in-for-ma-tion lan-guage lay-out li-brary mem-o-ry mod-ern na-tion-al
        or-phan par-a-graph par-a-graphs po-si-tion pro-cess ref-er-ence
        ren-der-ing sec-tion sen-tence sen-tences stream-ing ta-ble tem-plate
        ty-pog-ra-phy ver-ti-cal wid-ow won-der-ful
        WORDS;
}
