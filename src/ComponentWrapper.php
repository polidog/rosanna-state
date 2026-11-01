<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Storage\SnapshotPersist;

/**
 * コンポーネントのラッパーの属性 (RT-6.4、RT-6.5)。入口によって HTML が変わらないよう 1 か所に置く。
 *
 * @internal
 */
final class ComponentWrapper
{
    /**
     * @return array<string, string>
     * @throws \LogicException 秘密鍵なしで Snapshot を描画しようとしたとき (SN-10)
     */
    public static function props(
        ComponentState $state,
        ?SnapshotPersist $persist,
        ?SnapshotSerializer $serializer,
        ActionSigner $signer,
    ): array {
        $instanceId = $state->getComponentId();
        $props = ['data-rosanna' => $instanceId];
        if (!$state->isSnapshotStorage()) {
            return $props;
        }

        $props['data-rosanna-snapshot'] = self::serialize($state, $serializer);

        // VR-7 古い送信を止めた後の restore に要るので persist の有無によらず付ける
        $signature = $signer->sign($instanceId, Action::RESTORE);
        if ($signature !== null) {
            $props['data-rosanna-restore-sig'] = $signature;
        }
        if ($persist !== null) {
            $props['data-rosanna-persist'] = $persist->value;
        }

        return $props;
    }

    /**
     * @return array<string, string>
     * @throws \LogicException Snapshot でスコープがないとき。署名なしのスナップショットを出さない (RT-6.6)
     */
    public static function propsInScope(ComponentState $state, ?SnapshotPersist $persist): array
    {
        $scope = RenderContext::currentScope();
        if ($scope === null) {
            if ($state->isSnapshotStorage()) {
                throw new \LogicException(
                    'Cannot render a Snapshot component outside of a render scope: the snapshot cannot be signed. '
                    . 'Render it through RenderContext::run().',
                );
            }

            return ['data-rosanna' => $state->getComponentId()];
        }

        return self::props($state, $persist, $scope->snapshotSerializer, $scope->signer);
    }

    /** @throws \LogicException 秘密鍵がないとき (SN-10) */
    public static function serialize(ComponentState $state, ?SnapshotSerializer $serializer): string
    {
        if ($serializer === null) {
            throw new \LogicException(
                'Cannot render a Snapshot component without a secret key. '
                . 'Call setSnapshotSecret() with a high-entropy key before rendering it.',
            );
        }

        return $serializer->serialize($state->createSnapshot());
    }
}
