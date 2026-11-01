<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Protocol\SessionSnapshots;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/** SN-24、SN-25 スロットの読み書き */
final class SessionSnapshotsTest extends TestCase
{
    use SessionTestHelper;

    private static function started(): SnapshotSerializer
    {
        self::isolateSessions();
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'rosanna-log-'));
        session_start();

        return new SnapshotSerializer('slot-secret');
    }

    #[RunInSeparateProcess]
    public function testSN25TheOldestWriteIsEvictedAndLogged(): void
    {
        $serializer = self::started();
        for ($i = 0; $i < 65; $i++) {
            SessionSnapshots::store($serializer, new Snapshot('Row', (string) $i, [$i]));
        }

        $loaded = SessionSnapshots::load($serializer);

        self::assertCount(64, $loaded);
        self::assertArrayNotHasKey('Row#0', $loaded);
        self::assertSame([64], $loaded['Row#64']->state);
        self::assertStringContainsString('Row#0 goes back to its initial state', (string) file_get_contents((string) ini_get('error_log')));
    }

    #[RunInSeparateProcess]
    public function testSN25RewritingAnInstanceMovesItToTheNewestEnd(): void
    {
        $serializer = self::started();
        for ($i = 0; $i < 64; $i++) {
            SessionSnapshots::store($serializer, new Snapshot('Row', (string) $i, [$i]));
        }
        SessionSnapshots::store($serializer, new Snapshot('Row', '0', ['again']));
        SessionSnapshots::store($serializer, new Snapshot('Row', 'new', ['x']));

        $loaded = SessionSnapshots::load($serializer);

        self::assertSame(['again'], $loaded['Row#0']->state);
        self::assertArrayNotHasKey('Row#1', $loaded);
    }

    #[RunInSeparateProcess]
    public function testSN24EntriesAreBoundToTheSessionId(): void
    {
        $serializer = self::started();
        SessionSnapshots::store($serializer, new Snapshot('Row', '0', [1]));
        self::assertCount(1, SessionSnapshots::load($serializer));

        session_regenerate_id();

        self::assertSame([], SessionSnapshots::load($serializer));
        self::assertSame([], $_SESSION['_rosanna_snapshot']);
        self::assertStringContainsString('Snapshot rejected (behavior=Session)', (string) file_get_contents((string) ini_get('error_log')));
    }

    #[RunInSeparateProcess]
    public function testSN24AnEntryFiledUnderAnotherIdIsDropped(): void
    {
        $serializer = self::started();
        SessionSnapshots::store($serializer, new Snapshot('Row', '0', [1]));
        $_SESSION['_rosanna_snapshot'] = ['Row#9' => $_SESSION['_rosanna_snapshot']['Row#0']];

        self::assertSame([], SessionSnapshots::load($serializer));
    }

    /** @return void */
    #[RunInSeparateProcess]
    public function testSN24BrokenSlotShapesAreDropped(): void
    {
        $serializer = self::started();

        foreach (['not an array', 1, ['Row#0' => 1], ['Row#0' => '{}'], ['Row#0' => 'x']] as $value) {
            $_SESSION['_rosanna_snapshot'] = $value;
            self::assertSame([], SessionSnapshots::load($serializer));
            self::assertSame([], $_SESSION['_rosanna_snapshot']);
        }
    }
}
