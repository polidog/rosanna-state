<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Runtime;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\State\component;

use Polidog\Rosanna\State\ComponentState;

use function Polidog\Rosanna\State\fc;

use Polidog\Rosanna\State\Storage\StorageType;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;
use Polidog\Rosanna\State\Tests\Storage\SessionTestHelper;

use function Polidog\Rosanna\State\useState;

/** RT-6.7 `component()` はラッパーを出さず、保存先が Session になる */
final class ComponentFunctionTest extends TestCase
{
    use RendersInScope;
    use SessionTestHelper;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    public function testRT67EmitsNoWrapper(): void
    {
        $html = self::html(static fn() => H::section(children: [
            component(static fn(array $props) => H::span(children: $props['v']), ['v' => 'a']),
            // Element の children に直接置いても同じ
            new Element('div', [], [component(static fn() => H::i())]),
        ]));

        self::assertSame('<section><span>a</span><div><i></i></div></section>', $html);
        self::assertStringNotContainsString('data-rosanna', $html);
    }

    /** 検出の確認: `fc()` はラッパーを出す */
    public function testRT67FunctionComponentDoesEmitAWrapper(): void
    {
        $html = self::html(static fn() => H::section(children: [fc(static fn() => H::i(), 'k', StorageType::Memory)()]));

        self::assertStringContainsString('data-rosanna="', $html);
    }

    /** children に置くまで動かない */
    public function testRT67BuildingTheValueDoesNotRunTheComponent(): void
    {
        $ran = false;
        component(static function () use (&$ran): Element {
            $ran = true;

            return H::div();
        });

        self::assertFalse($ran);
    }

    public function testRT67ComponentMustReturnAnElement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must return an Element');
        H::div(children: [component(static fn() => 'text')]);
    }

    public function testRT67ParentStateIsRestored(): void
    {
        $parent = ComponentState::getInstance('P#0', StorageType::Memory);
        ComponentState::setCurrent($parent);

        H::div(children: [component(static fn() => H::i())]);

        self::assertSame($parent, ComponentState::current());
    }

    #[RunInSeparateProcess]
    public function testRT67StateIsStoredInTheSession(): void
    {
        self::isolateSessions();
        self::visitorWithCookie('componentcomponentcomponent');
        $id = null;
        $counter = static function () use (&$id): Element {
            [$value] = useState('initial');
            $state = ComponentState::current();
            \assert($state !== null);
            $id = $state->getComponentId();
            $state->setState(0, 'stored');

            return H::span(children: $value);
        };

        self::assertSame('<div><span>initial</span></div>', self::html(static fn() => H::div(children: [component($counter, ['key' => 'k'])])));

        self::assertStringEndsWith('#k', (string) $id);
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertContains('stored', $_SESSION);

        // Memory なら次のリクエストに残らない。Session なので読める
        RequestLifecycle::reset();
        $_COOKIE = [(string) session_name() => 'componentcomponentcomponent'];
        self::assertSame('<div><span>stored</span></div>', self::html(static fn() => H::div(children: [component($counter, ['key' => 'k'])])));
    }
}
