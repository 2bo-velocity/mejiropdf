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
 * Splits an inline-markup string into a flat list of {@see TextToken}s in a
 * single pass. It resolves nesting by recursing into span bodies with an
 * updated {@see TextStyle}, so `**[link](url)**` yields bold + underlined +
 * linked runs. Byte offsets from `preg_match_all` line up with `substr`, so
 * multibyte (Japanese) text between matches is sliced correctly.
 *
 * Supported markup (Markdown-compatible, per the design notes):
 * - ruby:          `|親《ルビ》`  or  `{親|ルビ}`
 * - emphasis dots: `《《傍点》》`
 * - bold/italic:   `**bold**` / `*italic*`
 * - strikethrough: `~~text~~`
 * - highlight:     `==text==`
 * - tate-chu-yoko: `^24^` (or `[24]{.tcy}`) — horizontal-in-vertical
 * - link:          `[text](https://…)`
 * - attributes:    `[text]{color=#RRGGBB bg=#RRGGBB size=14pt .bold .underline}`
 * - escape:        `\X` emits X literally (disables the construct it starts)
 *
 * This is the pure lexer; enabling/disabling classes of markup globally and the
 * actual line-breaking/drawing live in later typesetting layers.
 */
final class TextTokenizer
{
    private const string DEFAULT_LINK_COLOR = '#0066CC';
    private const string DEFAULT_HIGHLIGHT = '#FFF176';

    private const string PATTERN = '/'
        . '\\\\(?<esc>.)'                                 // \X  escape
        . '|\[(?<linktext>[^\]]+)\]\((?<url>[^)\s]+)\)'   // [text](url)
        . '|\[(?<attrtext>[^\]]+)\]\{(?<attrs>[^}]*)\}'   // [text]{attrs}
        . '|\(\((?<note>(?:[^()]|\([^()]*\))+)\)\)'         // ((footnote))
        . '|《《(?<bouten>[^》]+)》》'                       // 《《bouten》》
        . '|\|(?<rp>[^|《\s]+)《(?<rr>[^》]+)》'            // |親《ルビ》
        . '|\{(?<bp>[^|{}]+)\|(?<br>[^}]+)\}'             // {親|ルビ}
        . '|\*\*(?<bold>.+?)\*\*'                         // **bold**
        . '|~~(?<strike>.+?)~~'                           // ~~strike~~
        . '|==(?<high>.+?)=='                             // ==highlight==
        . '|\^(?<tcy>[^^]+)\^'                            // ^24^ tate-chu-yoko
        . '|\*(?<italic>.+?)\*'                           // *italic*
        . '/u';

    /**
     * @return list<TextToken>
     */
    public static function tokenize(string $input, ?TextStyle $baseStyle = null): array
    {
        $base = $baseStyle ?? new TextStyle();
        if ($input === '') {
            return [];
        }

        /** @var list<array<int|string, array{0: string, 1: int}>> $matches */
        $matches = [];
        if (\preg_match_all(self::PATTERN, $input, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE) === 0) {
            return [new TextToken($input, style: $base)];
        }

        $tokens = [];
        $cursor = 0;
        foreach ($matches as $m) {
            $offset = $m[0][1];
            if ($offset > $cursor) {
                $tokens[] = new TextToken(\substr($input, $cursor, $offset - $cursor), style: $base);
            }
            $cursor = $offset + \strlen($m[0][0]);
            self::emit($m, $base, $tokens);
        }
        if ($cursor < \strlen($input)) {
            $tokens[] = new TextToken(\substr($input, $cursor), style: $base);
        }

        return $tokens;
    }

