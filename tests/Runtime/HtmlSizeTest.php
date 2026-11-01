<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/** RT-4.7 (#87): 行数を 2 倍にしても HTML のサイズがおよそ 2 倍に収まる */
final class HtmlSizeTest extends TestCase
{
    use RendersInScope;
    use SessionTestHelper;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    private static function todo(int $rows, StorageType $type): Element
    {
        return fc(static function () use ($rows) {
            [$todos, $set] = useState(array_map(static fn(int $i) => ['id' => $i, 'title' => "Task {$i}", 'done' => false], range(1, $rows)));

            return H::ul(children: array_map(static fn(array $todo) => H::li(children: [
                H::span(children: $todo['title']),
                H::button(onClick: static fn() => $set(array_map(
                    static fn(array $t) => $t['id'] === $todo['id'] ? [...$t, 'done' => !$t['done']] : $t,
                    $todos,
                )), children: 'toggle'),
                H::button(onClick: static fn() => $set(array_values(array_filter(
                    $todos,
                    static fn(array $t) => $t['id'] !== $todo['id'],
                ))), children: 'delete'),
            ]), $todos));
        }, 'todo', $type)();
    }

    private static function htmlBytes(int $rows, StorageType $type): int
    {
        RequestLifecycle::reset();

        return strlen(self::html(static fn() => self::todo($rows, $type)));
    }

    /** @return iterable<string, array{StorageType}> */
    public static function storages(): iterable
    {
        yield 'session' => [StorageType::Session];
        yield 'snapshot' => [StorageType::Snapshot];
    }

    #[DataProvider('storages')]
    #[RunInSeparateProcess]
    public function testRT47SizeGrowsLinearly(StorageType $type): void
    {
        self::isolateSessions();

        $small = self::htmlBytes(100, $type);
        $double = self::htmlBytes(200, $type);
        $quadruple = self::htmlBytes(400, $type);

        self::assertLessThan(2.2, $double / $small);
        self::assertGreaterThan(1.8, $double / $small);
        self::assertLessThan(2.2, $quadruple / $double);
        self::assertGreaterThan(1.8, $quadruple / $double);
    }

    #[DataProvider('storages')]
    public function testRT47OnlyOneFormForTheWholeList(StorageType $type): void
    {
        RequestLifecycle::reset();
        $document = self::dom(self::html(static fn() => self::todo(20, $type)));
        self::assertSame(1, $document->querySelectorAll('form')->length);
        self::assertSame(40, $document->querySelectorAll('button[form]')->length);
    }
}
