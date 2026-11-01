<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Snapshot\SnapshotVerificationException;

final class SnapshotSerializerTest extends TestCase
{
    private const SECRET = 'test-fixture-key-888';

    private static function serializer(string $context = ''): SnapshotSerializer
    {
        return new SnapshotSerializer(self::SECRET, $context);
    }

    private static function sample(): Snapshot
    {
        return new Snapshot('C', 'k', ['a', 1.0, ['x' => null]], [0 => [1], 1 => null], 3);
    }

    public function testSN1RoundTrip(): void
    {
        $restored = self::serializer()->deserialize(self::serializer()->serialize(self::sample()));

        self::assertSame(self::sample()->state, $restored->state);
        self::assertSame(self::sample()->effectDeps, $restored->effectDeps);
        self::assertSame(3, $restored->version);
        self::assertNotNull($restored->checksum);
    }

    public function testSN2InvalidUtf8IsReplacedAndStillVerifies(): void
    {
        $json = self::serializer()->serialize(new Snapshot('C', 'k', ["bad\xFFbyte"]));

        self::assertSame("bad\u{FFFD}byte", self::serializer()->deserialize($json)->state[0]);
    }

    public function testSN2ChecksumIsAnExistingChecksumIgnoredOnSerialize(): void
    {
        $withOld = new Snapshot('C', 'k', [], [], 0, 'stale');

        self::serializer()->deserialize(self::serializer()->serialize($withOld));
        $this->addToAssertionCount(1);
    }

