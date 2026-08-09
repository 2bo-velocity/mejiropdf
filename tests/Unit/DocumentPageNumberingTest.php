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
use MejiroPDF\Page\CreditOptions;
use MejiroPDF\Page\NumberStyle;
use MejiroPDF\Page\PageNumberOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Front matter numbered i, ii, iii and a body that starts again at 1 — so that
 * material can be added to either without renumbering the other.
 */
#[CoversClass(Document::class)]
#[CoversClass(NumberStyle::class)]
#[CoversClass(PageNumberOptions::class)]
final class DocumentPageNumberingTest extends TestCase
{
    /**
     * Every printed page number, in page order, less the licence marker.
     *
     * @return list<string>
     */
    private static function numbers(string $pdf): array
    {
        \preg_match_all("~[\d.]+ [\d.]+ Td\n\((.*?)\) Tj~", $pdf, $m);

        return \array_values(\array_filter(
            $m[1],
            static fn (string $text): bool => !\str_contains($text, 'Powered by MejiroPDF'),
        ));
    }

    private static function pages(Document $pdf, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $pdf->addPage();
        }
    }

    #[Test]
    public function front_matter_and_body_are_numbered_separately(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', style: NumberStyle::ROMAN_LOWER));
        self::pages($pdf, 3);
        $pdf->restartPageNumbering(NumberStyle::ARABIC);
        self::pages($pdf, 4);

        self::assertSame(['i', 'ii', 'iii', '1', '2', '3', '4'], self::numbers($pdf->output()));
    }

    #[Test]
    public function a_sequence_begins_with_the_next_page_opened(): void
    {
        // The call sits between the front matter and the body, not inside
        // either. A page joins the committed list only when the next one opens,
        // so counting that list alone would start the sequence a page early.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', style: NumberStyle::ROMAN_LOWER));
        self::pages($pdf, 2);
        $pdf->restartPageNumbering(NumberStyle::ARABIC);
        self::pages($pdf, 1);

        self::assertSame(['i', 'ii', '1'], self::numbers($pdf->output()));
    }

    #[Test]
    public function a_run_can_carry_no_number_at_all(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}'));
        self::pages($pdf, 2);
        $pdf->restartPageNumbering(NumberStyle::NONE);
        self::pages($pdf, 2);          // plates
        $pdf->restartPageNumbering(NumberStyle::ARABIC, from: 3);
        self::pages($pdf, 2);

        self::assertSame(['1', '2', '3', '4'], self::numbers($pdf->output()));
        self::assertSame(6, $pdf->pageCount());
    }

    #[Test]
    public function the_total_counts_the_sequence_rather_than_the_document(): void
    {
        // Anything else would be nonsense once there is more than one run:
        // "ii of 47" tells a reader nothing about the front matter.
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}/{TOTAL}', style: NumberStyle::ROMAN_LOWER));
        self::pages($pdf, 2);
        $pdf->restartPageNumbering(NumberStyle::ARABIC);
        self::pages($pdf, 3);

        self::assertSame(['i/ii', 'ii/ii', '1/3', '2/3', '3/3'], self::numbers($pdf->output()));
    }

    #[Test]
    public function a_document_without_restarts_numbers_exactly_as_before(): void
    {
        $plain = new Document(credit: CreditOptions::disabled());
        $plain->setPageNumbers(new PageNumberOptions(format: '{PAGE} / {TOTAL}', skipPages: 1, startPageNumber: 1));
        self::pages($plain, 4);

        self::assertSame(['1 / 3', '2 / 3', '3 / 3'], self::numbers($plain->output()));
    }

    #[Test]
    public function a_style_alone_needs_no_restart(): void
    {
        $pdf = new Document(credit: CreditOptions::disabled());
        $pdf->setPageNumbers(new PageNumberOptions(format: '{PAGE}', style: NumberStyle::KANJI));
        self::pages($pdf, 3);

        self::assertSame(['一', '二', '三'], self::numbers($pdf->output()));
    }

    /** @return iterable<string, array{NumberStyle, int, string}> */
    public static function spellings(): iterable
    {
        yield 'arabic' => [NumberStyle::ARABIC, 42, '42'];
        yield 'roman one' => [NumberStyle::ROMAN_LOWER, 1, 'i'];
        yield 'roman four' => [NumberStyle::ROMAN_LOWER, 4, 'iv'];
        yield 'roman nine' => [NumberStyle::ROMAN_LOWER, 9, 'ix'];
        yield 'roman forty-two' => [NumberStyle::ROMAN_LOWER, 42, 'xlii'];
        yield 'roman upper' => [NumberStyle::ROMAN_UPPER, 1987, 'MCMLXXXVII'];
        yield 'roman limit' => [NumberStyle::ROMAN_LOWER, 3999, 'mmmcmxcix'];
        // Past the numerals' reach, digits say more than a wall of m's.
        yield 'roman past its reach' => [NumberStyle::ROMAN_LOWER, 4000, '4000'];

        yield 'kanji one' => [NumberStyle::KANJI, 1, '一'];
        yield 'kanji ten' => [NumberStyle::KANJI, 10, '十'];        // not 一十
        yield 'kanji eleven' => [NumberStyle::KANJI, 11, '十一'];
        yield 'kanji twenty' => [NumberStyle::KANJI, 20, '二十'];
        yield 'kanji hundred' => [NumberStyle::KANJI, 100, '百'];    // not 一百
        yield 'kanji 123' => [NumberStyle::KANJI, 123, '百二十三'];
        yield 'kanji 1000' => [NumberStyle::KANJI, 1000, '千'];
        yield 'kanji 2024' => [NumberStyle::KANJI, 2024, '二千二十四'];
        yield 'kanji past its reach' => [NumberStyle::KANJI, 10000, '10000'];

        yield 'none' => [NumberStyle::NONE, 5, ''];

        // Roman and kanji have nothing to say about zero, and a page number
        // that lands there is the caller's arithmetic, not an unprintable
        // document — so digits rather than an exception at finalize.
        yield 'roman zero' => [NumberStyle::ROMAN_LOWER, 0, '0'];
        yield 'kanji negative' => [NumberStyle::KANJI, -3, '-3'];
    }

    #[Test]
    #[DataProvider('spellings')]
    public function it_spells_a_number_in_each_style(NumberStyle $style, int $number, string $expected): void
    {
        self::assertSame($expected, $style->format($number));
    }
}
