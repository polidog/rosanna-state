<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

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
