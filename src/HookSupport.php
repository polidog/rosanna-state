<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Storage\StorageType;

/**
 * useState 関数と BaseComponent::useState() で共有する部分。
 *
 * @internal
 */
final class HookSupport
{
    /** @throws \LogicException コンポーネントの外 (RT-7.4a) */
    public static function currentState(string $hook): ComponentState
    {
        return ComponentState::current() ?? throw new \LogicException(
            "{$hook}() must be called while a component is rendering. Use fc() or a class component.",
        );
    }

    /**
     * @return array{mixed, \Closure(mixed): Action}
     */
    public static function useState(ComponentState $state, mixed $initial, ?StorageType $storageType): array
    {
        $index = $state->nextHookIndex();
        $value = $state->getState($index, $initial);
        $state->rememberRenderedValue($index, $value);
        $componentId = $state->getComponentId();
        $base = null;

        // AC-13 差分は運び方の違いで、適用の結果は描画時に確定している。AC-15 base はフックごとに 1 回だけ計算する
        $setter = static function (mixed $next) use ($index, $value, $componentId, $storageType, &$base): Action {
            $patch = StateDiff::create($value, $next);
            if ($patch === null) {
                return Action::setState($index, $next, $componentId, $storageType);
            }
            $base ??= StateDiff::hash($value);

            return Action::setStateDiff($index, $patch, $base, $componentId, $storageType);
        };

        return [$value, $setter];
    }
}
