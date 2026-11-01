<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Storage\MemoryStorage;
use Polidog\Rosanna\Storage\SessionStorage;
use Polidog\Rosanna\Storage\SnapshotStorage;

final class StorageTest extends TestCase
{
    use SessionTestHelper;

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'memory' => ['memory'];
        yield 'snapshot' => ['snapshot'];
        yield 'session' => ['session'];
    }

    #[DataProvider('storages')]
    #[RunInSeparateProcess]
    public function testST1Contract(string $type): void
    {
        self::isolateSessions();
        $storage = match ($type) {
            'memory' => new MemoryStorage(),
            'snapshot' => new SnapshotStorage('C#0'),
            default => new SessionStorage(),
        };

        self::assertFalse($storage->has('k'));
        self::assertSame('d', $storage->get('k', 'd'));

        $storage->set('k', null);
        self::assertTrue($storage->has('k'), 'null is a value');
        self::assertNull($storage->get('k', 'd'));

        $storage->set('a:1', 1);
        $storage->set('a:2', 2);
        $storage->set('b:1', 3);
        $storage->clearByPrefix('a:');
        self::assertFalse($storage->has('a:1'));
        self::assertFalse($storage->has('a:2'));
        self::assertTrue($storage->has('b:1'));

        $storage->delete('b:1');
        self::assertFalse($storage->has('b:1'));
    }

    public function testST7ExportIsOrderedByIndexAndSeparatedByInstance(): void
    {
        $storage = new SnapshotStorage('C#0');
        $storage->set('rosanna:C#0:state:2', 'c');
        $storage->set('rosanna:C#0:state:0', 'a');
        $storage->set('rosanna:C#0:state:1', 'b');
        $storage->set('rosanna:C#0:effect_deps:1', [1]);
        $storage->set('rosanna:C#0:effect_deps:0', null);
        $storage->set('rosanna:C#1:state:9', 'other instance');

        $exported = $storage->exportState();

        self::assertSame(['a', 'b', 'c'], $exported['state']);
        self::assertSame([0 => null, 1 => [1]], $exported['effectDeps']);
    }

    public function testInitializeReplacesTheContents(): void
    {
        $storage = new SnapshotStorage('C#0');
        $storage->set('rosanna:C#0:state:5', 'old');

        $storage->initializeFromSnapshot(new Snapshot('C', '0', ['new'], [0 => [1]], 3));

        self::assertSame(['new'], $storage->exportState()['state']);
        self::assertSame(3, $storage->get('rosanna:C#0:version'));
    }

    #[RunInSeparateProcess]
    public function testST2StaleSessionArrayIsNotReadWhenNoSessionIsActive(): void
    {
        self::isolateSessions();
        // ワーカーの前のリクエストの残りに見立てる
        $_SESSION = ['k' => 'previous visitor'];

        $storage = new SessionStorage();

        self::assertFalse($storage->has('k'));
        self::assertNull($storage->get('k'));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testST2RemovalWithoutSessionIsANoOpAndDoesNotStartOne(): void
    {
        self::isolateSessions();
        $storage = new SessionStorage();

        $storage->delete('k');
        $storage->clearByPrefix('k');

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testST2ClosedSessionIsResumedFromTheCookie(): void
    {
        self::isolateSessions();
        self::seedSession('abc123', ['k' => 'stored']);
        self::visitorWithCookie('abc123');

        $storage = new SessionStorage();

        self::assertSame('stored', $storage->get('k'));
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[RunInSeparateProcess]
    public function testU5WriteThatCannotStartASessionThrows(): void
    {
        self::isolateSessions();
        $_COOKIE[(string) session_name()] = 'bad!id';
        session_id('bad!id');

        $this->expectException(\LogicException::class);
        new SessionStorage()->set('k', 1);
    }
}
