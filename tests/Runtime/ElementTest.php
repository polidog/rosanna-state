<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-1 */
final class ElementTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT11WithersReturnNewInstances(): void
    {
        $element = new Element('div', ['a' => 1], ['x']);

        $props = $element->withProps(['b' => 2]);
        $children = $element->withChildren(['y']);

        self::assertNotSame($element, $props);
        self::assertNotSame($element, $children);
        self::assertSame(['a' => 1], $element->props);
        self::assertSame(['x'], $element->children);
        self::assertSame(['b' => 2], $props->props);
        self::assertSame(['y'], $children->children);
        self::assertSame(['x'], $props->children);
        self::assertSame(['a' => 1], $children->props);
    }

    public function testRT12ReadonlyProperties(): void
    {
        $this->expectException(\Error::class);
        $element = new Element('div');
        $element->type = 'span'; // @phpstan-ignore property.readOnlyAssignOutOfClass
    }

    public function testRT12FragmentHasNoTag(): void
    {
        self::assertSame('a<b></b>', self::html(static fn() => new Element('Fragment', ['ignored' => 1], ['a', new Element('b')])));
    }

    public function testRT12DeferIsAPlaceholder(): void
    {
        self::assertSame('<div data-rosanna-defer-url="/_defer/x"></div>', self::html(static fn() => H::defer('x')));
    }

    public function testRT12AndRT36UnresolvedFunctionComponentIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unresolved');
        self::html(static fn() => H::component(static fn() => H::div()));
    }

    public function testRT67ChildComponentIsExpandedWithoutWrapper(): void
    {
        self::assertSame(
            '<div><span>v</span></div>',
            self::html(static fn() => H::div(children: [H::component(static fn(array $props) => H::span(children: $props['x']), ['x' => 'v', 'key' => 1])])),
        );
    }

    public function testRT67InvalidKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::component(static fn() => H::div(), ['key' => ['a']]);
    }
}
