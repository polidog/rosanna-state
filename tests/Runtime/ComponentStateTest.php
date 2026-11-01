<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

final class ComponentStateTest extends TestCase
{
    use SessionTestHelper;

    protected function setUp(): void
    {
        ComponentState::clearInstances();
    }

    protected function tearDown(): void
    {
        ComponentState::clearInstances();
    }

    public function testST5SameIdIsOneInstance(): void
    {
        $first = ComponentState::getInstance('C#0', StorageType::Memory);
        $second = ComponentState::getInstance('C#0', StorageType::Snapshot);

        self::assertSame($first, $second);
        self::assertSame(StorageType::Memory, $second->getStorageType());
    }

    public function testDefaultStorageIsSession(): void
    {
        self::assertSame(StorageType::Session, ComponentState::getInstance('C#0')->getStorageType());
    }

    public function testST5FromSnapshotReplacesTheContentsOfTheSameInstance(): void
    {
        $existing = ComponentState::getInstance('C#0', StorageType::Snapshot);
        $existing->setState(0, 'old');
        $existing->setState(7, 'old');
        $existing->nextHookIndex();

        $restored = ComponentState::fromSnapshot(new Snapshot('C', '0', ['new'], [], 4));

        self::assertSame($existing, $restored);
        self::assertSame($restored, ComponentState::getInstance('C#0'));
        self::assertSame($restored, ComponentState::current());
        self::assertSame('new', $restored->getState(0, 'x'));
        self::assertFalse($restored->hasState(7));
        self::assertSame(4, $restored->version());
        self::assertSame(0, $restored->nextHookIndex());
    }

    public function testFromSnapshotOverASessionTypedInstanceBecomesSnapshot(): void
    {
        ComponentState::getInstance('C#0', StorageType::Memory);

        $restored = ComponentState::fromSnapshot(new Snapshot('C', '0'));

        self::assertTrue($restored->isSnapshotStorage());
    }

    public function testHookPositionAndReset(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        self::assertSame(0, $state->nextHookIndex());
        self::assertSame(1, $state->nextHookIndex());

        ComponentState::reset();

        self::assertSame(0, $state->nextHookIndex());
    }

    public function testCurrentAndSetCurrent(): void
    {
        $a = ComponentState::getInstance('A#0', StorageType::Memory);
        $b = ComponentState::getInstance('B#0', StorageType::Memory);
        self::assertSame($b, ComponentState::current());

        ComponentState::setCurrent($a);
        self::assertSame($a, ComponentState::current());

        ComponentState::setCurrent(null);
        self::assertNull(ComponentState::current());

        // current がなくても reset() は例外にしない
        ComponentState::reset();
    }

    public function testRT96ClearInstancesLeavesNoStateBehind(): void
    {
        ComponentState::getInstance('M#0', StorageType::Memory)->setState(0, 'visitor A');
        ComponentState::getInstance('S#0', StorageType::Snapshot)->setState(0, 'visitor A');

        ComponentState::clearInstances();

        self::assertNull(ComponentState::current());
        self::assertSame('fresh', ComponentState::getInstance('M#0', StorageType::Memory)->getState(0, 'fresh'));
        self::assertSame('fresh', ComponentState::getInstance('S#0', StorageType::Snapshot)->getState(0, 'fresh'));
    }

    public function testST3MemoryReadDoesNotStoreTheInitialValue(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        self::assertSame('first', $state->getState(0, 'first'));
        self::assertFalse($state->hasState(0));
        self::assertSame('second', $state->getState(0, 'second'));
    }

    public function testST3SnapshotRecordsTheInitialValueForTheSnapshot(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Snapshot);

