<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\StateDiff;

final class StateDiffTest extends TestCase
{
    private const SEED = 20261101;

    /** @return list<array{id: int, title: string, done: bool}> */
    private static function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['id' => $i, 'title' => "row {$i} with some text", 'done' => false];
        }

        return $rows;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, Action::JSON_FLAGS);
    }

    /** ブラウザとの往復。署名された action の JSON を読み直すのと同じ */
    private static function throughJson(mixed $value): mixed
    {
        return json_decode(self::json($value), true);
    }

    /** @param array<mixed> $patch */
    private static function assertAppliesExactly(mixed $base, array $patch, mixed $next, string $message = ''): void
    {
        self::assertSame($next, StateDiff::apply($base, $patch), $message);

        $carried = self::throughJson($patch);
        self::assertIsArray($carried);
        self::assertSame($next, StateDiff::apply(self::throughJson($base), $carried), $message . ' (through JSON)');
    }

    private static function randomScalar(): mixed
    {
        return match (mt_rand(0, 7)) {
            0 => null,
            1 => true,
            2 => false,
            3 => mt_rand(-1000, 1000),
            4 => mt_rand(-1000, 1000) / 4,
            5 => (float) mt_rand(0, 9),
            6 => '日本語' . mt_rand(0, 99),
            default => 'text "' . mt_rand(0, 99) . '" </x>',
        };
    }

    private static function randomKey(): int|string
    {
        return match (mt_rand(0, 4)) {
            0 => mt_rand(0, 20),
            1 => (string) mt_rand(0, 20) . 'x',
            2 => 'key' . mt_rand(0, 20),
            3 => mt_rand(-5, -1),
            default => '',
        };
    }

    private static function randomValue(int $depth = 0): mixed
    {
        if ($depth > 3 || mt_rand(0, 2) === 0) {
            return self::randomScalar();
        }

        $count = mt_rand(0, 6);
        $value = [];
        if (mt_rand(0, 1) === 0) {
            for ($i = 0; $i < $count; $i++) {
                $value[] = self::randomValue($depth + 1);
            }
        } else {
            for ($i = 0; $i < $count; $i++) {
                $value[self::randomKey()] = self::randomValue($depth + 1);
            }
        }

        return $value;
    }

    /** 基準の値に近い値を作る。差分が実際に使われる形 */
    private static function mutate(mixed $value, int $depth = 0): mixed
    {
        if (!is_array($value) || $value === [] || mt_rand(0, 5) === 0) {
            return self::randomValue($depth);
        }

        if (array_is_list($value)) {
            switch (mt_rand(0, 4)) {
                case 0:
                    array_unshift($value, self::randomValue($depth + 1));

                    return $value;
                case 1:
                    $value[] = self::randomValue($depth + 1);

                    return $value;
                case 2:
                    array_splice($value, mt_rand(0, count($value) - 1), 1);

                    return $value;
                case 3:
                    array_splice($value, mt_rand(0, count($value)), mt_rand(0, 2), [self::randomValue($depth + 1), self::randomValue($depth + 1)]);

                    return $value;
                default:
                    $at = mt_rand(0, count($value) - 1);
                    $value[$at] = self::mutate($value[$at], $depth + 1);

                    return $value;
            }
        }

        $keys = array_keys($value);
        $key = $keys[mt_rand(0, count($keys) - 1)];
        switch (mt_rand(0, 3)) {
            case 0:
                unset($value[$key]);

                return $value;
            case 1:
                $value[self::randomKey()] = self::randomValue($depth + 1);

                return $value;
            case 2:
                // キーの順序を変える
                $moved = $value[$key];
                unset($value[$key]);
                $value[$key] = $moved;

                return $value;
            default:
                $value[$key] = self::mutate($value[$key], $depth + 1);

                return $value;
        }
    }

    public function testAC13RandomUnrelatedValues(): void
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < 3000; $i++) {
            $base = self::randomValue();
            $next = self::randomValue();

            $patch = StateDiff::create($base, $next);
            if ($patch !== null) {
                self::assertAppliesExactly($base, $patch, $next, "iteration {$i}");
            }
        }
    }

    public function testAC13RandomSimilarValuesWithKeyOrderAndJsonRoundTrip(): void
    {
        mt_srand(self::SEED + 1);
        $used = 0;
        $refused = 0;

        for ($i = 0; $i < 3000; $i++) {
            // 大きさの比較を越えるよう、十分に大きい値に混ぜる
            $inner = self::randomValue();
            $filler = str_repeat('x', 400);
            $base = mt_rand(0, 1) === 0
                ? ['filler' => $filler, 'inner' => $inner, 'other' => ['a' => 1, 'b' => [1, 2]]]
                : [$filler, $inner, $filler];

            $next = self::mutate($base);
            for ($m = mt_rand(0, 3); $m > 0; $m--) {
                $next = self::mutate($next);
            }

            $patch = StateDiff::create($base, $next);
            if ($patch === null) {
                $refused++;

                continue;
            }

            $used++;
            self::assertAppliesExactly($base, $patch, $next, "iteration {$i}");
        }

        self::assertGreaterThan(1000, $used, 'the property has to be exercised');
        self::assertGreaterThan(0, $refused);
    }

    public function testAC13KeyOrderChangeIsNotDiffedWrongly(): void
    {
        $filler = str_repeat('x', 500);
        $base = ['a' => $filler, 'b' => 2, 'c' => 3];
        $next = ['b' => 2, 'a' => $filler, 'c' => 3];

        // `===` は順序も比べる。順序を再現できなければ null
        self::assertNull(StateDiff::create($base, $next));
    }

    public function testAC13AddedKeyAtTheEndIsDiffed(): void
    {
        $filler = str_repeat('x', 500);
        $base = ['a' => $filler, 'b' => 2];
        $next = ['a' => $filler, 'b' => 2, 'c' => 3];

        $patch = StateDiff::create($base, $next);

        self::assertNotNull($patch);
        self::assertAppliesExactly($base, $patch, $next);
    }

    public function testAC13AddedKeyBeforeAnotherFallsBackToValue(): void
    {
        $filler = str_repeat('x', 500);

        self::assertNull(StateDiff::create(['a' => $filler], ['new' => 1, 'a' => $filler]));
    }

    public function testAC13ValueTypeChangeIsCaught(): void
    {
        $base = [str_repeat('x', 500), 1, 'same'];
        $next = [str_repeat('x', 500), 1.0, 'same'];

        $patch = StateDiff::create($base, $next);

        self::assertNotNull($patch);
        self::assertSame(1.0, StateDiff::apply($base, $patch)[1]);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function tooSmallToDiff(): iterable
    {
        yield 'scalar' => [1, 2];
        yield 'small list' => [[1, 2, 3], [1, 2, 4]];
        yield 'small map' => [['a' => 1], ['a' => 2]];
        yield 'growth larger than the base' => [[1, 2], array_fill(0, 500, 'long value')];
        yield 'from empty' => [[], self::rows(50)];
        yield 'replacement larger than the base' => [str_repeat('x', 10), str_repeat('y', 1000)];
    }

    #[DataProvider('tooSmallToDiff')]
    public function testAC13LargerDiffIsNull(mixed $base, mixed $next): void
    {
        self::assertNull(StateDiff::create($base, $next));
    }

    public function testAC13ValuesJsonCannotCarryAreNull(): void
    {
        $base = array_fill(0, 200, 'value');
        $next = $base;
        $next[10] = NAN;

        self::assertNull(StateDiff::create($base, $next));
    }

    /** @return iterable<string, array{\Closure(list<array{id: int, title: string, done: bool}>): list<array{id: int, title: string, done: bool}>, string}> */
    public static function rowOperations(): iterable
    {
        yield 'toggle in the middle' => [static function (array $rows) {
            $at = (int) (count($rows) / 2);
            $rows[$at]['done'] = !$rows[$at]['done'];

            return $rows;
        }, 'l'];
        yield 'delete the first with array_values and array_filter' => [
            static fn(array $rows) => array_values(array_filter($rows, static fn(array $r) => $r['id'] !== 0)),
            's',
        ];
        yield 'delete in the middle with array_values and array_filter' => [static function (array $rows) {
            $id = (int) (count($rows) / 2);

            return array_values(array_filter($rows, static fn(array $r) => $r['id'] !== $id));
        }, 's'];
        yield 'prepend' => [
            static fn(array $rows) => [['id' => -1, 'title' => 'new', 'done' => false], ...$rows],
            's',
        ];
        yield 'append' => [
            static fn(array $rows) => [...$rows, ['id' => -1, 'title' => 'new', 'done' => false]],
            's',
        ];
    }

    /** @param \Closure(list<array{id: int, title: string, done: bool}>): list<array{id: int, title: string, done: bool}> $operation */
    #[DataProvider('rowOperations')]
    public function testAC14DiffDoesNotGrowWithTheNumberOfRows(\Closure $operation, string $form): void
    {
        $sizes = [];
        foreach ([50, 500, 5000] as $count) {
            $rows = self::rows($count);
            $next = $operation($rows);

            $patch = StateDiff::create($rows, $next);

            self::assertNotNull($patch, "{$count} rows");
            self::assertSame($form, $patch[0]);
            self::assertAppliesExactly($rows, $patch, $next);
            $sizes[$count] = strlen(self::json($patch));
        }

        self::assertLessThan(120, $sizes[5000]);
        self::assertLessThanOrEqual($sizes[50] + 8, $sizes[5000], 'only the digits of a position may grow');
    }

    public function testAC14ToggleIsAnItemPatchOfAMapPatch(): void
    {
        $rows = self::rows(50);
        $next = $rows;
        $next[7]['done'] = true;

        self::assertSame(['l', 7, ['m', ['done' => ['r', true]], []]], StateDiff::create($rows, $next));
    }

    public function testAC14Formats(): void
    {
        $long = str_repeat('x', 500);

        self::assertSame(['s', 1, 2, ['n']], StateDiff::create([$long, 'a', 'b', $long], [$long, 'n', $long]));
        self::assertSame(['s', 1, 1, []], StateDiff::create([$long, 'a', $long], [$long, $long]));
        self::assertSame(['s', 1, 0, ['n']], StateDiff::create([$long, $long], [$long, 'n', $long]));
        self::assertSame(['m', ['b' => ['r', 2]], ['c']], StateDiff::create(['a' => $long, 'b' => 1, 'c' => 3], ['a' => $long, 'b' => 2]));
        self::assertSame(['r', 'short'], StateDiff::create([$long], 'short'));
        self::assertSame(['m', [], ['k']], StateDiff::create(['a' => $long, 'k' => 1], ['a' => $long]));
    }

    public function testAC14ListAndMapAreDifferentShapes(): void
    {
        $long = str_repeat('x', 500);

        self::assertSame(['r', ['a' => 1]], StateDiff::create([$long], ['a' => 1]));
        self::assertSame(['r', [1]], StateDiff::create(['a' => $long], [1]));
    }

    public function testRepeatedElementsDoNotConfuseTheCommonTail(): void
    {
        $long = str_repeat('x', 300);
        $base = [$long, $long, $long];
        $next = [$long, $long];

        $patch = StateDiff::create($base, $next);

        self::assertNotNull($patch);
        self::assertAppliesExactly($base, $patch, $next);
    }

    /** @return iterable<string, array{mixed}> */
    public static function hashable(): iterable
    {
        yield 'list' => [[1, 2.0, 'a', null, true]];
        yield 'map' => [['b' => 1, 'a' => [], 3 => 'c']];
        yield 'scalar' => [1.5];
        yield 'null' => [null];
        yield 'unicode' => ['日本語'];
        yield 'invalid utf-8' => ["bad\xFF"];
    }

    #[DataProvider('hashable')]
    public function testAC15HashSurvivesTheRoundTrip(mixed $value): void
    {
        self::assertSame(StateDiff::hash($value), StateDiff::hash(self::throughJson($value)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', StateDiff::hash($value));
    }

    public function testAC15HashDistinguishesTypeAndOrder(): void
    {
        self::assertNotSame(StateDiff::hash(1), StateDiff::hash(1.0));
        self::assertNotSame(StateDiff::hash(1), StateDiff::hash('1'));
        self::assertNotSame(StateDiff::hash(['a' => 1, 'b' => 2]), StateDiff::hash(['b' => 2, 'a' => 1]));
        self::assertSame(hash('sha256', '[1.0,"日本語"]'), StateDiff::hash([1.0, '日本語']));
    }

    public function testAC15UnhashableIsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StateDiff::hash(NAN);
    }

    /** @return iterable<string, array{mixed, array<mixed>}> */
    public static function malformedPatches(): iterable
    {
        yield 'empty' => [[], []];
        yield 'not a list' => [[], ['op' => 'r']];
        yield 'unknown' => [[], ['x', 1]];
        yield 'int op' => [[], [1, 2]];
        yield 'r without value' => [[], ['r']];
        yield 'r too long' => [[], ['r', 1, 2]];
        yield 'l on scalar' => [1, ['l', 0, ['r', 1]]];
        yield 'l on map' => [['a' => 1], ['l', 0, ['r', 1]]];
        yield 'l out of range' => [[1], ['l', 5, ['r', 1]]];
        yield 'l negative' => [[1], ['l', -1, ['r', 1]]];
        yield 'l string position' => [[1], ['l', '0', ['r', 1]]];
        yield 'l scalar patch' => [[1], ['l', 0, 'r']];
        yield 'l short' => [[1], ['l', 0]];
        yield 's on scalar' => [1, ['s', 0, 0, []]];
        yield 's on map' => [['a' => 1], ['s', 0, 0, []]];
        yield 's past the end' => [[1], ['s', 0, 2, []]];
        yield 's negative start' => [[1], ['s', -1, 0, []]];
        yield 's negative count' => [[1], ['s', 0, -1, []]];
        yield 's scalar items' => [[1], ['s', 0, 0, 'x']];
        yield 's short' => [[1], ['s', 0, 0]];
        yield 'm on scalar' => [1, ['m', [], []]];
        yield 'm scalar set' => [['a' => 1], ['m', 'x', []]];
        yield 'm scalar item' => [['a' => 1], ['m', ['a' => 1], []]];
        yield 'm scalar removed' => [['a' => 1], ['m', [], 'a']];
        yield 'm array removed key' => [['a' => 1], ['m', [], [['a']]]];
        yield 'm short' => [['a' => 1], ['m', []]];
        yield 'nested malformed' => [['a' => [1]], ['m', ['a' => ['l', 9, ['r', 1]]], []]];
    }

    /** @param array<mixed> $patch */
    #[DataProvider('malformedPatches')]
    public function testApplyRefusesMalformedPatches(mixed $base, array $patch): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StateDiff::apply($base, $patch);
    }

    public function testActionCarriesTheDiffAndBase(): void
    {
        $rows = self::rows(100);
        $next = $rows;
        $next[3]['done'] = true;

        $patch = StateDiff::create($rows, $next);
        self::assertNotNull($patch);
        $hash = StateDiff::hash($rows);

        $json = Action::setStateDiff(0, $patch, $hash, 'C#0')->toJson();
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        $action = Action::fromArray($decoded);

        self::assertLessThan(250, strlen($json));
        self::assertSame($hash, $action->payload['base']);
        self::assertIsArray($action->payload['patch']);
        self::assertSame($next, StateDiff::apply($rows, $action->payload['patch']));
    }
}
