<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\State\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\State\Storage\SnapshotPersist;
use Polidog\Rosanna\State\Storage\StorageType;

/**
 * インスタンスの state を用意してから `Renderer` で描画する。`new` せず RendererFactory から得る (RT-9.5)。
 */
final class ComponentRenderer
{
    /** @internal RendererFactory を使う */
    public function __construct(
        private readonly string $rootId,
        private readonly ?StorageType $storageType,
        private readonly ?SnapshotPersist $persist,
        private readonly ?SnapshotSerializer $snapshotSerializer,
        private readonly \Polidog\Rosanna\State\Protocol\ActionSigner $signer,
        private readonly Renderer $renderer,
    ) {}

    /**
     * ラッパー付き。
     *
     * @param callable(): mixed $component
     * @throws \RuntimeException 不正な Element が届いたとき
     * @throws \LogicException 秘密鍵なしの Snapshot (SN-10)
     */
    public function render(callable $component): string
    {
        $parentState = ComponentState::current();
        try {
            $state = ComponentState::getInstance($this->rootId, $this->storageType);
            ComponentState::reset();
            $result = $component();
            $props = ComponentWrapper::props($state, $this->persist, $this->snapshotSerializer, $this->signer);

            return $this->renderElement(new Element('div', $props, [$result]));
        } finally {
            ComponentState::setCurrent($parentState);
        }
    }

    /**
     * ラッパーなし (部分更新)。Snapshot では末尾に新しいスナップショットの hidden を付ける (SN-12)。
     *
     * @param callable(): mixed $component
     * @throws \RuntimeException 不正な Element が届いたとき
     * @throws \LogicException 秘密鍵なしの Snapshot (SN-10)
     */
    public function renderPartial(callable $component): string
    {
        $parentState = ComponentState::current();
        try {
            $state = ComponentState::getInstance($this->rootId, $this->storageType);
            ComponentState::reset();
            $html = $this->renderElement(new Element('Fragment', [], [$component()]));
            if ($state->isSnapshotStorage()) {
                $html .= ActionFormExtension::hiddenField(
                    '_rosanna_snapshot',
                    ComponentWrapper::serialize($state, $this->snapshotSerializer),
                    ' data-rosanna-snapshot-update',
                );
            }

            return $html;
        } finally {
            ComponentState::setCurrent($parentState);
        }
    }

    /** @throws \RuntimeException 不正な Element が届いたとき */
    public function renderElement(Element|string $element): string
    {
        return $this->renderer->renderElement($element);
    }
}
