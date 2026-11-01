<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

#[Component(name: 'restore-child', storage: 'memory')]
final class RestoreChild extends BaseComponent
{
    public function render(): Element
    {
        [$value] = $this->useState('child');

        return H::span(children: $value);
    }
}

final class RestoreThrower extends BaseComponent
{
    public function render(): Element
    {
        throw new \DomainException('boom');
    }
}

#[Component(name: 'restore-class-parent', storage: 'memory')]
final class RestoreClassParent extends BaseComponent
{
    /** @var array<string, \Closure> */
    public static array $setters = [];

    public function render(): Element
    {
        [, self::$setters['before']] = $this->useState('b');
        $element = H::div(children: [new RestoreChild()]);
        [, self::$setters['after']] = $this->useState('a');

        return $element;
    }
}

/** RT-5.4、RT-8: 子の後で親に戻ること */
final class NestedRenderRestoreTest extends TestCase
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

    public function testRT54FunctionParentAfterAClassChild(): void
    {
        $setter = null;
        $wrapper = fc(static function () use (&$setter) {
            $element = H::div(children: [new RestoreChild()]);
            [, $setter] = useState('parent');

            return $element;
        }, 'p', StorageType::Memory)();

        self::assertSame($wrapper->props['data-rosanna'], $setter(1)->componentId);
        self::assertSame(0, $setter(1)->payload['index']);
        self::assertFalse(ComponentState::getInstance('restore-child#0')->hasState(1));
    }

    public function testRT54SnapshotParentKeepsItsStateAfterAClassChild(): void
    {
        $wrapper = RenderContext::run(self::scope(), static fn() => fc(static function () {
            $element = H::div(children: [new RestoreChild()]);
            [$value] = useState('parent');

            return H::p(children: [$element, $value]);
        }, 'p', StorageType::Snapshot)());

        $snapshot = new SnapshotSerializer(self::SECRET)->deserialize($wrapper->props['data-rosanna-snapshot']);
        self::assertSame([0 => 'parent'], $snapshot->state);
    }

    public function testRT54ClassParentIsNotAffectedByAClassChild(): void
    {
        self::html(static fn() => H::div(children: [new RestoreClassParent()]));

        self::assertSame('restore-class-parent#0', (RestoreClassParent::$setters['before'])(1)->componentId);
        self::assertSame('restore-class-parent#0', (RestoreClassParent::$setters['after'])(1)->componentId);
        self::assertSame(1, (RestoreClassParent::$setters['after'])(1)->payload['index']);
    }

    public function testRT54ThrowingChildKeepsLaterIds(): void
    {
        $sibling = new RestoreChild();
        $depth = null;
        fc(static function () use ($sibling, &$depth) {
            try {
                H::div(children: [new RestoreThrower()]);
            } catch (\DomainException) {
                $depth = RenderContext::stackDepth();
            }

            return H::div(children: [$sibling]);
        }, 'p', StorageType::Memory)();

        self::assertSame(1, $depth, 'only the parent is on the stack');
        self::assertSame('restore-child#0', $sibling->getComponentState()?->getComponentId());
        self::assertSame(0, RenderContext::stackDepth());
        self::assertNull(ComponentState::current());
    }

    public function testRT54ClassSiblingsKeepTheirIdOrder(): void
    {
        $first = new RestoreChild();
        $second = new RestoreChild();
        H::div(children: [$first, $second]);

        self::assertSame('restore-child#0', $first->getComponentState()?->getComponentId());
        self::assertSame('restore-child#1', $second->getComponentState()?->getComponentId());
    }
}
