<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Storage\MemoryStorage;
use Polidog\Rosanna\Storage\SessionStorage;
use Polidog\Rosanna\Storage\SnapshotStorage;
use Polidog\Rosanna\Storage\StateKey;
use Polidog\Rosanna\Storage\StateStorageInterface;
use Polidog\Rosanna\Storage\StorageType;

/**
 * 1 インスタンスの hook の位置、状態、バージョン、effect。
 */
final class ComponentState
{
    /** @var array<string, self> */
    private static array $instances = [];

    private static ?self $current = null;

    private int $hookIndex = 0;

    /**
     * ストレージに書くと $_SESSION の終了時の書き込みが致命的エラーになるので、ここにだけ置く (ST-6、RT-7.11)。
     *
     * @var array<int, \Closure>
     */
    private array $effectCleanups = [];

    /**
     * 描画時のフックの値。初期値は保存されない (ST-3) ので、差分の基準をフォームに載せるために覚える (AC-18)。
     *
     * @var array<int, mixed>
     */
    private array $renderedValues = [];

    private function __construct(
        private readonly string $componentId,
        private StateStorageInterface $storage,
        private StorageType $storageType,
    ) {}

    /**
     * 同じ ID があればそれを返し、$storageType は無視する (ST-5)。
     * Session の読み書きをするまでセッションを開始しない (ST-4)。
     */
    public static function getInstance(string $componentId, ?StorageType $storageType = null): self
    {
        if (!isset(self::$instances[$componentId])) {
            $type = $storageType ?? StorageType::Session;
            $storage = match ($type) {
                StorageType::Session => new SessionStorage(),
                StorageType::Memory => new MemoryStorage(),
                StorageType::Snapshot => new SnapshotStorage($componentId),
            };
            self::$instances[$componentId] = new self($componentId, $storage, $type);
        }

        return self::$current = self::$instances[$componentId];
    }

