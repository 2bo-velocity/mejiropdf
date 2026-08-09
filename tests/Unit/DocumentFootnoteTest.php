<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit;

use MejiroPDF\Document;
use MejiroPDF\Font\CidFontJapanese;
use MejiroPDF\Layout\FlowRegion;
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Tests\Support\RecordingLogger;
use MejiroPDF\Text\FootnoteStyle;
use MejiroPDF\Text\TextTokenizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
#[CoversClass(FootnoteStyle::class)]
#[CoversClass(TextTokenizer::class)]
final class DocumentFootnoteTest extends TestCase
{
    /** @return list<array{size: float, y: float, text: string}> */
    private static function runs(string $pdf): array
    {
        \preg_match_all(
            "~/F\d+ ([\d.]+) Tf\n1 0 0\.0000 1 [\d.]+ ([\d.]+) Tm\n<([0-9A-F]+)> Tj~",
            $pdf,
            $hits,
            \PREG_SET_ORDER,
        );

        return \array_map(static fn (array $h): array => [
            'size' => (float) $h[1],
            'y' => (float) $h[2],
            'text' => (string) \mb_convert_encoding((string) \hex2bin($h[3]), 'UTF-8', 'UTF-16BE'),
        ], $hits);
    }

    /**
     * Runs drawn at $size, within a hair — the sizes come back through a PDF's
     * two decimal places.
     *
     * @param  list<array{size: float, y: float, text: string}> $runs
     * @return list<array{size: float, y: float, text: string}>
     */
    private static function atSize(array $runs, float $size): array
    {
        return \array_values(\array_filter(
            $runs,
            static fn (array $r): bool => \abs($r['size'] - $size) < 0.01,
        ));
    }

    /**
     * Glyphs gathered into the lines they sit on, keyed by baseline in the
     * order first seen — horizontal text is drawn a character at a time.
     *
     * @param  list<array{size: float, y: float, text: string}> $runs
     * @return list<array{0: float, 1: string}>
     */
    private static function linesOf(array $runs): array
    {
        $lines = [];
        foreach ($runs as $run) {
            $key = \number_format($run['y'], 2, '.', '');
            $lines[$key] = [$run['y'], ($lines[$key][1] ?? '') . $run['text']];
        }

        return \array_values($lines);
    }

