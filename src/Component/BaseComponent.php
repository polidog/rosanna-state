<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\HookSupport;
use Polidog\Rosanna\Runtime\RenderContext;

abstract class BaseComponent implements ComponentInterface
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
     * setter の Action に storageType を入れない。action の処理時にレジストリの設定で決める (RT-5.3)。
     *
     * @template T
     * @param T $initial
     * @return array{0: T, 1: callable(T): Action}
     */
    #[\NoDiscard]
    protected function useState(mixed $initial): array
    {
        if ($this->componentState === null) {
            $componentId = RenderContext::currentComponentId();

            return [$initial, static fn(mixed $value): Action => Action::setState(0, $value, $componentId)];
        }

        /** @var array{0: T, 1: callable(T): Action} */
        return HookSupport::useState($this->componentState, $initial, null);
    }

    public static function getComponentName(): string
    {
        return Component::forClass(static::class)->name ?? static::class;
    }
}
