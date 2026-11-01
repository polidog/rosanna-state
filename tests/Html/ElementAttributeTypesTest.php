<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Html;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-2.4 (#118) と数値の属性 */
final class ElementAttributeTypesTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testNumericAttributesAcceptStringsAndNumbers(): void
    {
        self::assertSame('<input min="1" max="10" step="0.5" />', self::html(static fn() => H::input(min: 1, max: '10', step: 0.5)));
        self::assertSame('<td colspan="2" rowspan="3"></td>', self::html(static fn() => H::td(colspan: 2, rowspan: '3')));
        self::assertSame('<progress value="1.5" max="10"></progress>', self::html(static fn() => H::progress(value: '1.5', max: 10)));
    }

    public function testRT24ScalarChildren(): void
    {
        self::assertSame('<p>1</p>', self::html(static fn() => H::p(children: 1)));
        self::assertSame('<p>1.5</p>', self::html(static fn() => H::p(children: 1.5)));
    }

    public function testRT24BoolChildrenRenderNothing(): void
    {
        self::assertSame('<p></p>', self::html(static fn() => H::p(children: true)));
        self::assertSame('<p></p>', self::html(static fn() => H::p(children: false)));
    }

    public function testRT24StringableChildIsEscaped(): void
    {
        $child = new class implements \Stringable {
            public function __toString(): string
            {
                return '<b>';
            }
        };
        self::assertSame('<p>&lt;b&gt;</p>', self::html(static fn() => H::p(children: $child)));
    }

    public function testRT24RenderableChild(): void
    {
        $child = new class implements Renderable {
            public function render(): Element
            {
                return H::span(children: 'r');
            }
        };
        self::assertSame('<p><span>r</span></p>', self::html(static fn() => H::p(children: $child)));
    }
}
