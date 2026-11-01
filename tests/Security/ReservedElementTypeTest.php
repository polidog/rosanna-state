<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\State\component;

use Polidog\Rosanna\State\ComponentState;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

/** 文字列で関数名を受ける形を作らない。@see reservedTypeMarker */
function reservedTypeMarker(mixed $props = null): Element
{
    ReservedElementTypeTest::$invoked++;

    return H::span(children: 'id=' . ComponentState::current()?->getComponentId());
}

/** RT-6.7 `component()` は Closure に正規化する。RT-6.8 defer のプレースホルダー */
final class ReservedElementTypeTest extends TestCase
{
    use RendersInScope;

    private const MARKER = __NAMESPACE__ . '\reservedTypeMarker';

    public static int $invoked = 0;

    protected function setUp(): void
    {
        self::$invoked = 0;
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public static function callMarker(): Element
    {
        return reservedTypeMarker();
    }

    /** @return iterable<string, array{callable, string}> */
    public static function callables(): iterable
    {
        yield 'function name' => [self::MARKER, self::MARKER . '#0'];
        yield 'array callable' => [[self::class, 'callMarker'], self::class . '::callMarker#0'];
        yield 'invokable object' => [new class {
            public function __invoke(): Element
            {
                return ReservedElementTypeTest::callMarker();
            }
        }, 'AnonymousFC#0'];
    }

    /** RT-6.7 props には Closure だけが入る。RT-8.1 名前は元の callable から決まる */
    #[DataProvider('callables')]
    public function testRT67ComponentIsNormalizedToAClosureAndKeepsItsName(callable $component, string $id): void
    {
        $call = component($component);
        self::assertInstanceOf(\Closure::class, new \ReflectionProperty($call, 'component')->getValue($call));
        self::assertSame(0, self::$invoked, 'component() only builds the value');

        self::assertSame("<div><span>id={$id}</span></div>", self::html(static fn() => H::div(children: [component($component)])));
        self::assertSame(1, self::$invoked);
    }

    public function testRT68DeferStillBuildsItsPlaceholder(): void
    {
        $element = H::defer('panel', ['a' => 1], localCache: true);

        self::assertSame('__defer__', $element->type);
        self::assertSame('<div data-rosanna-defer-url="/_defer/panel?a=1" data-rosanna-defer-cache></div>', self::html(static fn() => $element));
    }
}
