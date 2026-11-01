<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\ResolvesItself;
use Polidog\Rosanna\State\Storage\StorageType;

/**
 * `component()` の値。children に置くと `resolveRenderables()` が展開する。
 */
final class FunctionComponentCall implements ResolvesItself
{
    /** @param array<string, mixed> $props */
    public function __construct(
        private readonly \Closure $component,
        private readonly array $props,
        private readonly ?string $key,
        private readonly string $name,
    ) {}

    /** @throws \RuntimeException コンポーネントが Element を返さないとき */
    public function resolve(): Element
    {
        $instanceId = RenderPass::beginComponent($this->name, $this->key);
        $parentState = ComponentState::current();

        try {
            ComponentState::getInstance($instanceId, StorageType::Session);
            ComponentState::reset();
            $result = ($this->component)($this->props);
        } finally {
            RenderPass::endComponent();
            ComponentState::setCurrent($parentState);
        }

        if (!$result instanceof Element) {
            throw new \RuntimeException(sprintf('Function component %s must return an Element; got %s.', $this->name, get_debug_type($result)));
        }

        return $result;
    }

    public function render(): Element
    {
        return $this->resolve();
    }
}
