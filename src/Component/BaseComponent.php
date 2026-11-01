<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Component;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\ResolvesItself;
use Polidog\Rosanna\State\Action;
use Polidog\Rosanna\State\ComponentState;
use Polidog\Rosanna\State\ComponentWrapper;
use Polidog\Rosanna\State\HookSupport;
use Polidog\Rosanna\State\Protocol\WrapperSearch;
use Polidog\Rosanna\State\RenderPass;
use Polidog\Rosanna\State\Storage\StorageType;

abstract class BaseComponent implements ComponentInterface, ResolvesItself
{
    private ?ComponentState $componentState = null;

    /** @internal */
    public function setComponentState(ComponentState $state): void
    {
        $this->componentState = $state;
    }

    public function getComponentState(): ?ComponentState
    {
        return $this->componentState;
    }

    /**
     * 登録できないクラスはレジストリで保存先を決められないので、setter の Action にインスタンスの保存先を入れる (RT-5.3)。
     *
     * @template T
     * @param T $initial
     * @return array{0: T, 1: callable(T): Action}
     */
    #[\NoDiscard]
    protected function useState(mixed $initial): array
    {
        if ($this->componentState === null) {
            $componentId = RenderPass::currentComponentId();

            return [$initial, static fn(mixed $value): Action => Action::setState(0, $value, $componentId)];
        }

        /** @var array{0: T, 1: callable(T): Action} */
        return HookSupport::useState($this->componentState, $initial, $this->componentState->getStorageType());
    }

    /** state が設定済みのインスタンスは、新しい番号を取らず同じ状態を読む (RT-5.4) */
    final public function resolve(): Element
    {
        $attribute = Component::forClass(static::class);
        $parentState = ComponentState::current();
        $state = $this->componentState;

        if ($state === null) {
            $instanceId = RenderPass::beginComponent(static::getComponentName());
        } else {
            RenderPass::enterComponent($state->getComponentId());
        }

        try {
            if ($state === null) {
                $state = ComponentState::getInstance($instanceId, $attribute->storageType ?? StorageType::Session);
                $this->setComponentState($state);
            } else {
                ComponentState::setCurrent($state);
            }
            ComponentState::reset();
            $instanceId = $state->getComponentId();
            $result = RenderPass::inClassScope($instanceId, fn(): Element => WrapperSearch::expand($this->render()));

            // スナップショットには描画で使われたフックの値が要る
            return new Element('div', ComponentWrapper::propsInScope($state, $attribute?->persist), [$result]);
        } finally {
            RenderPass::endComponent();
            ComponentState::setCurrent($parentState);
        }
    }

    public static function getComponentName(): string
    {
        return Component::forClass(static::class)->name ?? static::class;
    }
}
