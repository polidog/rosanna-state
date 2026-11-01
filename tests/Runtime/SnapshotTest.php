<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\Snapshot;

final class SnapshotTest extends TestCase
{
    public function testSN1Format(): void
    {
        $json = new Snapshot('C', 'k', ['a'], [0 => [1]], 2, 'sum')->toJson();

        self::assertSame(
            '{"memo":{"name":"C","key":"k","version":2},"state":["a"],"effectDeps":[[1]],"checksum":"sum"}',
            $json,
        );
    }

    public function testInstanceId(): void
    {
        self::assertSame('C#k', new Snapshot('C', 'k')->getInstanceId());
    }

    public function testRoundTripKeepsFloatZeroFractionAndGaps(): void
    {
        $snapshot = new Snapshot('C', 'k', [1 => 1.0, 3 => ['a' => []]], [2 => null], 5, 'sum');

        $restored = Snapshot::fromJson($snapshot->toJson());

        self::assertSame($snapshot->state, $restored->state);
        self::assertSame($snapshot->effectDeps, $restored->effectDeps);
        self::assertSame(5, $restored->version);
        self::assertSame('sum', $restored->checksum);
    }

    public function testToJsonRefusesValuesThatDoNotRoundTrip(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('state.0');
        new Snapshot('C', 'k', [new \stdClass()])->toJson();
    }
}
