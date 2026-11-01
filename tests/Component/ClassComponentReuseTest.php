<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

#[Component(name: 'reuse', storage: StorageType::Memory)]
final class ReusedComponent extends BaseComponent
{
    public bool $throws = false;

    public function render(): Element
    {
        [$value, $set] = $this->useState(0);
        if ($this->throws) {
            throw new \DomainException('boom');
        }

        return H::span(children: "v={$value} index=" . $set($value + 1)->payload['index']);
    }
}

/** RT-5.4 同じインスタンスを再利用しても、同じ状態を hook の位置 0 から読む */
final class ClassComponentReuseTest extends TestCase
{
    use RendersInScope;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT54ReusedInstanceReadsTheSameStateFromTheFirstHook(): void
    {
        $component = new ReusedComponent();
        $scope = self::scope();

        $first = self::html(static fn() => H::div(children: [$component]), $scope);
        self::assertSame('<div><div data-rosanna="reuse#0"><span>v=0 index=0</span></div></div>', $first);

        $state = $component->getComponentState();
        self::assertNotNull($state);
        $state->setState(0, 9);

        $second = self::html(static fn() => H::div(children: [$component]), $scope);
        self::assertSame('<div><div data-rosanna="reuse#0"><span>v=9 index=0</span></div></div>', $second);
    }

    public function testRT54ReuseDoesNotTakeANewInstanceNumber(): void
    {
        $component = new ReusedComponent();
        $scope = self::scope();
        self::html(static fn() => H::div(children: [$component]), $scope);
        self::html(static fn() => H::div(children: [$component]), $scope);

        self::assertSame('reuse#1', RenderContext::nextInstanceId('reuse'));
    }

    public function testRT54ReuseRestoresTheParentStateAndStack(): void
    {
        $component = new ReusedComponent();
        $parent = ComponentState::getInstance('parent#0', StorageType::Memory);
        self::html(static fn() => H::div(children: [$component]));
        ComponentState::setCurrent($parent);

        H::div(children: [$component]);
        self::assertSame($parent, ComponentState::current());
        self::assertSame(0, RenderContext::stackDepth());

        $component->throws = true;
        try {
            H::div(children: [$component]);
            self::fail('Expected the exception');
        } catch (\DomainException) {
            self::assertSame($parent, ComponentState::current());
            self::assertSame(0, RenderContext::stackDepth());
        }
    }

    public function testRT54ReuseIsOnTheStackWhileRendering(): void
    {
        $seen = null;
        $component = new class ($seen) extends BaseComponent {
            /** @param-out string|null $seen */
            public function __construct(private mixed &$seen) {}

            public function render(): Element
            {
                $this->seen = RenderContext::currentComponentId();

                return H::div();
            }
        };
        $component->setComponentState(ComponentState::getInstance('given#3', StorageType::Memory));
        ComponentState::setCurrent(null);

        self::html(static fn() => H::div(children: [$component]));

        self::assertSame('given#3', $seen);
    }
}
