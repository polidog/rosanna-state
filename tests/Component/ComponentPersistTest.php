<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Component\ComponentRegistry;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

#[Component(name: 'persist-child', storage: 'snapshot', persist: 'localStorage')]
final class PersistChild extends BaseComponent
{
    public function render(): Element
    {
        [$value] = $this->useState('v');

        return H::span(children: $value);
    }
}

#[Component(name: 'session-child')]
final class SessionChild extends BaseComponent
{
    /** @var \Closure(mixed): Action */
    public static \Closure $setter;

    public function render(): Element
    {
        [$value, self::$setter] = $this->useState('child');

        return H::span(children: $value);
    }
}

#[Component(name: 'memory-child', storage: StorageType::Memory)]
final class MemoryChild extends BaseComponent
{
    public function render(): Element
    {
        [$value] = $this->useState('m');

        return H::span(children: $value);
    }
}

final class ThrowingChild extends BaseComponent
{
    public function render(): Element
    {
        throw new \DomainException('boom');
    }
}

/** RT-5.3、RT-5.4 */
final class ComponentPersistTest extends TestCase
{
    use RendersInScope;
    use SessionTestHelper;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT53SetterHasNoStorageType(): void
    {
        $child = new MemoryChild();
        $state = ComponentState::getInstance('memory-child#0', StorageType::Memory);
        $child->setComponentState($state);
        $component = new class extends BaseComponent {
            public ?\Closure $setter = null;

            public function render(): Element
            {
                [, $this->setter] = $this->useState(1);

                return H::div();
            }
        };
        $component->setComponentState($state);
        $component->render();

        $action = ($component->setter)(2);
        self::assertNull($action->storageType);
        self::assertSame('memory-child#0', $action->componentId);
    }

    public function testRT53WithoutStateTheInitialValueIsReturned(): void
    {
        $component = new class extends BaseComponent {
            public mixed $value = null;
            public ?\Closure $setter = null;

            public function render(): Element
            {
                [$this->value, $this->setter] = $this->useState('init');

                return H::div();
            }
        };
        $component->render();

        self::assertSame('init', $component->value);
        self::assertSame(['index' => 0, 'value' => 1], ($component->setter)(1)->payload);
    }

    public function testRT54ChildGetsTheWrapperAndStateOfItsAttribute(): void
    {
        $child = new MemoryChild();
        $html = self::html(static fn() => H::div(children: [$child]));

        self::assertSame('<div><div data-rosanna="memory-child#0"><span>m</span></div></div>', $html);
        self::assertSame(StorageType::Memory, $child->getComponentState()?->getStorageType());
    }

    public function testRT54SnapshotChildMatchesFc(): void
    {
        $html = self::html(static fn() => H::div(children: [new PersistChild()]));
        $wrapper = self::dom($html)->querySelector('[data-rosanna]');

        self::assertSame('persist-child#0', $wrapper?->getAttribute('data-rosanna'));
        self::assertSame('localStorage', $wrapper?->getAttribute('data-rosanna-persist'));
        self::assertNotSame('', (string) $wrapper?->getAttribute('data-rosanna-restore-sig'));
        self::assertSame([0 => 'v'], new SnapshotSerializer(self::SECRET)->deserialize((string) $wrapper?->getAttribute('data-rosanna-snapshot'))->state);
    }

    public function testRT54SnapshotChildWithoutASecretIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        self::html(static fn() => H::div(children: [new PersistChild()]), self::scope(null));
    }

    public function testRT54ChildWithStateRendersWithItsWrapperAndKeepsItsId(): void
    {
        $child = new MemoryChild();
        $child->setComponentState(ComponentState::getInstance('given#0', StorageType::Memory));
        ComponentState::setCurrent(null);
        self::assertSame('<div><div data-rosanna="given#0"><span>m</span></div></div>', self::html(static fn() => H::div(children: [$child])));
    }

    public function testRT54SiblingsAreNumberedInOrder(): void
    {
        $html = self::html(static fn() => H::div(children: [new MemoryChild(), new MemoryChild()]));
        $wrappers = self::dom($html)->querySelectorAll('[data-rosanna]');
        self::assertSame('memory-child#0', $wrappers->item(0)?->getAttribute('data-rosanna'));
        self::assertSame('memory-child#1', $wrappers->item(1)?->getAttribute('data-rosanna'));
    }

    public function testRT54ParentHooksReturnToTheParentAfterAChild(): void
    {
        $setters = [];
        $wrapper = fc(static function () use (&$setters) {
            [, $setters['before']] = useState('b');
            $element = H::div(children: [new MemoryChild()]);
            [, $setters['after']] = useState('a');

            return $element;
        }, 'parent', StorageType::Memory)();

        $id = $wrapper->props['data-rosanna'];
        self::assertSame($id, $setters['before'](1)->componentId);
        self::assertSame($id, $setters['after'](1)->componentId);
        self::assertSame(1, $setters['after'](1)->payload['index']);
    }

    public function testRT54ThrowingChildRestoresTheParentAndTheStack(): void
    {
        $afterCatch = null;
        fc(static function () use (&$afterCatch) {
            $state = ComponentState::current();
            try {
                H::div(children: [new ThrowingChild()]);
            } catch (\DomainException) {
                $afterCatch = [$state === ComponentState::current(), RenderContext::stackDepth()];
            }

            return H::div();
        }, 'parent', StorageType::Memory)();

        self::assertSame([true, 1], $afterCatch);
        self::assertSame(0, RenderContext::stackDepth());
        self::assertNull(ComponentState::current());
    }

    #[RunInSeparateProcess]
    public function testRT54SessionChildDoesNotWriteToTheSessionOnRead(): void
    {
        self::isolateSessions();
        self::html(static fn() => H::div(children: [new SessionChild()]));
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame('session-child#0', (SessionChild::$setter)(1)->componentId);
    }

    public function testRegistryExposesPersist(): void
    {
        $registry = new ComponentRegistry()->register(PersistChild::class);
        self::assertSame(SnapshotPersist::LocalStorage, $registry->getPersist('persist-child'));
        self::assertSame(StorageType::Snapshot, $registry->getStorageType('persist-child'));
    }
}
