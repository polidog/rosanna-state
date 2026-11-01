<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Defer\DeclaresDefer;
use Polidog\Rosanna\Defer\EndpointTargetScope;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\State\Protocol\ActionSigner;
use Polidog\Rosanna\State\Snapshot\SnapshotSerializer;

/**
 * 状態層のリクエストごとの値。`RenderScope::$state` に入れる。
 */
final class StateScope implements EndpointTargetScope
{
    /**
     * @param ActionSigner $signer 鍵がなければ sign() が null を返す
     * @param string $signingScope `ActionSigner::signingScope()` の値。page:{パス} か defer:{名前}
     * @param string|null $deferredEndpointName defer エンドポイントの描画なら登録名
     * @param string|null $deferredEndpointComponent その登録の component (PSX の FQCN)
     */
    public function __construct(
        public readonly RendererFactory $renderers,
        public readonly PsxRenderer $psx,
        public readonly ?SnapshotSerializer $snapshotSerializer,
        public readonly ActionSigner $signer,
        public readonly string $signingScope,
        private readonly ?string $deferredEndpointName = null,
        private readonly ?string $deferredEndpointComponent = null,
    ) {}

    /** @throws \LogicException 状態層のスコープでないとき */
    public static function of(RenderScope $scope): self
    {
        return $scope->state instanceof self
            ? $scope->state
            : throw new \LogicException('This render scope has no state layer. Create it with RosannaState::scope().');
    }

    /** 描画中でないか、状態層のスコープでなければ null */
    public static function current(): ?self
    {
        $state = RenderContext::currentScope()?->state;

        return $state instanceof self ? $state : null;
    }

    /**
     * 対象自身だけがプレースホルダーを飛ばす。入れ子の defer はプレースホルダーのままにする (RT-6.2、RT-9.4)。
     * 親のキャッシュの方針で子の出力が返るのを防ぐ。
     */
    public function isDeferredEndpointTarget(DeclaresDefer $component): bool
    {
        if ($this->deferredEndpointName === null) {
            return false;
        }
        if ($component->deferDeclaration()?->name === $this->deferredEndpointName) {
            return true;
        }

        return $this->deferredEndpointComponent !== null
            && $this->psx->loaded($this->deferredEndpointComponent) === $component;
    }
}
