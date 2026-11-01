<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

/**
 * serializer、defer の prefix、CSRF トークン、署名を必ず揃えて Renderer を作る (RT-9.5)。
 */
final class RendererFactory
{
    public function __construct(
        private readonly ?SnapshotSerializer $snapshotSerializer,
        private readonly ActionSigner $signer,
        private readonly CsrfGuard $csrf,
        private readonly string $deferPrefix = Renderer::DEFAULT_DEFER_PREFIX,
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
    ): Renderer {
        return new Renderer($rootId, $storage, $persist, $deferQuery, $deferName, $this->snapshotSerializer, $this->signer, $this->csrf, $this->deferPrefix);
    }
}
