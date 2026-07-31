<?php

declare(strict_types=1);

/**
 * MejiroPDF — A modern, stream-first, memory-efficient PDF engine for PHP 8.3+.
 *
 * Copyright (C) 2026 Yusuke Tsubonoue <support@2bo-velocity.com>
 * Licensed under the GNU AGPL v3 or later <https://www.gnu.org/licenses/>.
 */

namespace MejiroPDF\Tests\Unit\Block;

use MejiroPDF\Block\Condition;
use MejiroPDF\Block\DataPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Condition::class)]
#[CoversClass(DataPath::class)]
final class ConditionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return [
            'props' => ['showBank' => true, 'hideSeal' => false, 'paymentMethod' => 'bank', 'copies' => 3],
            'items' => [['name' => 'A'], ['name' => 'B']],
            'empty' => [],
            'note' => '',
            'total' => '1200',
        ];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function expressions(): iterable
    {
        yield 'bare true flag' => ['props.showBank', true];
        yield 'bare false flag' => ['props.hideSeal', false];
        yield 'negated flag' => ['!props.hideSeal', true];
        yield 'missing path is false' => ['props.nope', false];
        yield 'empty string is false' => ['note', false];
        yield 'non-empty list is true' => ['items', true];
        yield 'empty list is false' => ['empty', false];

        yield 'length of a list' => ['items.length > 0', true];
        yield 'length of an empty list' => ['empty.length > 0', false];

        yield 'strict equality on a string' => ["props.paymentMethod === 'bank'", true];
        yield 'strict inequality' => ["props.paymentMethod !== 'card'", true];
        yield 'double-quoted literal' => ['props.paymentMethod == "bank"', true];
        yield 'boolean literal' => ['props.showBank === true', true];
        yield 'boolean literal mismatch' => ['props.hideSeal === true', false];
        yield 'null literal' => ['props.nope === null', true];

        yield 'numeric comparison' => ['props.copies >= 3', true];
        yield 'numeric comparison fails' => ['props.copies > 3', false];
        // Template data routinely arrives as strings (JSON, form input), so a
        // numeric string must compare as a number.
        yield 'numeric string compares numerically' => ['total > 1000', true];
        yield 'numeric string equals a number' => ['total === 1200', true];

        yield 'conjunction' => ["props.showBank && props.paymentMethod === 'bank'", true];
        yield 'conjunction with a false side' => ['props.showBank && props.hideSeal', false];
        yield 'disjunction' => ['props.hideSeal || items.length > 1', true];
    }

    #[Test]
    #[DataProvider('expressions')]
    public function it_evaluates(string $expression, bool $expected): void
    {
        self::assertSame($expected, Condition::isTrue($expression, $this->data()), $expression);
    }

    #[Test]
    public function no_condition_means_the_block_is_drawn(): void
    {
        self::assertTrue(Condition::isTrue(null, []));
        self::assertTrue(Condition::isTrue('', []));
        self::assertTrue(Condition::isTrue('   ', []));
    }

    #[Test]
    public function a_malformed_expression_leaves_the_block_visible(): void
    {
        // Failing open is deliberate: a typo should show up on the page, not
        // silently delete content from every document in a batch.
        self::assertTrue(Condition::isTrue('=== "bank"', $this->data()));
        self::assertTrue(Condition::isTrue(\str_repeat('a', 600), $this->data()));
    }

    #[Test]
    public function it_never_executes_the_expression(): void
    {
        // The grammar has no production for a call, so this is inert text.
        // Were eval() involved, the file would exist by the time we look.
        $canary = \sys_get_temp_dir() . '/mejiro_condition_canary_' . \bin2hex(\random_bytes(6));

        Condition::isTrue(\sprintf("touch('%s')", $canary), []);
        Condition::isTrue(\sprintf("props.x === '\" . touch('%s') . \"'", $canary), []);

        self::assertFileDoesNotExist($canary);
    }

    #[Test]
    public function data_path_walks_nested_records(): void
    {
        $data = ['customer' => ['address' => ['city' => '東京']]];

        self::assertSame('東京', DataPath::resolve($data, 'customer.address.city'));
        self::assertNull(DataPath::resolve($data, 'customer.address.zip'));
        self::assertNull(DataPath::resolve($data, 'customer.name.first'));
        self::assertNull(DataPath::resolve($data, ''));
    }

    #[Test]
    public function data_path_counts_characters_not_bytes(): void
    {
        self::assertSame(3, DataPath::resolve(['name' => 'あいう'], 'name.length'));
    }

    #[Test]
    public function rows_of_a_missing_source_is_an_empty_list(): void
    {
        self::assertSame([], DataPath::rows([], 'items'));
        self::assertSame([], DataPath::rows(['items' => 'not a list'], 'items'));
        self::assertCount(2, DataPath::rows(['items' => [['a'], ['b']]], 'items'));
    }
}
