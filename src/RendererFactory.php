<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Runtime\Renderer;
use Polidog\Rosanna\State\Protocol\ActionSigner;
use Polidog\Rosanna\State\Protocol\CsrfGuard;
use Polidog\Rosanna\State\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\State\Storage\SnapshotPersist;
use Polidog\Rosanna\State\Storage\StorageType;

/**
 * serializer、defer の prefix、CSRF トークン、署名を必ず揃えて ComponentRenderer を作る (RT-9.5)。
 */
final class RendererFactory
{
    public const DEFAULT_DEFER_PREFIX = '/_defer';

    public function __construct(
        private readonly ?SnapshotSerializer $snapshotSerializer,
        private readonly ActionSigner $signer,
        private readonly CsrfGuard $csrf,
        private readonly string $deferPrefix = self::DEFAULT_DEFER_PREFIX,
    ) {}

    /**
     * @param string $rootId action の componentId がないときの送信先
     * @param string|null $deferQuery この断片を答える defer のリクエストのクエリ。フォームが再描画のために運ぶ (HD-11)
     * @param string|null $deferName この断片を答える defer の名前。フォームが送信元として運ぶ (HD-9)
     */
    public function create(
        string $rootId,
        ?StorageType $storage = null,
        ?SnapshotPersist $persist = null,
        ?string $deferQuery = null,
        ?string $deferName = null,
    ): ComponentRenderer {
        return new ComponentRenderer(
            $rootId,
            $storage,
            $persist,
            $this->snapshotSerializer,
            $this->signer,
            new Renderer($this->extension($rootId, $deferQuery, $deferName)),
        );
    }

    /** `Renderer` に差し込む拡張。描画ごとの可変状態を持つので描画ごとに作る */
    public function extension(string $rootId, ?string $deferQuery = null, ?string $deferName = null): ActionFormExtension
    {
        return new ActionFormExtension($rootId, $deferQuery, $deferName, $this->snapshotSerializer, $this->signer, $this->csrf, $this->deferPrefix);
    }
}
