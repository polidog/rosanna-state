<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

use Polidog\Rosanna\Runtime\Snapshot;

/**
 * 1 インスタンス分の状態をリクエストの間だけ持つ (ST-7)。
 */
final class SnapshotStorage extends MemoryStorage
{
    public function __construct(private readonly string $instanceId) {}

    public function initializeFromSnapshot(Snapshot $snapshot): void
    {
        $statePrefix = StateKey::statePrefix($this->instanceId);
        $effectDepsPrefix = StateKey::effectDepsPrefix($this->instanceId);

        $this->data = [StateKey::version($this->instanceId) => $snapshot->version];
        foreach ($snapshot->state as $index => $value) {
            $this->data[$statePrefix . $index] = $value;
        }
        foreach ($snapshot->effectDeps as $index => $deps) {
            $this->data[$effectDepsPrefix . $index] = $deps;
        }
    }

    /**
     * @return array{state: array<int, mixed>, effectDeps: array<int, array<mixed>|null>}
     */
    public function exportState(): array
    {
        $statePrefix = StateKey::statePrefix($this->instanceId);
        $effectDepsPrefix = StateKey::effectDepsPrefix($this->instanceId);

        $state = [];
        $effectDeps = [];
        foreach ($this->data as $key => $value) {
            if (str_starts_with($key, $statePrefix)) {
                $state[(int) substr($key, strlen($statePrefix))] = $value;
            } elseif (str_starts_with($key, $effectDepsPrefix)) {
                /** @var array<mixed>|null $value */
                $effectDeps[(int) substr($key, strlen($effectDepsPrefix))] = $value;
            }
        }

        // 順序が違うと JSON がリストにならず、同じ状態でも別の署名になる
        ksort($state);
        ksort($effectDeps);

        return ['state' => $state, 'effectDeps' => $effectDeps];
    }
}