    /**
     * 同じ ID のインスタンスがあれば中身を置き換える。別のオブジェクトにすると、
     * 古い参照を持つ呼び出し側と 2 つに分かれる (ST-5)。
     */
    public static function fromSnapshot(Snapshot $snapshot): self
    {
        $componentId = $snapshot->getInstanceId();

        $storage = new SnapshotStorage($componentId);
        $storage->initializeFromSnapshot($snapshot);

        $instance = self::$instances[$componentId] ?? null;
        if ($instance === null) {
            $instance = new self($componentId, $storage, StorageType::Snapshot);
            self::$instances[$componentId] = $instance;
        } else {
            $instance->storage = $storage;
            $instance->storageType = StorageType::Snapshot;
            $instance->hookIndex = 0;
        }

        return self::$current = $instance;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    /** @internal コンポーネントの外のページの state と、入れ子の描画の後に hook を戻すため */
    public static function setCurrent(?self $state): void
    {
        self::$current = $state;
    }

    /** 現在のインスタンスの hook の位置を先頭に戻す。描画の開始で呼ぶ */
    public static function reset(): void
    {
        if (self::$current !== null) {
            self::$current->hookIndex = 0;
        }
    }

    /**
     * すべてのインスタンスを消す。Memory と Snapshot の値、effect のクリーンアップも一緒に消える (RT-9.6)。
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
        self::$current = null;
    }

    public function getComponentId(): string
    {
        return $this->componentId;
    }

    public function nextHookIndex(): int
    {
        return $this->hookIndex++;
    }

    /**
     * 保存がなければ $initial を返すだけで保存しない (ST-3)。
     * Snapshot だけは createSnapshot() に初期値が必要なのでリクエスト内の配列に記録する。
     */
    public function getState(int $index, mixed $initial): mixed
    {
        $key = $this->stateKey($index);
        if ($this->storage->has($key)) {
            return $this->storage->get($key);
        }
        if ($this->isSnapshotStorage()) {
            $this->storage->set($key, $initial);
        }

        return $initial;
    }

    /**
     * @throws \InvalidArgumentException 値が null、スカラー、それらの配列でないとき (ST-6)
     * @throws \LogicException Session でセッションを開始できないとき
     */
    public function setState(int $index, mixed $value): void
    {
        Action::assertJsonRoundTrippable($value);
        $this->storage->set($this->stateKey($index), $value);
    }

    /** 保存値があるか。Snapshot は getState() が記録した初期値も含む (AC-16、AC-18) */
    public function hasState(int $index): bool
    {
        return $this->storage->has($this->stateKey($index));
    }

    /** @internal useState のため */
    public function rememberRenderedValue(int $index, mixed $value): void
    {
        $this->renderedValues[$index] = $value;
    }

    /** @internal Renderer のため */
    public function hasRenderedValue(int $index): bool
    {
        return array_key_exists($index, $this->renderedValues);
    }

    /** @internal Renderer のため */
    public function renderedValue(int $index): mixed
    {
        return $this->renderedValues[$index] ?? null;
    }

    /** 状態と effect の deps を消す。バージョンは巻き戻さない (VR-3) */
    public function clearState(): void
    {
        $this->storage->clearByPrefix(StateKey::statePrefix($this->componentId));
        $this->storage->clearByPrefix(StateKey::effectDepsPrefix($this->componentId));
        $this->effectCleanups = [];
        $this->renderedValues = [];
    }

    /** 保存がなければ 0。読み取りはセッションを開始しない (VR-1、VR-3) */
    public function version(): int
    {
        $version = $this->storage->get(StateKey::version($this->componentId), 0);

        return is_int($version) ? $version : 0;
    }

    /**
     * action の適用ごとに 1 回呼ぶ (VR-2)。setFields は複数の setState() になるので、setState() では増やさない。
     *
     * @internal ActionHandler のため
     * @return int 増やした後のバージョン
     */
    public function incrementVersion(): int
    {
        $version = $this->version() + 1;
        $this->storage->set(StateKey::version($this->componentId), $version);

        return $version;
    }

    /**
     * useEffect の本体 (RT-7.8〜7.11)。順番は前回のクリーンアップ、callback、クリーンアップの記録、deps の保存。
     *
     * @param array<mixed>|null $deps
     */
    public function runEffect(int $index, callable $callback, ?array $deps = null): void
    {
        if (!$this->shouldRunEffect($index, $deps)) {
            return;
        }

        $this->runEffectCleanup($index);
        $cleanup = $callback();
        // RT-7.10 callable な文字列を実行しない
        if ($cleanup instanceof \Closure) {
            $this->setEffectCleanup($index, $cleanup);
        }
        $this->setEffectDeps($index, $deps);
    }

    /** @param array<mixed>|null $deps */
    public function shouldRunEffect(int $index, ?array $deps): bool
    {
        if ($deps === null) {
            return true;
        }

        $previous = $this->getEffectDeps($index);
        if ($previous === null || count($previous) !== count($deps)) {
            return true;
        }

        foreach ($deps as $position => $dep) {
            if (!array_key_exists($position, $previous) || $previous[$position] !== $dep) {
                return true;
            }
        }

        return false;
    }

    /**
     * null の deps は「前回の deps が配列でない」と同じなので保存せず、前回の値だけを消す。
     * deps のない useEffect が匿名の訪問者のセッションを始めないようにするため (ST-2)。
     *
     * @param array<mixed>|null $deps
     * @throws \InvalidArgumentException deps に null、スカラー、配列以外があるとき (ST-6)
     */
    public function setEffectDeps(int $index, ?array $deps): void
    {
        $key = $this->effectDepsKey($index);
        if ($deps === null) {
            $this->storage->delete($key);

            return;
        }

        Action::assertJsonRoundTrippable($deps);
        $this->storage->set($key, $deps);
    }

    /** @return array<mixed>|null */
    public function getEffectDeps(int $index): ?array
    {
        $deps = $this->storage->get($this->effectDepsKey($index));

        return is_array($deps) ? $deps : null;
    }

    public function setEffectCleanup(int $index, \Closure $cleanup): void
    {
        $this->effectCleanups[$index] = $cleanup;
    }

    public function runEffectCleanup(int $index): void
    {
        $cleanup = $this->effectCleanups[$index] ?? null;
        if ($cleanup !== null) {
            unset($this->effectCleanups[$index]);
            $cleanup();
        }
    }

    /** @throws \LogicException Snapshot 以外、または ID が `名前#key` の形でないとき */
    public function createSnapshot(): Snapshot
    {
        if (!$this->storage instanceof SnapshotStorage) {
            throw new \LogicException('createSnapshot() can only be called on snapshot storage');
        }

        $parts = explode('#', $this->componentId, 2);
        if (count($parts) !== 2) {
            throw new \LogicException("Instance ID \"{$this->componentId}\" has no key, so it cannot be restored from a snapshot");
        }

        $exported = $this->storage->exportState();

        return new Snapshot($parts[0], $parts[1], $exported['state'], $exported['effectDeps'], $this->version());
    }

    public function isSnapshotStorage(): bool
    {
        return $this->storageType === StorageType::Snapshot;
    }

    public function getStorageType(): StorageType
    {
        return $this->storageType;
    }

    private function stateKey(int $index): string
    {
        return StateKey::statePrefix($this->componentId) . $index;
    }

    private function effectDepsKey(int $index): string
    {
        return StateKey::effectDepsPrefix($this->componentId) . $index;
    }
}
