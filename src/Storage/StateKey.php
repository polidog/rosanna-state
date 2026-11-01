<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

/**
 * 状態のキーの形式を 1 か所に置く。
 *
 * @internal
 */
final class StateKey
{
    public static function statePrefix(string $instanceId): string
    {
        return "rosanna:{$instanceId}:state:";
    }

    public static function effectDepsPrefix(string $instanceId): string
    {
        return "rosanna:{$instanceId}:effect_deps:";
    }

    public static function version(string $instanceId): string
    {
        return "rosanna:{$instanceId}:version";
    }
}