    public function testSN4EmptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotSerializer('');
    }

    public function testSN4KeyShorterThanTheMinimumIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SnapshotSerializer(str_repeat('k', SnapshotSerializer::MIN_SECRET_BYTES - 1));
    }

    public function testSN4KeyOfTheMinimumLengthIsAccepted(): void
    {
        new SnapshotSerializer(str_repeat('k', SnapshotSerializer::MIN_SECRET_BYTES));
        $this->addToAssertionCount(1);
    }

    public function testSN3MissingChecksumIsRejected(): void
    {
        $this->expectException(SnapshotVerificationException::class);
        self::serializer()->deserialize(self::sample()->toJson());
    }

    public function testSN3AnotherKeyIsRejected(): void
    {
        $this->expectException(SnapshotVerificationException::class);
        new SnapshotSerializer('other-fixture-key-22')->deserialize(self::serializer()->serialize(self::sample()));
    }

    /** @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}> */
    public static function tamperings(): iterable
    {
        yield 'state' => [static fn(array $d) => ['state' => ['b']] + $d];
        yield 'state type' => [static function (array $d) {
            $d['state'][1] = 1;

            return $d;
        }];
        yield 'effectDeps' => [static fn(array $d) => ['effectDeps' => []] + $d];
        yield 'version' => [static function (array $d) {
            $d['memo']['version'] = 4;

            return $d;
        }];
        yield 'memo.name' => [static function (array $d) {
            $d['memo']['name'] = 'Other';

            return $d;
        }];
        yield 'memo.key' => [static function (array $d) {
            $d['memo']['key'] = 'other';

            return $d;
        }];
        yield 'checksum' => [static fn(array $d) => ['checksum' => str_repeat('0', 64)] + $d];
        yield 'checksum removed' => [static fn(array $d) => array_diff_key($d, ['checksum' => 1])];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $tamper */
    #[DataProvider('tamperings')]
    public function testSN3TamperedSnapshotIsRejected(\Closure $tamper): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(self::serializer()->serialize(self::sample()), true);
        $json = json_encode($tamper($data), JSON_PRESERVE_ZERO_FRACTION);
        self::assertIsString($json);

        $this->expectException(SnapshotVerificationException::class);
        self::serializer()->deserialize($json);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty string' => [''];
        yield 'not json' => ['not json'];
        yield 'scalar' => ['1'];
        yield 'string' => ['"x"'];
        yield 'null' => ['null'];
        yield 'empty object' => ['{}'];
        yield 'list' => ['[1,2]'];
        yield 'no memo' => ['{"state":[],"checksum":"x"}'];
        yield 'array memo' => ['{"memo":[1,2,3],"state":[],"checksum":"x"}'];
        yield 'scalar memo' => ['{"memo":"x","checksum":"x"}'];
        yield 'no name' => ['{"memo":{"key":"k","version":0},"checksum":"x"}'];
        yield 'numeric name' => ['{"memo":{"name":1,"key":"k","version":0},"checksum":"x"}'];
        yield 'no version' => ['{"memo":{"name":"C","key":"k"},"checksum":"x"}'];
        yield 'string version' => ['{"memo":{"name":"C","key":"k","version":"1"},"checksum":"x"}'];
        yield 'float version' => ['{"memo":{"name":"C","key":"k","version":1.5},"checksum":"x"}'];
        yield 'null version' => ['{"memo":{"name":"C","key":"k","version":null},"checksum":"x"}'];
        yield 'scalar state' => ['{"memo":{"name":"C","key":"k","version":0},"state":1,"checksum":"x"}'];
        yield 'named state' => ['{"memo":{"name":"C","key":"k","version":0},"state":{"a":1},"checksum":"x"}'];
        yield 'scalar deps' => ['{"memo":{"name":"C","key":"k","version":0},"effectDeps":[1],"checksum":"x"}'];
        yield 'numeric checksum' => ['{"memo":{"name":"C","key":"k","version":0},"checksum":1}'];
        yield 'too deep' => [str_repeat('[', 600) . str_repeat(']', 600)];
    }

    #[DataProvider('malformed')]
    public function testSN5MalformedInputIsTheSameException(string $input): void
    {
        $this->expectException(SnapshotVerificationException::class);
        self::serializer()->deserialize($input);
    }

    public function testSN6ContextsDoNotVerifyEachOther(): void
    {
        $a = self::serializer('A')->serialize(self::sample());

        self::serializer('A')->deserialize($a);

        foreach (['B', ''] as $other) {
            try {
                self::serializer($other)->deserialize($a);
                self::fail("context \"{$other}\" must not verify");
            } catch (SnapshotVerificationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(SnapshotVerificationException::class);
        self::serializer('A')->deserialize(self::serializer()->serialize(self::sample()));
    }

    public function testSN6WithContextReturnsAnotherSerializer(): void
    {
        $base = self::serializer();
        $bound = $base->withContext('A');

        self::assertNotSame($base, $bound);
        self::assertTrue($bound->verifyString('p', self::serializer('A')->signString('p')));
        self::assertFalse($base->verifyString('p', $bound->signString('p')));
    }

    public function testSN6SignStringContextIsUnambiguous(): void
    {
        $signature = self::serializer('ab')->signString('c');

        self::assertTrue(self::serializer('ab')->verifyString('c', $signature));
        self::assertFalse(self::serializer('a')->verifyString('bc', $signature));
        self::assertFalse(self::serializer('abc')->verifyString('', $signature));
        self::assertFalse(self::serializer('')->verifyString('abc', $signature));
        self::assertFalse(self::serializer('')->verifyString('ctx:2:ab:c', $signature), 'the empty context cannot spell a bound payload');
    }

    public function testSignStringCannotForgeASnapshotChecksum(): void
    {
        $signed = json_decode(self::serializer()->serialize(self::sample()), true);
        self::assertIsArray($signed);
        $payload = (string) json_encode([
            'name' => 'C', 'key' => 'k', 'version' => 3, 'state' => self::sample()->state, 'effectDeps' => self::sample()->effectDeps,
        ], JSON_PRESERVE_ZERO_FRACTION);

        self::assertNotSame($signed['checksum'], self::serializer()->signString($payload));
    }

    public function testSN7NoUnverifiedReaderIsPublic(): void
    {
        $methods = array_map(static fn(\ReflectionMethod $m) => $m->name, new \ReflectionClass(SnapshotSerializer::class)->getMethods(\ReflectionMethod::IS_PUBLIC));

        self::assertSame(
            ['__construct', 'deserialize', 'serialize', 'signString', 'verifyString', 'withContext'],
            (static function (array $names) {
                sort($names);

                return $names;
            })($methods),
        );
    }

    public function testVerifyStringRejectsAWrongSignature(): void
    {
        self::assertFalse(self::serializer()->verifyString('p', ''));
        self::assertFalse(self::serializer()->verifyString('p', 'x'));
    }
}