    private static function flowed(string $text, ?FootnoteStyle $footnotes, ?RecordingLogger $logger = null): string
    {
        $pdf = new Document(credit: CreditOptions::disabled(), logger: $logger);
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: 120),
            $text,
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            footnotes: $footnotes,
        );

        return $pdf->output();
    }

    #[Test]
    public function the_tokenizer_carries_the_note_off_the_markup(): void
    {
        $tokens = TextTokenizer::tokenize('本((注記))と');

        self::assertCount(3, $tokens);
        self::assertSame('注記', $tokens[1]->footnote);
        // The mark has no text yet: what the note is called is the flow's to say.
        self::assertSame('', $tokens[1]->text);
    }

    #[Test]
    public function a_mark_is_numbered_set_small_and_raised(): void
    {
        $runs = self::runs(self::flowed('本((注記))と', new FootnoteStyle(reserve: 20.0)));

        $body = $runs[0];
        $mark = $runs[1];
        self::assertSame('本', $body['text']);
        self::assertSame('1', $mark['text']);
        self::assertEqualsWithDelta(12.0 * 0.7, $mark['size'], 0.01);
        // 0.4 em of its own size above the line it refers from.
        self::assertEqualsWithDelta($body['y'] + $mark['size'] * 0.4, $mark['y'], 0.01);
    }

    #[Test]
    public function the_notes_are_set_at_the_foot_in_the_order_they_were_marked(): void
    {
        $out = self::flowed('一((最初の注))と二((次の注))と三((三つ目))。', new FootnoteStyle(reserve: 30.0));
        $runs = self::runs($out);

        $marks = self::atSize($runs, 12.0 * 0.7);
        self::assertSame('123', \implode('', \array_column($marks, 'text')));

        // Horizontal text is drawn a character at a time, so a note is a run of
        // glyphs sharing a baseline rather than one string.
        $notes = self::atSize($runs, 8.0);
        $lines = self::linesOf($notes);
        self::assertSame(['1 最初の注', '2 次の注', '3 三つ目'], \array_column($lines, 1));

        // Below the body, and in order down the page.
        self::assertLessThan($runs[0]['y'], $lines[0][0]);
        self::assertGreaterThan($lines[1][0], $lines[0][0]);
        self::assertGreaterThan($lines[2][0], $lines[1][0]);

        self::assertStringContainsString("l\nS", $out); // the rule above them
    }

    #[Test]
    public function each_page_carries_only_its_own_notes(): void
    {
        $filler = \str_repeat('本文が続く。', 12);
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage();
        $pdf->flowInto(
            FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: 70),
            "はじめ((前の注))。{$filler}\n\n{$filler}\n\nおわり((後の注))。",
            font: CidFontJapanese::HEISEI_MIN_W3,
            size: 12.0,
            footnotes: new FootnoteStyle(reserve: 18.0),
        );
        $out = $pdf->output();
        self::assertGreaterThan(1, $pdf->pageCount());

        // Grouping by baseline would merge them: two pages put their notes at
        // the same height. Each is drawn once, under its own mark.
        $set = \implode('', \array_column(self::atSize(self::runs($out), 8.0), 'text'));
        self::assertSame(1, \substr_count($set, '前の注'));
        self::assertSame(1, \substr_count($set, '後の注'));
        self::assertSame('1 前の注2 後の注', $set);

        $marks = \implode('', \array_column(self::atSize(self::runs($out), 12.0 * 0.7), 'text'));
        self::assertSame('12', $marks);
    }

    #[Test]
    public function the_reserved_band_is_taken_out_of_the_body(): void
    {
        $text = \implode("\n\n", \array_fill(0, 6, \str_repeat('本文が続く。', 8)));

        $withoutNotes = self::runs(self::flowed($text, null));
        $withBand = self::runs(self::flowed($text, new FootnoteStyle(reserve: 25.0)));

        // The band is kept whether or not a note lands in it, so the body stops
        // higher up the page.
        self::assertGreaterThan(
            \min(\array_column($withoutNotes, 'y')),
            \min(\array_column($withBand, 'y')),
        );
    }

    #[Test]
    public function notes_that_outgrow_their_band_are_reported_rather_than_drawn_over_the_body(): void
    {
        $logger = new RecordingLogger();
        self::flowed(
            '本((' . \str_repeat('長い注が延々と続く。', 12) . '))と',
            new FootnoteStyle(reserve: 6.0),
            $logger,
        );

        self::assertNotSame([], \array_filter(
            $logger->records,
            static fn (array $r): bool => \str_contains($r['message'], 'overrun their reserve'),
        ));
    }

    #[Test]
    public function markup_that_cannot_be_honoured_is_printed_rather_than_swallowed(): void
    {
        // typeset() draws one block wherever it is told; it paginates nothing
        // and so can place no note. Dropping the words would be worse than
        // showing the brackets.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->addPage()->typeset('本((注記))と', 20, 30, 160, font: CidFontJapanese::HEISEI_MIN_W3, size: 12.0);

        $text = \implode('', \array_column(self::runs($pdf->output()), 'text'));
        self::assertStringContainsString('((注記))', $text);
    }

    #[Test]
    public function a_flow_without_footnotes_is_untouched(): void
    {
        $plain = self::flowed('本と続く', null);
        $same = self::flowed('本と続く', new FootnoteStyle()); // reserve 0

        self::assertSame(self::runs($plain), self::runs($same));
    }

    #[Test]
    public function the_band_is_worked_out_per_page_by_default(): void
    {
        self::assertNull((new FootnoteStyle())->reserve);

        $para = '　本文が続く。' . \str_repeat('組版の話をする。', 10);
        $note = \str_repeat('この注はそれなりに長い。', 4);
        $body = \implode("\n\n", ["はじめ(({$note}))。{$para}", ...\array_fill(0, 10, $para)]);

        $withBand = self::runs(self::flowed($body, new FootnoteStyle()));
        $noBand = self::runs(self::flowed($body, new FootnoteStyle(reserve: 0.0)));

        // The body gives up lines to make room, so the same text needs more of
        // them elsewhere.
        $bodyLines = static fn (array $runs): int => \count(self::atSize($runs, 12.0));
        self::assertLessThan($bodyLines($noBand), $bodyLines($withBand));
    }

    #[Test]
    public function a_note_found_late_still_lands_on_its_own_page(): void
    {
        // The page floor: 120 mm down an A4 page, in PDF points.
        $floor = 841.89 - 120.0 * 2.8346456692913;

        $para = '　本文が続く。' . \str_repeat('組版の話をする。', 10);
        $note = \str_repeat('この注はそれなりに長い。', 4);
        $runs = self::runs(self::flowed(
            \implode("\n\n", ["はじめ(({$note}))。{$para}", ...\array_fill(0, 10, $para)]),
            new FootnoteStyle(),
        ));

        // Worked out rather than stated, the band is deep enough that the notes
        // sit inside the page instead of running off the bottom of it.
        $notes = self::atSize($runs, 8.0);
        self::assertNotSame([], $notes);
        self::assertGreaterThan($floor, \min(\array_column($notes, 'y')));
    }

    #[Test]
    public function laying_a_page_out_again_costs_no_memory_of_its_own(): void
    {
        // Every page here carries a note, so every page is laid out at least
        // twice. What that must not do is hold anything: the comparison is
        // against the same flow with notes switched off, not against a smaller
        // document — the tokens of a longer text grow either way, and measuring
        // that would only prove the input got bigger.
        $peak = static function (?float $reserve): int {
            $note = \str_repeat('注が付く。', 3);
            $body = \implode("\n\n", \array_fill(
                0,
                200,
                "段落(({$note}))。" . \str_repeat('本文が続く。', 12),
            ));

            $pdf = new Document(credit: CreditOptions::disabled());
            $pdf->addPage();
            \gc_collect_cycles();
            \memory_reset_peak_usage();
            $before = \memory_get_usage();
            $pdf->flowInto(
                FlowRegion::horizontal(x: 20, topY: 20, width: 160, bottomY: 140),
                $body,
                font: CidFontJapanese::HEISEI_MIN_W3,
                size: 11.0,
                footnotes: new FootnoteStyle(reserve: $reserve),
            );

            return \memory_get_peak_usage() - $before;
        };

        $peak(0.0);                 // warm the allocator so the first run is not the outlier
        $withoutNotes = $peak(0.0);
        $reLaidOut = $peak(null);

        self::assertLessThan(
            $withoutNotes * 1.5,
            $reLaidOut,
            \sprintf(
                'Re-laying pages out should not hold memory: %.2f MB against %.2f MB with the notes off.',
                $reLaidOut / 1048576,
                $withoutNotes / 1048576,
            ),
        );
    }

    #[Test]
    public function a_negative_reserve_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FootnoteStyle(reserve: -1.0);
    }
}
