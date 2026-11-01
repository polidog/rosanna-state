<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\ComponentState;

use function Polidog\Rosanna\State\fc;

use Polidog\Rosanna\State\RenderPass;
use Polidog\Rosanna\State\Storage\StorageType;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

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
        self::assertSame('A#0', RenderPass::beginComponent('A'));
        self::assertSame('B#0', RenderPass::beginComponent('B'));
        self::assertSame('A#1', RenderPass::beginComponent('A'));
        RenderPass::endComponent();
        RenderPass::endComponent();
        RenderPass::endComponent();

        RenderPass::beginRender();
        self::assertSame('A#0', RenderPass::nextInstanceId('A'));
    }

    public function testRT82ExplicitKeyDoesNotUseTheCounter(): void
    {
        self::assertSame('A#k', RenderPass::nextInstanceId('A', 'k'));
        self::assertSame('A#0', RenderPass::nextInstanceId('A'));
    }

    public function testRT85BeginRenderClearsCountersAndStack(): void
    {
        RenderPass::beginComponent('A');
        RenderPass::beginRender();
        self::assertSame(0, RenderPass::stackDepth());
        self::assertSame('A#0', RenderPass::nextInstanceId('A'));
    }

    public function testRT85ResumePassKeepsTheHigherCounterSoNestedIdsAreNotReused(): void
    {
        RenderPass::nextInstanceId('A');
        RenderPass::beginComponent('Outer');
        $pass = RenderPass::currentPass();

        RenderPass::beginRender();
        self::assertSame('A#0', RenderPass::nextInstanceId('A'));
        self::assertSame('A#1', RenderPass::nextInstanceId('A'));
        self::assertSame('B#0', RenderPass::nextInstanceId('B'));

        RenderPass::resumePass($pass);

        self::assertSame(1, RenderPass::stackDepth());
        self::assertSame('Outer#0', RenderPass::currentComponentId());
        self::assertSame('A#2', RenderPass::nextInstanceId('A'));
        self::assertSame('B#1', RenderPass::nextInstanceId('B'));
    }

    public function testRT85SiblingsAroundANestedPassGetDistinctIds(): void
    {
        $sibling = static fn() => H::div();
        $pass = null;
        $ids = [];

        $outer = fc(static function () use ($sibling, &$pass, &$ids) {
            $ids[] = fc($sibling, storageType: StorageType::Memory)()->props['data-rosanna'];

            $pass = RenderPass::currentPass();
            RenderPass::beginRender();
            $ids[] = fc($sibling, storageType: StorageType::Memory)()->props['data-rosanna'];
            RenderPass::resumePass($pass);

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
        RenderPass::beginComponent('Outer');
        $pass = RenderPass::currentPass();

        try {
            RenderPass::beginRender();
            RenderPass::beginComponent('Inner');
            RenderPass::beginComponent('Inner');
            throw new \DomainException('boom');
        } catch (\DomainException) {
            RenderPass::resumePass($pass);
        }

        self::assertSame(1, RenderPass::stackDepth());
        self::assertSame('Outer#0', RenderPass::currentComponentId());
        self::assertSame('Inner#2', RenderPass::nextInstanceId('Inner'));
    }

    public function testRT95FactoryProducesAConfiguredRenderer(): void
    {
        $scope = self::scope(deferPrefix: '/custom/');
        $html = RenderContext::run($scope, static fn() => $scope->state->renderers->create('x')->renderElement(H::defer('late')));
        self::assertSame('<div data-rosanna-defer-url="/custom/late"></div>', $html);
    }

    public function testRT95RootIdIsTheTargetWhenTheActionHasNoComponent(): void
    {
        $scope = self::scope();
        $html = $scope->state->renderers->create('Root#0')->renderElement(H::button(onClick: \Polidog\Rosanna\State\Action::setState(0, 1), children: 'x'));
        self::assertStringContainsString('name="_rosanna_component" value="Root#0"', $html);
    }

    public function testRT96ResetClearsStateScopeAndPass(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $state->setState(0, 'secret');
        RenderPass::beginComponent('A');

        RenderContext::run(self::scope(), static function (): void {
            RequestLifecycle::reset();
            self::assertNull(RenderContext::currentScope());
        });

        self::assertNull(ComponentState::current());
        self::assertSame(0, RenderPass::stackDepth());
        self::assertSame('A#0', RenderPass::nextInstanceId('A'));
        self::assertFalse(ComponentState::getInstance('C#0', StorageType::Memory)->hasState(0));
    }

    public function testRendererRenderWrapsAndRestoresTheParentState(): void
    {
        $parent = ComponentState::getInstance('P#0', StorageType::Memory);
        $scope = self::scope();
        $html = $scope->state->renderers->create('C#0', StorageType::Memory)->render(static fn() => new Element('p'));
        self::assertSame('<div data-rosanna="C#0"><p></p></div>', $html);
        self::assertSame($parent, ComponentState::current());
    }

    public function testRendererRenderRestoresTheParentStateOnException(): void
    {
        $parent = ComponentState::getInstance('P#0', StorageType::Memory);
        $scope = self::scope();
        try {
            $scope->state->renderers->create('C#0', StorageType::Memory)->render(static function (): never {
                throw new \DomainException('boom');
            });
            self::fail();
        } catch (\DomainException) {
        }
        self::assertSame($parent, ComponentState::current());
    }
}
