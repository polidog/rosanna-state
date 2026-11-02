<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Defer\Defer;
use Polidog\Rosanna\State\Storage\SnapshotPersist;
use Polidog\Rosanna\State\Storage\StorageType;

/**
 * 値は描画時に確定する。`$set($count + 1)` は「足す」ではなく「その値にする」。
 * 読み取りだけではセッションを開始しない (RT-7.4)。
 *
 * @template T
 * @param T $initial
 * @return array{0: T, 1: callable(T): Action}
 * @throws \LogicException コンポーネントの外
 */
#[\NoDiscard]
function useState(mixed $initial): array
{
    $state = HookSupport::currentState('useState');

    /** @var array{0: T, 1: callable(T): Action} */
    return HookSupport::useState($state, $initial, $state->getStorageType());
}

/**
 * フィールド名からフックの位置への対応を署名に載せる。値はクライアント入力である。
 *
 * @param array<array-key, mixed> $fields フィールド名 => useState の setter
 * @throws \LogicException コンポーネントの外
 * @throws \InvalidArgumentException RT-7.6
 */
function useFormAction(array $fields): Action
{
    HookSupport::currentState('useFormAction');

    if ($fields === []) {
        throw new \InvalidArgumentException('useFormAction() needs at least one field.');
    }

    $map = [];
    $target = null;
    foreach ($fields as $name => $setter) {
        // `tags[]` や `a.b` は $_POST から読み戻せない
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/D', $name) !== 1 || str_starts_with($name, '_rosanna_')) {
            throw new \InvalidArgumentException(sprintf('useFormAction(): invalid field name %s.', var_export($name, true)));
        }

        $action = useFormActionProbe($name, $setter);
        if ($target !== null && $target->componentId !== $action->componentId) {
            throw new \InvalidArgumentException('useFormAction(): all setters must belong to the same component.');
        }
        $target = $action;
        /** @var int $index */
        $index = $action->payload['index'];
        $map[$name] = $index;
    }

    /** @var Action $target */
    return Action::setFields($map, $target->componentId, $target->storageType);
}

/**
 * \Closure に限り、null を渡して setState の Action が返るかで setter かを判定する (RT-7.6)。
 *
 * @internal
 * @throws \InvalidArgumentException
 */
function useFormActionProbe(string $name, mixed $setter): Action
{
    $action = null;
    if ($setter instanceof \Closure) {
        try {
            $action = $setter(null);
        } catch (\TypeError) {
            // 引数の型や数が合わないものは setter ではない
        }
    }

    if (!$action instanceof Action || $action->type !== Action::SET_STATE || !is_int($action->payload['index'] ?? null)) {
        throw new \InvalidArgumentException("useFormAction(): field '{$name}' must be a useState() setter.");
    }

    return $action;
}

/**
 * 描画の中で同期的に実行する。クリーンアップはリクエストのメモリにだけ置き、次のリクエストでは実行しない。
 * 配列の deps は保存するので、Session の `useEffect($fn, [])` は初回に書き込みでセッションを始める。
 *
 * @param array<mixed>|null $deps
 * @throws \LogicException コンポーネントの外
 */
function useEffect(callable $callback, ?array $deps = null): void
{
    $state = HookSupport::currentState('useEffect');
    $state->runEffect($state->nextHookIndex(), $callback, $deps);
}

/** @throws \InvalidArgumentException persist を Snapshot 以外と組み合わせたとき */
function fc(
    callable $component,
    ?string $key = null,
    StorageType $storageType = StorageType::Session,
    ?Defer $defer = null,
    ?SnapshotPersist $persist = null,
): FunctionComponent {
    return new FunctionComponent(\Closure::fromCallable($component), $key, $storageType, $defer, $persist);
}

/**
 * fc() と違ってラッパーを出さず、保存先は Session になる (RT-6.7)。children に置くと展開される。
 *
 * @param array<string, mixed> $props
 * @throws \InvalidArgumentException key が string でも int でもないとき
 */
function component(callable $component, array $props = []): FunctionComponentCall
{
    $key = $props['key'] ?? null;
    unset($props['key']);
    if ($key !== null && !is_string($key) && !is_int($key)) {
        throw new \InvalidArgumentException('component(): the "key" prop must be a string or int, got ' . get_debug_type($key));
    }

    return new FunctionComponentCall(
        $component instanceof \Closure ? $component : \Closure::fromCallable($component),
        $props,
        $key === null ? null : (string) $key,
        getFunctionComponentName($component),
    );
}

/**
 * Action と Closure だけを受ける。is_callable() にすると、イベントの prop に流れた文字列が
 * 関数名として実行されるので、文字列、配列、呼び出し可能オブジェクトは実行せずに拒否する (RT-2.6)。
 *
 * クロージャが Action を返さなければ例外にする。黙って動かないボタンを描くと、描画のたびに走る副作用に気づけない (01-runtime.md の RT-2.6)。
 *
 * @internal
 * @throws \InvalidArgumentException
 */
function resolveEventAction(string $event, mixed $value): Action
{
    if ($value instanceof Action) {
        return $value;
    }
    if (!$value instanceof \Closure) {
        throw new \InvalidArgumentException(sprintf('%s must be an Action or a Closure; got %s', $event, get_debug_type($value)));
    }

    // クロージャはイベントのときではなく描画中に動くので、渡せるイベントの値がない
    if (new \ReflectionFunction($value)->getNumberOfRequiredParameters() > 0) {
        throw new \InvalidArgumentException(sprintf(
            '%s closures take no arguments: they run during rendering to build the Action. '
            . 'To receive what the user typed, use useFormAction().',
            $event,
        ));
    }

    $action = $value();
    if (!$action instanceof Action) {
        throw new \InvalidArgumentException(sprintf(
            '%1$s closure returned %2$s instead of an Action. It ran during rendering, not on the event, '
            . 'so its side effects have already run and would run on every render. '
            . 'Return an Action, such as the result of a useState() setter, and put side effects in useEffect(), '
            . 'depending on the state that setter changes. To have no action, write the condition at the prop: '
            . '%1$s: $can ? fn() => $set(1) : null.',
            $event,
            get_debug_type($action),
        ));
    }

    return $action;
}

/** RT-8.1 */
function getFunctionComponentName(callable $component): string
{
    if ($component instanceof \Closure) {
        $reflection = new \ReflectionFunction($component);
        $file = $reflection->getFileName();
        $line = $reflection->getStartLine();

        return 'FC@' . basename($file === false ? 'unknown' : $file) . ':' . ($line === false ? 0 : $line);
    }
    if (is_array($component)) {
        return (is_object($component[0]) ? $component[0]::class : $component[0]) . '::' . $component[1];
    }

    return is_string($component) ? $component : 'AnonymousFC';
}
