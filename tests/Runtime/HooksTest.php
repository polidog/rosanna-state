<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Runtime\StateDiff;

use function Polidog\Rosanna\Runtime\useEffect;
use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/** RT-7、AC-13、AC-15 */
final class HooksTest extends TestCase
{
    use SessionTestHelper;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @param callable(): void $body */
    private static function inComponent(callable $body, string $id = 'C#0', StorageType $type = StorageType::Memory): ComponentState
    {
        $state = ComponentState::getInstance($id, $type);
        ComponentState::reset();
        $body();
        ComponentState::setCurrent(null);

        return $state;
    }

    /** @return iterable<string, array{\Closure}> */
    public static function hooksOutsideAComponent(): iterable
    {
        yield 'useState' => [static fn() => useState(1)[0]];
        yield 'useFormAction' => [static fn() => useFormAction(['a' => static fn() => null])];
        yield 'useEffect' => [static fn() => useEffect(static fn() => null)];
    }

    #[DataProvider('hooksOutsideAComponent')]
    public function testRT74aHooksOutsideAComponentAreLogicExceptions(\Closure $hook): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('component');
        $hook();
    }

    public function testRT74aHostMayProvideAPageState(): void
    {
        ComponentState::setCurrent(ComponentState::getInstance('Page#0', StorageType::Memory));
        self::assertSame(5, useState(5)[0]);
    }

    public function testRT71ReturnsValueAndSetterWithoutChangingState(): void
    {
        self::inComponent(static function (): void {
            [$value, $set] = useState(3);
            self::assertSame(3, $value);
            $action = $set(4);
            self::assertSame(Action::SET_STATE, $action->type);
            self::assertSame(['index' => 0, 'value' => 4], $action->payload);
            self::assertSame('C#0', $action->componentId);
            self::assertSame(StorageType::Memory, $action->storageType);
            self::assertFalse(ComponentState::current()?->hasState(0), 'the setter does not change the state');
        });
    }

    public function testRT72HooksAreIdentifiedByCallOrder(): void
    {
        self::inComponent(static function (): void {
            [, $a] = useState('a');
            [, $b] = useState('b');
            useEffect(static fn() => null);
            [, $c] = useState('c');
            self::assertSame(0, $a(1)->payload['index']);
            self::assertSame(1, $b(1)->payload['index']);
            self::assertSame(3, $c(1)->payload['index']);
        });
    }

    public function testRT73NullIsKeptAsAValue(): void
    {
        $state = ComponentState::getInstance('C#0', StorageType::Memory);
        $state->setState(0, null);
        self::inComponent(static function (): void {
            self::assertNull(useState('initial')[0]);
        });
    }

    #[RunInSeparateProcess]
    public function testRT73NullIsKeptInSessionStorage(): void
    {
        self::isolateSessions();
        session_start();
        $state = ComponentState::getInstance('C#0', StorageType::Session);
        $state->setState(0, null);
        self::inComponent(static function (): void {
            self::assertNull(useState('initial')[0]);
        }, type: StorageType::Session);
    }

    #[RunInSeparateProcess]
    public function testRT74ReadingDoesNotStartASession(): void
    {
        self::isolateSessions();
        self::inComponent(static function (): void {
            [$value, $set] = useState([1, 2, 3]);
            self::assertSame([1, 2, 3], $value);
            $set([1, 2]);
        }, type: StorageType::Session);

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testAC13ListChangeUsesADiffOnlyWhenItIsSmaller(): void
    {
        $rows = array_map(static fn(int $i) => ['id' => $i, 'done' => false], range(0, 99));
        self::inComponent(static function () use ($rows): void {
            [$value, $set] = useState($rows);
            $next = $value;
            $next[42]['done'] = true;

            $action = $set($next);
            self::assertArrayNotHasKey('value', $action->payload);
            self::assertSame(StateDiff::hash($rows), $action->payload['base']);
            self::assertSame($next, StateDiff::apply($rows, $action->payload['patch']));
        });
    }

    public function testAC13SmallValuesStaySetState(): void
    {
        self::inComponent(static function (): void {
            [, $set] = useState(1);
            self::assertArrayHasKey('value', $set(2)->payload);
        });
    }

    public function testAC15BaseIsTheRenderTimeValueEvenWhenTheNewValueDiffers(): void
    {
        $rows = array_map(static fn(int $i) => "row-{$i}", range(0, 99));
        self::inComponent(static function () use ($rows): void {
            [$value, $set] = useState($rows);
            $first = $set(array_slice($value, 1));
            $second = $set(array_slice($value, 2));
            self::assertSame($first->payload['base'], $second->payload['base']);
            self::assertSame(StateDiff::hash($rows), $first->payload['base']);
        });
    }

    public function testRT75UseFormActionMapsFieldsToHooks(): void
    {
        self::inComponent(static function (): void {
            [, $name] = useState('');
            [, $email] = useState('');

            $action = useFormAction(['name' => $name, 'email' => $email]);

            self::assertSame(Action::SET_FIELDS, $action->type);
            self::assertSame(['fields' => ['name' => 0, 'email' => 1]], $action->payload);
            self::assertSame('C#0', $action->componentId);
            self::assertSame(StorageType::Memory, $action->storageType);
        });
    }

    public function testRT76EmptyFieldsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::inComponent(static fn() => useFormAction([]));
    }

    /** @return iterable<string, array{string|int}> */
    public static function invalidFieldNames(): iterable
    {
        yield 'brackets' => ['tags[]'];
        yield 'dot' => ['a.b'];
        yield 'space' => ['a b'];
        yield 'digit first' => ['1a'];
        yield 'empty' => [''];
        yield 'reserved' => ['_rosanna_csrf'];
        yield 'trailing newline' => ["a\n"];
        yield 'int' => [0];
    }

    #[DataProvider('invalidFieldNames')]
    public function testRT76InvalidFieldNamesAreRefused(string|int $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::inComponent(static function () use ($name): void {
            [, $set] = useState('');
            useFormAction([$name => $set]);
        });
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonSetters(): iterable
    {
        yield 'string' => ['phpinfo'];
        yield 'array' => [[self::class, 'sideEffect']];
        yield 'closure returning nothing' => [static fn() => null];
        yield 'closure returning fields' => [static fn() => Action::setFields(['a' => 0])];
        yield 'closure with a typed argument' => [static fn(int $v) => Action::setState(0, $v)];
        yield 'closure with two arguments' => [static fn($a, $b) => Action::setState(0, $a)];
        yield 'closure returning restore' => [static fn() => Action::restore()];
    }

    #[DataProvider('nonSetters')]
    public function testRT76NonSettersAreRefused(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('setter');
        self::inComponent(static fn() => useFormAction(['field' => $value]));
    }

    public static function sideEffect(): never
    {
        self::fail('must not run');
    }

    public function testRT76SettersOfDifferentComponentsAreRefused(): void
    {
        $other = null;
        self::inComponent(static function () use (&$other): void {
            [, $other] = useState('');
        }, 'Other#0');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same component');
        self::inComponent(static function () use (&$other): void {
            [, $mine] = useState('');
            useFormAction(['a' => $mine, 'b' => $other]);
        });
    }

    public function testRT78NullDepsRunEveryRender(): void
    {
        $runs = 0;
        foreach ([1, 2, 3] as $_) {
            self::inComponent(static function () use (&$runs): void {
                useEffect(static function () use (&$runs): void {
                    $runs++;
                });
            });
        }
        self::assertSame(3, $runs);
    }

    public function testRT78EmptyDepsRunOnce(): void
    {
        $runs = 0;
        foreach ([1, 2, 3] as $_) {
            self::inComponent(static function () use (&$runs): void {
                useEffect(static function () use (&$runs): void {
                    $runs++;
                }, []);
            });
        }
        self::assertSame(1, $runs);
    }

    public function testRT78ArrayDepsCompareWithIdentity(): void
    {
        $runs = 0;
        foreach ([[1], [1], ['1'], ['1', 2], ['1', 2]] as $deps) {
            self::inComponent(static function () use (&$runs, $deps): void {
                useEffect(static function () use (&$runs): void {
                    $runs++;
                }, $deps);
            });
        }
        self::assertSame(3, $runs);
    }

    public function testRT78DepsMayChangeFromNullToAnArray(): void
    {
        $runs = 0;
        foreach ([null, [], [], null, []] as $deps) {
            self::inComponent(static function () use (&$runs, $deps): void {
                useEffect(static function () use (&$runs): void {
                    $runs++;
                }, $deps);
            });
        }
        self::assertSame(4, $runs);
    }

    public function testRT78NullAsADepValueIsCompared(): void
    {
        $runs = 0;
        foreach ([[null], [null], [0], [null]] as $deps) {
            self::inComponent(static function () use (&$runs, $deps): void {
                useEffect(static function () use (&$runs): void {
                    $runs++;
                }, $deps);
            });
        }
        self::assertSame(3, $runs);
    }

    public function testRT79OrderOfCleanupCallbackAndCleanupRecording(): void
    {
        $log = [];
        foreach ([1, 2] as $render) {
            self::inComponent(static function () use (&$log, $render): void {
                useEffect(static function () use (&$log, $render) {
                    $log[] = "effect {$render}";

                    return static function () use (&$log, $render): void {
                        $log[] = "cleanup {$render}";
                    };
                }, [$render]);
            });
        }
        self::assertSame(['effect 1', 'cleanup 1', 'effect 2'], $log);
    }

    public function testRT79EffectsDoNotRunTwiceInOneRender(): void
    {
        $cleanups = 0;
        foreach ([1, 1, 1] as $dep) {
            self::inComponent(static function () use (&$cleanups, $dep): void {
                useEffect(static function () use (&$cleanups) {
                    return static function () use (&$cleanups): void {
                        $cleanups++;
                    };
                }, [$dep]);
            });
        }
        self::assertSame(0, $cleanups, 'deps did not change, so neither the effect nor its cleanup run');
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonClosureReturns(): iterable
    {
        yield 'function name' => ['system'];
        yield 'array callable' => [[self::class, 'sideEffect']];
        yield 'invokable' => [new class {
            public function __invoke(): void
            {
                HooksTest::sideEffect();
            }
        }];
        yield 'int' => [1];
    }

    #[DataProvider('nonClosureReturns')]
    public function testRT710OnlyAClosureIsACleanup(mixed $returned): void
    {
        foreach ([1, 2] as $dep) {
            self::inComponent(static function () use ($returned, $dep): void {
                useEffect(static fn() => $returned, [$dep]);
            });
        }
        $this->addToAssertionCount(1);
    }

    #[RunInSeparateProcess]
    public function testRT711CleanupIsNotWrittenToStorageNorRunNextRequest(): void
    {
        self::isolateSessions();
        session_start();
        $ran = false;
        self::inComponent(static function () use (&$ran): void {
            useEffect(static fn() => static function () use (&$ran): void {
                $ran = true;
            }, [1]);
        }, type: StorageType::Session);

        // Closure をセッションに書くと serialize で例外になる
        self::assertIsString(serialize($_SESSION));
        self::assertStringNotContainsString('Closure', serialize($_SESSION));

        RequestLifecycle::reset();
        self::inComponent(static function (): void {
            useEffect(static fn() => null, [2]);
        }, type: StorageType::Session);
        self::assertFalse($ran, 'a cleanup does not survive into the next request');
    }

    public function testFcWiresHooksToItsInstance(): void
    {
        $wrapper = fc(static function () {
            [$count, $set] = useState(10);

            return H::button(onClick: static fn() => $set($count + 1), children: $count);
        }, 'k', StorageType::Memory)();

        $button = $wrapper->children[0];
        self::assertSame([10], $button->children);
        self::assertSame(11, $button->props['wire:click']->payload['value']);
        self::assertSame($wrapper->props['data-rosanna'], $button->props['wire:click']->componentId);
    }
}
