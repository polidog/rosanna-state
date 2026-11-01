<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\State\component;

use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

/** RT-1 */
final class ElementTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT12DeferIsAPlaceholder(): void
    {
        self::assertSame('<div data-rosanna-defer-url="/_defer/x"></div>', self::html(static fn() => H::defer('x')));
    }

    public function testRT12AndRT36UnresolvedFunctionComponentIsRefused(): void
    {
        // `component()` は Element でないので、children に置かずに渡すと型で拒否する。中身は動かない
        $ran = false;
        $call = component(static function () use (&$ran) {
            $ran = true;

            return H::div();
        });

        try {
            self::scope()->state->renderers->create('_root_')->renderElement($call);
            self::fail('Expected TypeError');
        } catch (\TypeError) {
            self::assertFalse($ran);
        }
    }

    public function testRT67ChildComponentIsExpandedWithoutWrapper(): void
    {
        self::assertSame(
            '<div><span>v</span></div>',
            self::html(static fn() => H::div(children: [component(static fn(array $props) => H::span(children: $props['x']), ['x' => 'v', 'key' => 1])])),
        );
    }

    public function testRT67InvalidKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        component(static fn() => H::div(), ['key' => ['a']]);
    }
}
