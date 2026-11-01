<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-8、RT-9 */
final class RenderContextTest extends TestCase
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

    public function testRT83NumbersArePerPassAndPerNameWithoutTheParent(): void
    {
        self::assertSame('A#0', RenderContext::beginComponent('A'));
        self::assertSame('B#0', RenderContext::beginComponent('B'));
        self::assertSame('A#1', RenderContext::beginComponent('A'));
        RenderContext::endComponent();
        RenderContext::endComponent();
        RenderContext::endComponent();

        RenderContext::beginRender();
        self::assertSame('A#0', RenderContext::nextInstanceId('A'));
    }

    public function testRT82ExplicitKeyDoesNotUseTheCounter(): void
    {
        self::assertSame('A#k', RenderContext::nextInstanceId('A', 'k'));
        self::assertSame('A#0', RenderContext::nextInstanceId('A'));
    }

    public function testRT85BeginRenderClearsCountersAndStack(): void
    {
        RenderContext::beginComponent('A');
        RenderContext::beginRender();
        self::assertSame(0, RenderContext::stackDepth());
        self::assertSame('A#0', RenderContext::nextInstanceId('A'));
    }

    public function testRT85ResumePassKeepsTheHigherCounterSoNestedIdsAreNotReused(): void
    {
        RenderContext::nextInstanceId('A');
        RenderContext::beginComponent('Outer');
        $pass = RenderContext::currentPass();

        RenderContext::beginRender();
        self::assertSame('A#0', RenderContext::nextInstanceId('A'));
        self::assertSame('A#1', RenderContext::nextInstanceId('A'));
        self::assertSame('B#0', RenderContext::nextInstanceId('B'));

        RenderContext::resumePass($pass);

        self::assertSame(1, RenderContext::stackDepth());
        self::assertSame('Outer#0', RenderContext::currentComponentId());
        self::assertSame('A#2', RenderContext::nextInstanceId('A'));
        self::assertSame('B#1', RenderContext::nextInstanceId('B'));
    }

    public function testRT85SiblingsAroundANestedPassGetDistinctIds(): void
    {
        $sibling = static fn() => H::div();
        $pass = null;
        $ids = [];

        $outer = fc(static function () use ($sibling, &$pass, &$ids) {
            $ids[] = fc($sibling, storageType: StorageType::Memory)()->props['data-rosanna'];

            $pass = RenderContext::currentPass();
            RenderContext::beginRender();
            $ids[] = fc($sibling, storageType: StorageType::Memory)()->props['data-rosanna'];
            RenderContext::resumePass($pass);

            $ids[] = fc($sibling, storageType: StorageType::Memory)()->props['data-rosanna'];

            return H::div();
        }, 'outer', StorageType::Memory);

        $outer();
        self::assertNotSame($ids[0], $ids[2], implode(',', $ids));
        self::assertNotSame($ids[1], $ids[2], implode(',', $ids));
        self::assertStringEndsWith('#0', $ids[0]);
        self::assertStringEndsWith('#0', $ids[1], 'the nested pass starts over');
        self::assertStringEndsWith('#1', $ids[2], 'the nested pass used 0 again, so the later sibling takes the next number');
    }

    public function testRT85ExceptionInANestedPassRestoresTheStackAndKeepsTheCounters(): void
    {
        RenderContext::beginComponent('Outer');
        $pass = RenderContext::currentPass();

        try {
            RenderContext::beginRender();
            RenderContext::beginComponent('Inner');
            RenderContext::beginComponent('Inner');
            throw new \DomainException('boom');
        } catch (\DomainException) {
            RenderContext::resumePass($pass);
        }

        self::assertSame(1, RenderContext::stackDepth());
        self::assertSame('Outer#0', RenderContext::currentComponentId());
        self::assertSame('Inner#2', RenderContext::nextInstanceId('Inner'));
    }

    public function testRT91ScopeOutsideRunIsALogicException(): void
    {
        self::assertNull(RenderContext::currentScope());
        $this->expectException(\LogicException::class);
        RenderContext::scope();
    }

    public function testRT92RunReturnsAndRestoresWhenNested(): void
    {
        $outer = self::scope();
        $inner = self::scope();

        $result = RenderContext::run($outer, function () use ($outer, $inner): string {
            self::assertSame($outer, RenderContext::scope());
            $value = RenderContext::run($inner, function () use ($inner): string {
                self::assertSame($inner, RenderContext::scope());

                return 'value';
            });
            self::assertSame($outer, RenderContext::scope());

            return $value;
        });

        self::assertSame('value', $result);
        self::assertNull(RenderContext::currentScope());
    }

    public function testRT92ExceptionRestoresTheOuterScope(): void
    {
        $outer = self::scope();
        RenderContext::run($outer, function () use ($outer): void {
            try {
                RenderContext::run(self::scope(), static function (): never {
                    throw new \DomainException('boom');
                });
            } catch (\DomainException) {
            }
            self::assertSame($outer, RenderContext::currentScope());
        });
        self::assertNull(RenderContext::currentScope());
    }

    public function testRT95FactoryProducesAConfiguredRenderer(): void
    {
        $scope = self::scope(deferPrefix: '/custom/');
        $html = RenderContext::run($scope, static fn() => $scope->renderers->create('x')->renderElement(H::defer('late')));
        self::assertSame('<div data-rosanna-defer-url="/custom/late"></div>', $html);
    }

    public function testRT95RootIdIsTheTargetWhenTheActionHasNoComponent(): void
    {
        $scope = self::scope();
        $html = $scope->renderers->create('Root#0')->renderElement(H::button(onClick: \Polidog\Rosanna\Runtime\Action::setState(0, 1), children: 'x'));
        self::assertStringContainsString('name="_rosanna_component" value="Root#0"', $html);
    }

    public function testRT96ResetClearsStateScopeAndPass(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $state->setState(0, 'secret');
        RenderContext::beginComponent('A');

        RenderContext::run(self::scope(), static function (): void {
            RequestLifecycle::reset();
            self::assertNull(RenderContext::currentScope());
        });

        self::assertNull(ComponentState::current());
        self::assertSame(0, RenderContext::stackDepth());
        self::assertSame('A#0', RenderContext::nextInstanceId('A'));
        self::assertFalse(ComponentState::getInstance('C#0', StorageType::Memory)->hasState(0));
    }

    public function testRendererRenderWrapsAndRestoresTheParentState(): void
    {
        $parent = ComponentState::getInstance('P#0', StorageType::Memory);
        $scope = self::scope();
        $html = $scope->renderers->create('C#0', StorageType::Memory)->render(static fn() => new Element('p'));
        self::assertSame('<div data-rosanna="C#0"><p></p></div>', $html);
        self::assertSame($parent, ComponentState::current());
    }

    public function testRendererRenderRestoresTheParentStateOnException(): void
    {
        $parent = ComponentState::getInstance('P#0', StorageType::Memory);
        $scope = self::scope();
        try {
            $scope->renderers->create('C#0', StorageType::Memory)->render(static function (): never {
                throw new \DomainException('boom');
            });
            self::fail();
        } catch (\DomainException) {
        }
        self::assertSame($parent, ComponentState::current());
    }
}