        self::assertSame('first', $state->getState(0, 'first'));
        self::assertSame(['first'], $state->createSnapshot()->state);
        self::assertSame('first', $state->getState(0, 'second'), 'recorded for this request');
    }

    public function testRT73NullIsKeptAsAValue(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        $state->setState(0, null);

        self::assertNull($state->getState(0, 'initial'));
    }

    public function testST6NonJsonValuesAreRefused(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        $this->expectException(\InvalidArgumentException::class);
        $state->setState(0, ['nested' => static fn() => 1]);
    }

    public function testST6NonJsonEffectDepsAreRefused(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        $this->expectException(\InvalidArgumentException::class);
        $state->setEffectDeps(0, [new \stdClass()]);
    }

    public function testSnapshotExportKeepsNameKeyVersionAndOrder(): void
    {
        $state = ComponentState::getInstance('Name#a#b', StorageType::Snapshot);
        $state->setState(1, 'b');
        $state->setState(0, 'a');
        $state->setEffectDeps(0, [1]);
        $state->incrementVersion();

        $snapshot = $state->createSnapshot();

        self::assertSame('Name', $snapshot->componentName);
        self::assertSame('a#b', $snapshot->key);
        self::assertSame('Name#a#b', $snapshot->getInstanceId());
        self::assertSame(['a', 'b'], $snapshot->state);
        self::assertSame([0 => [1]], $snapshot->effectDeps);
        self::assertSame(1, $snapshot->version);
    }

    public function testCreateSnapshotOnOtherStorageIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        ComponentState::getInstance('C#0', StorageType::Memory)->createSnapshot();
    }

    public function testCreateSnapshotWithoutAKeyIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        ComponentState::getInstance('no-key', StorageType::Snapshot)->createSnapshot();
    }

    public function testVR1VersionStartsAtZeroAndIncrements(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        self::assertSame(0, $state->version());
        self::assertSame(1, $state->incrementVersion());
        self::assertSame(1, $state->version());
    }

    public function testVR2SetStateAloneDoesNotChangeTheVersion(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        $state->setState(0, 1);
        $state->setState(1, 2);

        self::assertSame(0, $state->version());
    }

    #[RunInSeparateProcess]
    public function testVR3SessionVersionKeyAndClearDoesNotRewind(): void
    {
        self::isolateSessions();
        $state = ComponentState::getInstance('C#0', StorageType::Session);
        self::assertSame(0, $state->version());
        self::assertSame(PHP_SESSION_NONE, session_status(), 'reading the version does not start a session');

        $state->setState(0, 'v');
        $state->setEffectDeps(0, [1]);
        $state->incrementVersion();
        $state->incrementVersion();

        self::assertSame(2, $_SESSION['rosanna:C#0:version']);
        self::assertSame('v', $_SESSION['rosanna:C#0:state:0']);
        self::assertSame([1], $_SESSION['rosanna:C#0:effect_deps:0']);

        $state->clearState();

        self::assertFalse($state->hasState(0));
        self::assertNull($state->getEffectDeps(0));
        self::assertSame(2, $state->version());
    }

    #[RunInSeparateProcess]
    public function testST3AnonymousReadWritesNothingToTheSession(): void
    {
        self::isolateSessions();
        $state = ComponentState::getInstance('C#0', StorageType::Session);

        self::assertSame('initial', $state->getState(0, 'initial'));
        self::assertFalse($state->hasState(0));
        self::assertSame(PHP_SESSION_NONE, session_status());

        session_start();
        self::assertSame('initial', $state->getState(0, 'initial'));
        self::assertSame([], $_SESSION);
    }

    public function testRT78DepsTable(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);

        self::assertTrue($state->shouldRunEffect(0, null), 'null always runs');
        self::assertTrue($state->shouldRunEffect(0, []), 'first run');
        self::assertTrue($state->shouldRunEffect(0, [1]), 'first run');

        $state->setEffectDeps(0, []);
        self::assertFalse($state->shouldRunEffect(0, []));
        self::assertTrue($state->shouldRunEffect(0, null));

        $state->setEffectDeps(0, [1, null]);
        self::assertFalse($state->shouldRunEffect(0, [1, null]), 'null as a value compares equal');
        self::assertTrue($state->shouldRunEffect(0, [1, 0]), 'null !== 0');
        self::assertTrue($state->shouldRunEffect(0, ['1', null]), '!== is strict');
        self::assertTrue($state->shouldRunEffect(0, [1]), 'length differs');
        self::assertTrue($state->shouldRunEffect(0, []), 'length differs');
    }

    public function testRT78DepsFromNullToArrayRuns(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $runs = 0;
        $effect = static function () use (&$runs): void {
            $runs++;
        };

        $state->runEffect(0, $effect, null);
        self::assertSame(1, $runs);

        $state->runEffect(0, $effect, [1]);
        self::assertSame(2, $runs, 'the previous deps were not an array');

        $state->runEffect(0, $effect, [1]);
        self::assertSame(2, $runs, 'does not run twice');

        $state->runEffect(0, $effect, null);
        self::assertSame(3, $runs);

        $state->runEffect(0, $effect, [1]);
        self::assertSame(4, $runs, 'null replaced the earlier array');
    }

    public function testRT79OrderOfCleanupCallbackAndDeps(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $log = [];

        $state->runEffect(0, static function () use (&$log, $state) {
            $log[] = 'run 1, deps ' . json_encode($state->getEffectDeps(0));

            return static function () use (&$log): void {
                $log[] = 'cleanup 1';
            };
        }, [1]);

        $state->runEffect(0, static function () use (&$log, $state): void {
            $log[] = 'run 2, deps ' . json_encode($state->getEffectDeps(0));
        }, [2]);

        self::assertSame(['run 1, deps null', 'cleanup 1', 'run 2, deps [1]'], $log);
        self::assertSame([2], $state->getEffectDeps(0));

        // クリーンアップは 1 回だけ
        $state->runEffect(0, static fn() => null, [3]);
        self::assertSame(['run 1, deps null', 'cleanup 1', 'run 2, deps [1]'], $log);
    }

    public function testRT710OnlyAClosureIsACleanup(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $state->runEffect(0, static fn() => 'phpversion', [1]);
        $state->runEffect(1, static fn() => 'system', [1]);

        $state->runEffectCleanup(0);

        // 文字列が記録されていれば、ここまでに実行されるか Closure の型で落ちる
        $state->runEffect(1, static fn() => null, [2]);
        self::assertSame([2], $state->getEffectDeps(1));
    }

    public function testRT711CleanupIsNotInTheStorageAndDoesNotReachTheNextRequest(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Snapshot);
        $ran = false;
        $state->runEffect(0, static fn() => static function () use (&$ran): void {
            $ran = true;
        }, [1]);

        // Snapshot に入るのは deps だけ
        $snapshot = $state->createSnapshot();
        self::assertSame([0 => [1]], $snapshot->effectDeps);
        self::assertStringNotContainsString('Closure', $snapshot->toJson());

        // 次のリクエストに見立てる
        ComponentState::clearInstances();
        $next = ComponentState::fromSnapshot($snapshot);
        $next->runEffect(0, static fn() => null, [2]);

        self::assertFalse($ran);
    }

    public function testEffectDepsSurviveTheSnapshotAndDoNotRunAgain(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Snapshot);
        $state->runEffect(0, static fn() => null, [1.0, 'a', null]);
        $json = $state->createSnapshot()->toJson();
        ComponentState::clearInstances();

        $next = ComponentState::fromSnapshot(Snapshot::fromJson($json));

        self::assertFalse($next->shouldRunEffect(0, [1.0, 'a', null]));
    }
}
