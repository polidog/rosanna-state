<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Component\Defer;

use function Polidog\Rosanna\Html\getFunctionComponentName;

use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

/**
 * `fc()` が返す、インスタンスの state を持つコンポーネント。
 */
final class FunctionComponent
{
    /** @var (\Closure(array<string, mixed>): list<mixed>)|null */
    private ?\Closure $argumentResolver = null;

    /** @throws \InvalidArgumentException persist を Snapshot 以外と組み合わせたとき (RT-6.1) */
    public function __construct(
        public readonly \Closure $inner,
        public readonly ?string $key = null,
        public readonly StorageType $storageType = StorageType::Session,
        public readonly ?Defer $defer = null,
        public readonly ?SnapshotPersist $persist = null,
    ) {
        if ($persist !== null && $storageType !== StorageType::Snapshot) {
            throw new \InvalidArgumentException('fc(): persist only applies to Snapshot storage.');
        }
    }

    /**
     * 第 2 引数以降を解決する口 (RT-11.3)。inner を包むと名前が変わり、インスタンス ID がずれるので別に持つ。
     *
     * @internal PsxRenderer のため
     * @param \Closure(array<string, mixed>): list<mixed> $resolver
     */
    public function withArgumentResolver(\Closure $resolver): self
    {
        $component = clone $this;
        $component->argumentResolver = $resolver;

        return $component;
    }

    /**
     * @param array<string, mixed> $props
     * @throws \InvalidArgumentException key が string でも int でもないとき (RT-8.2)
     * @throws \LogicException Snapshot でスコープか秘密鍵がないとき (RT-6.6、SN-10)
     */
    public function __invoke(array $props = []): Element
    {
        if ($this->defer !== null && RenderContext::currentScope()?->isDeferredEndpointTarget($this) !== true) {
            return $this->defer->buildPlaceholder($props);
        }

        $key = $this->instanceKey($props['key'] ?? null);
        unset($props['key']);

        $instanceId = RenderContext::beginComponent(getFunctionComponentName($this->inner), $key);
        $parentState = ComponentState::current();

        try {
            $state = ComponentState::getInstance($instanceId, $this->storageType);
            ComponentState::reset();

            $arguments = $this->argumentResolver === null ? [] : ($this->argumentResolver)($props);
            $result = ($this->inner)($props, ...$arguments);

            return new Element('div', ComponentWrapper::propsInScope($state, $this->persist), [$result]);
        } finally {
            RenderContext::endComponent();
            ComponentState::setCurrent($parentState);
        }
    }

    /** `fc()` の key が props の key の名前空間になる。1 つのコンポーネントを複数のインスタンスで描画できる (RT-8.2) */
    private function instanceKey(mixed $propsKey): ?string
    {
        if ($propsKey === null) {
            return $this->key;
        }
        if (!is_string($propsKey) && !is_int($propsKey)) {
            throw new \InvalidArgumentException('fc(): the "key" prop must be a string or int, got ' . get_debug_type($propsKey));
        }

        return $this->key === null ? (string) $propsKey : "{$this->key}/{$propsKey}";
    }
}
