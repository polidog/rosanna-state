<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Storage\StorageType;

final class ActionTest extends TestCase
{
    public function testFactoriesAndRoundTrip(): void
    {
        foreach ([
            Action::setState(1, ['a' => 1.0], 'C#0', StorageType::Snapshot),
            Action::setState(0, null),
            Action::setStateDiff(2, ['r', 1], str_repeat('a', 64), 'C#0'),
            Action::setFields(['name' => 0, 'mail' => 1], 'C#0', StorageType::Session),
            Action::restore('C#0', StorageType::Snapshot),
        ] as $action) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($action->toJson(), true);

            self::assertEquals($action, Action::fromArray($decoded));
            self::assertSame($action->toJson(), Action::fromArray($decoded)->toJson());
        }
    }

    public function testVR1WithVersionIsInTheJson(): void
    {
        $action = Action::setState(0, 1, 'C#0');
        $versioned = $action->withVersion(7);

        self::assertNull($action->version);
        self::assertSame(7, $versioned->version);
        self::assertSame(7, json_decode($versioned->toJson(), true)['version']);
        self::assertSame(7, Action::fromArray((array) json_decode($versioned->toJson(), true))->version);
        self::assertSame($action->payload, $versioned->payload);
    }

    public function testAC2ValueIsFixedAtRenderTime(): void
    {
        self::assertSame(['index' => 0, 'value' => 5], Action::setState(0, 5)->payload);
    }

    public function testAC1RestoreConstantsAndUnknownType(): void
    {
        self::assertSame('setState', Action::SET_STATE);
        self::assertSame('setFields', Action::SET_FIELDS);
        self::assertSame('restore', Action::RESTORE);

        $this->expectException(\InvalidArgumentException::class);
        Action::fromArray(['type' => 'deleteEverything', 'payload' => []]);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformed(): iterable
    {
        $ok = ['type' => 'setState', 'payload' => ['index' => 0, 'value' => 1]];

        yield 'no type' => [['payload' => []]];
        yield 'empty type' => [['type' => '']];
        yield 'int type' => [['type' => 1]];
        yield 'array type' => [['type' => ['setState']]];
        yield 'scalar payload' => [['payload' => 'x'] + $ok];
        yield 'int componentId' => [['componentId' => 1] + $ok];
        yield 'array componentId' => [['componentId' => ['x']] + $ok];
        yield 'unknown storage' => [['storageType' => 'redis'] + $ok];
        yield 'int storage' => [['storageType' => 1] + $ok];
        yield 'empty storage' => [['storageType' => ''] + $ok];
        yield 'string version' => [['version' => '1'] + $ok];
        yield 'float version' => [['version' => 1.0] + $ok];
        yield 'bool version' => [['version' => true] + $ok];
        yield 'setState without value' => [['type' => 'setState', 'payload' => ['index' => 0]]];
        yield 'setState value and patch' => [['type' => 'setState', 'payload' => ['index' => 0, 'value' => 1, 'patch' => ['r', 1], 'base' => 'x']]];
        yield 'setState value and base' => [['type' => 'setState', 'payload' => ['index' => 0, 'value' => 1, 'base' => 'x']]];
        yield 'setState patch without base' => [['type' => 'setState', 'payload' => ['index' => 0, 'patch' => ['r', 1]]]];
        yield 'setState base without patch' => [['type' => 'setState', 'payload' => ['index' => 0, 'base' => 'x']]];
        yield 'scalar patch' => [['type' => 'setState', 'payload' => ['index' => 0, 'patch' => 'r', 'base' => 'x']]];
        yield 'int base' => [['type' => 'setState', 'payload' => ['index' => 0, 'patch' => ['r', 1], 'base' => 1]]];
        yield 'setFields no fields' => [['type' => 'setFields', 'payload' => []]];
        yield 'setFields scalar' => [['type' => 'setFields', 'payload' => ['fields' => 'x']]];
        yield 'setFields empty' => [['type' => 'setFields', 'payload' => ['fields' => []]]];
        yield 'setFields list' => [['type' => 'setFields', 'payload' => ['fields' => [0]]]];
        yield 'setFields string index' => [['type' => 'setFields', 'payload' => ['fields' => ['a' => '0']]]];
        yield 'restore with payload' => [['type' => 'restore', 'payload' => ['index' => 0]]];
    }

    /** @param array<mixed> $data */
    #[DataProvider('malformed')]
    public function testAC3MalformedShapeIsInvalidArgumentNotTypeError(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Action::fromArray($data);
    }

    public function testAC3NullableFieldsAndNullValue(): void
    {
        $action = Action::fromArray(['type' => 'setState', 'payload' => ['index' => 0, 'value' => null]]);

        self::assertNull($action->componentId);
        self::assertNull($action->storageType);
        self::assertNull($action->version);
        self::assertSame(['index' => 0, 'value' => null], $action->payload);
    }

    /** @return iterable<string, array{mixed}> */
    public static function outOfRangeIndexes(): iterable
    {
        yield 'negative' => [-1];
        yield 'over' => [256];
        yield 'string' => ['0'];
        yield 'float' => [0.0];
        yield 'null' => [null];
        yield 'array' => [[0]];
    }

    #[DataProvider('outOfRangeIndexes')]
    public function testAC5IndexOutOfRange(mixed $index): void
    {
        try {
            Action::fromArray(['type' => 'setState', 'payload' => ['index' => $index, 'value' => 1]]);
            self::fail('setState');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        Action::fromArray(['type' => 'setFields', 'payload' => ['fields' => ['a' => $index]]]);
    }

    public function testAC5BoundsAreAccepted(): void
    {
        foreach ([0, 255] as $index) {
            self::assertSame($index, Action::fromArray(['type' => 'setState', 'payload' => ['index' => $index, 'value' => 1]])->payload['index']);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function instanceIds(): iterable
    {
        yield 'valid' => ['Counter#0', true];
        yield 'class name' => ['App\\Counter#a/b', true];
        yield 'exactly 255 bytes' => [str_repeat('a', 253) . '#0', true];
        yield 'too long' => [str_repeat('a', 254) . '#0', false];
        yield 'no hash' => ['Counter', false];
        yield 'empty' => ['', false];
        yield 'newline' => ["C#\n0", false];
        yield 'nul' => ["C#\x000", false];
        yield 'del' => ["C#\x7F", false];
    }

    #[DataProvider('instanceIds')]
    public function testAC6InstanceId(string $id, bool $valid): void
    {
        self::assertSame($valid, Action::isValidInstanceId($id));

        if (!$valid) {
            $this->expectException(\InvalidArgumentException::class);
        }
        self::assertSame($id, Action::fromArray(['type' => 'restore', 'componentId' => $id])->componentId);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function notRoundTrippable(): iterable
    {
        yield 'object' => [new \stdClass(), 'stdClass'];
        yield 'date' => [['when' => new \DateTimeImmutable()], 'when'];
        yield 'json serializable' => [new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return 1;
            }
        }, 'JsonSerializable'];
        yield 'closure' => [['a' => ['b' => static fn() => 1]], 'a.b'];
        yield 'nan' => [NAN, 'NAN'];
        yield 'inf' => [['x' => INF], 'INF'];
        yield 'negative inf' => [[-INF], '-INF'];
    }

    #[DataProvider('notRoundTrippable')]
    public function testAC4ValuesThatChangeInTheRoundTripAreRefusedWithTheirPlace(mixed $value, string $mention): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($mention);
        Action::setState(0, $value)->toJson();
    }

    public function testAC4DepthLimit(): void
    {
        $nest = static function (int $levels): array {
            $value = [];
            for ($i = 1; $i < $levels; $i++) {
                $value = [$value];
            }

            return $value;
        };

        Action::assertJsonRoundTrippable($nest(512));
        $this->addToAssertionCount(1);

        // payload と全体の 2 段を足しても JSON のデコードの既定の深さに収まる
        $json = Action::setState(0, $nest(509))->toJson();
        self::assertIsArray(json_decode($json, true));

        foreach ([$nest(513), $nest(600)] as $tooDeep) {
            try {
                Action::setState(0, $tooDeep)->toJson();
                self::fail('too deep');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // 値は上限の内でも action 全体が上限を超える。JsonException にしない
        $this->expectException(\InvalidArgumentException::class);
        Action::setState(0, $nest(512))->toJson();
    }

    public function testAC4Cycle(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;

        $this->expectException(\InvalidArgumentException::class);
        Action::setState(0, $cycle)->toJson();
    }

    /** @return iterable<string, array{mixed}> */
    public static function roundTrippable(): iterable
    {
        yield 'null' => [null];
        yield 'float with zero fraction' => [1.0];
        yield 'large float' => [1.0E+25];
        yield 'negative int' => [-5];
        yield 'unicode' => ['日本語/</script>'];
        yield 'empty array' => [[]];
        yield 'list' => [[1, 'a', null, [2.5]]];
        yield 'map with gaps' => [[1 => 'a', 3 => 'b']];
        yield 'out of order keys' => [['b' => 1, 'a' => 2, 5 => 3, 0 => 4]];
    }

    #[DataProvider('roundTrippable')]
    public function testAC4ValueComesBackIdentical(mixed $value): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(Action::setState(0, $value)->toJson(), true);

        self::assertSame($value, Action::fromArray($decoded)->payload['value']);
    }

    public function testInvalidUtf8DoesNotEmptyTheOutput(): void
    {
        $json = Action::setState(0, "bad\xFF")->toJson();

        self::assertSame("bad\u{FFFD}", json_decode($json, true)['payload']['value']);
    }
}
