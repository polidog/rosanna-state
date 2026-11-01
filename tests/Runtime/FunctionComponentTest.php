<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\Defer;

use function Polidog\Rosanna\Html\getFunctionComponentName;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** RT-6、RT-8.1、RT-8.2、SN-10、SN-11、VR-7 */
final class FunctionComponentTest extends TestCase
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

    private static function idOf(Element $wrapper): string
    {
        $id = $wrapper->props['data-rosanna'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testRT61PersistRequiresSnapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        fc(static fn() => H::div(), persist: SnapshotPersist::SessionStorage);
    }

    public function testRT63PropsAreGivenToInnerWithoutTheKey(): void
    {
        $seen = null;
        $component = fc(static function (array $props) use (&$seen) {
            $seen = $props;

            return H::div();
        });

        $component(['a' => 1, 'key' => 'k']);
        self::assertSame(['a' => 1], $seen);
    }

    public function testRT64ResultIsWrapped(): void
    {
        $wrapper = fc(static fn() => H::span(), 'k')();
        self::assertSame('div', $wrapper->type);
        self::assertSame(['data-rosanna'], array_keys($wrapper->props));
        self::assertStringEndsWith('#k', self::idOf($wrapper));
        self::assertSame('span', $wrapper->children[0]->type);
    }

    public function testRT64ClosureOutsideAnyScopeStillRendersForSessionAndMemory(): void
    {
        self::assertArrayHasKey('data-rosanna', fc(static fn() => H::div(), storageType: StorageType::Memory)()->props);
    }

    public function testRT81ClosureName(): void
    {
        $closure = static fn() => H::div();
        $line = __LINE__ - 1;
        self::assertSame('FC@FunctionComponentTest.php:' . $line, getFunctionComponentName($closure));
    }

    public function testRT81OtherCallableNames(): void
    {
        self::assertSame('strlen', getFunctionComponentName('strlen'));
        self::assertSame(self::class . '::namedCallable', getFunctionComponentName([self::class, 'namedCallable']));
        self::assertSame(self::class . '::namedCallable', getFunctionComponentName([new self('x'), 'namedCallable']));
        self::assertSame('AnonymousFC', getFunctionComponentName(new class {
            public function __invoke(): void {}
        }));
    }

    public static function namedCallable(): void {}

    public function testRT82KeyCombinations(): void
    {
        $closure = static fn() => H::div();
        $name = getFunctionComponentName($closure);

        self::assertSame("{$name}#0", self::idOf(fc($closure)()));
        self::assertSame("{$name}#1", self::idOf(fc($closure)()));
        self::assertSame("{$name}#a", self::idOf(fc($closure, 'a')()));
        self::assertSame("{$name}#b", self::idOf(fc($closure)(['key' => 'b'])));
        self::assertSame("{$name}#7", self::idOf(fc($closure)(['key' => 7])));
        self::assertSame("{$name}#a/b", self::idOf(fc($closure, 'a')(['key' => 'b'])));
        self::assertSame("{$name}#a/7", self::idOf(fc($closure, 'a')(['key' => 7])));
        self::assertSame("{$name}#2", self::idOf(fc($closure)()), 'keyed instances do not use the counter');
    }

    public function testRT82InvalidPropsKey(): void
    {
        foreach ([['a'], 1.5, true, new \stdClass()] as $key) {
            try {
                fc(static fn() => H::div())(['key' => $key]);
                self::fail('Accepted key of type ' . get_debug_type($key));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('key', $e->getMessage());
            }
        }
        self::assertSame(0, RenderContext::stackDepth());
    }

    public function testRT68SameIdSharesState(): void
    {
        $seen = [];
        $component = fc(static function () use (&$seen) {
            [$value] = useState(1);
            $seen[] = ComponentState::current();

            return H::div(children: $value);
        }, 'same', StorageType::Memory);

        $component();
        $component();
        self::assertSame($seen[0], $seen[1]);
    }

    public function testRT63ParentHooksStayOnTheParentAfterAChild(): void
    {
        $setters = [];
        $child = fc(static function () use (&$setters) {
            [, $setters['child']] = useState('c');

            return H::span();
        }, 'child', StorageType::Memory);

        $parent = fc(static function () use ($child, &$setters) {
            [, $setters['before']] = useState('b');
            $rendered = $child();
            [, $setters['after']] = useState('a');

            return H::div(children: $rendered);
        }, 'parent', StorageType::Memory);

        $wrapper = $parent();
        $parentId = self::idOf($wrapper);
        self::assertSame($parentId, $setters['before'](1)->componentId);
        self::assertSame($parentId, $setters['after'](1)->componentId);
        self::assertSame(1, $setters['after'](1)->payload['index']);
        self::assertStringEndsWith('#child', (string) $setters['child'](1)->componentId);
        self::assertNull(ComponentState::current());
    }

    public function testRT63ExceptionRestoresParentAndStack(): void
    {
        $child = fc(static function (): Element {
            throw new \DomainException('boom');
        }, 'child');

        $afterCatch = null;
        $parent = fc(static function () use ($child, &$afterCatch) {
            $state = ComponentState::current();
            try {
                $child();
            } catch (\DomainException) {
                $afterCatch = [$state === ComponentState::current(), RenderContext::stackDepth()];
            }

            return H::div();
        }, 'parent', StorageType::Memory);

        $parent();

        self::assertSame([true, 1], $afterCatch);
        self::assertSame(0, RenderContext::stackDepth());
        self::assertNull(ComponentState::current());
    }

    public function testRT63HookPositionRestartsOnEveryRender(): void
    {
        $indexes = [];
        $component = fc(static function () use (&$indexes) {
            [, $set] = useState(0);
            $indexes[] = $set(1)->payload['index'];

            return H::div();
        }, 'k', StorageType::Memory);

        $component();
        $component();
        self::assertSame([0, 0], $indexes);
    }

    public function testRT65SnapshotWrapperAttributes(): void
    {
        $wrapper = RenderContext::run(self::scope(), static fn() => fc(static function () {
            [$value] = useState(1);

            return H::div(children: $value);
        }, 'k', StorageType::Snapshot)());

        $id = self::idOf($wrapper);
        $snapshot = new SnapshotSerializer(self::SECRET)->deserialize($wrapper->props['data-rosanna-snapshot']);
        self::assertSame([0 => 1], $snapshot->state);
        self::assertSame($id, $snapshot->getInstanceId());
        self::assertArrayNotHasKey('data-rosanna-persist', $wrapper->props);

        // VR-7 persist なしでも restore の署名を持つ
        $signer = new ActionSigner(self::SECRET);
        self::assertTrue($signer->verify($id, Action::RESTORE, $wrapper->props['data-rosanna-restore-sig'], '/page'));
    }

    public function testRT65PersistAttribute(): void
    {
        $wrapper = RenderContext::run(self::scope(), static fn() => fc(static fn() => H::div(), 'k', StorageType::Snapshot, persist: SnapshotPersist::LocalStorage)());
        self::assertSame('localStorage', $wrapper->props['data-rosanna-persist']);
        self::assertArrayHasKey('data-rosanna-restore-sig', $wrapper->props);
    }

    public function testRT66SnapshotWithoutAScopeIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('render scope');
        fc(static fn() => H::div(), 'k', StorageType::Snapshot)();
    }

    public function testSN10SnapshotWithoutASecretIsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('secret');
        RenderContext::run(self::scope(null), static fn() => fc(static fn() => H::div(), 'k', StorageType::Snapshot)());
    }

    public function testRT62DeferReturnsPlaceholderOutsideAnyScope(): void
    {
        $ran = false;
        $element = fc(static function () use (&$ran) {
            $ran = true;

            return H::div();
        }, defer: new Defer('late'))(['a' => 1, 'key' => 'x']);

        self::assertFalse($ran);
        self::assertSame('__defer__', $element->type);
        self::assertSame('late', $element->props['__name']);
        self::assertSame(['a' => 1], $element->props['__params']);
    }

    public function testRT62OnThePageDeferReturnsAPlaceholder(): void
    {
        $component = fc(static fn() => H::div(children: 'real'), defer: new Defer('late'));
        self::assertSame(
            '<div data-rosanna-defer-url="/_defer/late?a=1"></div>',
            self::html(static fn() => $component(['a' => 1])),
        );
    }

    public function testRT62AtItsEndpointTheTargetRendersAndNestedDeferStaysAPlaceholder(): void
    {
        $nested = fc(static fn() => H::div(children: 'nested-real'), defer: new Defer('nested'));
        $target = fc(static fn() => H::div(children: ['target-real', $nested()]), 'k', defer: new Defer('late'));

        $html = self::html(static fn() => $target(), self::scope(deferName: 'late'));

        self::assertStringContainsString('target-real', $html);
        self::assertStringNotContainsString('nested-real', $html);
        self::assertStringContainsString('data-rosanna-defer-url="/_defer/nested"', $html);
    }

    public function testRT94EndpointTargetIsFoundByItsRegisteredComponent(): void
    {
        $psx = new \Polidog\Rosanna\Runtime\PsxRenderer();
        $psx->register('App\\Late', fc(static fn() => H::div(children: 'real'), defer: new Defer('other-name')));

        $html = self::html(static fn() => RenderContext::scope()->psx->render('App\\Late'), self::scope(deferName: 'late', deferComponent: 'App\\Late', psx: $psx));
        self::assertStringContainsString('real', $html);

        $placeholder = self::html(static fn() => RenderContext::scope()->psx->render('App\\Late'), self::scope(psx: $psx));
        self::assertStringContainsString('/_defer/other-name', $placeholder);
    }

    public function testRT94NotTheEndpoint(): void
    {
        $component = new FunctionComponent(static fn() => H::div(), defer: new Defer('a'));
        self::assertFalse(self::scope()->isDeferredEndpointTarget($component));
        self::assertTrue(self::scope(deferName: 'a')->isDeferredEndpointTarget($component));
        self::assertFalse(self::scope(deferName: 'b')->isDeferredEndpointTarget($component));
    }
}