    /**
     * Turn one regex match into tokens, appended to $tokens.
     *
     * @param array<int|string, array{0: string, 1: int}> $m
     * @param list<TextToken>                              $tokens
     */
    private static function emit(array $m, TextStyle $base, array &$tokens): void
    {
        if (self::has($m, 'esc')) {
            $tokens[] = new TextToken($m['esc'][0], style: $base);

            return;
        }
        if (self::has($m, 'linktext')) {
            $style = $base->with(
                isUnderline: true,
                color: $base->color ?? self::DEFAULT_LINK_COLOR,
                linkUrl: $m['url'][0],
            );
            self::append($tokens, self::tokenize($m['linktext'][0], $style));

            return;
        }
        if (self::has($m, 'attrtext')) {
            self::append($tokens, self::tokenize($m['attrtext'][0], self::applyAttributes($m['attrs'][0], $base)));

            return;
        }
        if (self::has($m, 'note')) {
            // The mark's text is empty until the flow numbers it; only then is
            // it known what this note is called.
            $tokens[] = new TextToken('', style: $base, footnote: $m['note'][0]);

            return;
        }
        if (self::has($m, 'bouten')) {
            $tokens[] = new TextToken($m['bouten'][0], style: $base->with(isBouten: true));

            return;
        }
        if (self::has($m, 'rp')) {
            $tokens[] = new TextToken($m['rp'][0], rubyText: $m['rr'][0], style: $base);

            return;
        }
        if (self::has($m, 'bp')) {
            $tokens[] = new TextToken($m['bp'][0], rubyText: $m['br'][0], style: $base);

            return;
        }
        if (self::has($m, 'bold')) {
            self::append($tokens, self::tokenize($m['bold'][0], $base->with(isBold: true)));

            return;
        }
        if (self::has($m, 'strike')) {
            self::append($tokens, self::tokenize($m['strike'][0], $base->with(isStrikethrough: true)));

            return;
        }
        if (self::has($m, 'high')) {
            self::append($tokens, self::tokenize($m['high'][0], $base->with(backgroundColor: self::DEFAULT_HIGHLIGHT)));

            return;
        }
        if (self::has($m, 'tcy')) {
            // Tate-chu-yoko is an atomic cell; its content is taken verbatim.
            $tokens[] = new TextToken($m['tcy'][0], style: $base->with(isTcy: true));

            return;
        }
        if (self::has($m, 'italic')) {
            self::append($tokens, self::tokenize($m['italic'][0], $base->with(isItalic: true)));
        }
    }

    /**
     * Parse `[text]{…}` attributes into a derived style. Flags start with a dot
     * (`.bold`); values use `key=value` (`color=`, `bg=`, `size=` in pt).
     */
    private static function applyAttributes(string $attrs, TextStyle $base): TextStyle
    {
        $style = $base;
        foreach (\preg_split('/\s+/', \trim($attrs)) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $style = match (true) {
                $part === '.bold' => $style->with(isBold: true),
                $part === '.italic' => $style->with(isItalic: true),
                $part === '.underline' => $style->with(isUnderline: true),
                $part === '.strikethrough' => $style->with(isStrikethrough: true),
                $part === '.bouten' => $style->with(isBouten: true),
                $part === '.tcy' => $style->with(isTcy: true),
                $part === '.dropcap' => $style->with(isDropCap: true),
                $part === '.keepnext' => $style->with(keepWithNext: true),
                // A subheading is held to what follows it and names the page.
                $part === '.subhead' => $style->with(keepWithNext: true, isSubheading: true),
                \str_starts_with($part, 'color=') => $style->with(color: \substr($part, 6)),
                \str_starts_with($part, 'bg=') => $style->with(backgroundColor: \substr($part, 3)),
                \str_starts_with($part, 'size=') => $style->with(fontSize: (float) \str_replace('pt', '', \substr($part, 5))),
                default => $style,
            };
        }

        return $style;
    }

    /**
     * True when named group $name participated in the match (offset ≠ -1).
     *
     * @param array<int|string, array{0: string, 1: int}> $m
     */
    private static function has(array $m, string $name): bool
    {
        return isset($m[$name]) && $m[$name][1] !== -1 && $m[$name][0] !== '';
    }

    /**
     * @param list<TextToken> $tokens
     * @param list<TextToken> $more
     */
    private static function append(array &$tokens, array $more): void
    {
        foreach ($more as $token) {
            $tokens[] = $token;
        }
    }
}
