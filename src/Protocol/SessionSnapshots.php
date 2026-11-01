<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Snapshot\SnapshotVerificationException;

/**
 * SnapshotBehavior::Session のスロット。インスタンス ID => 署名済み JSON の map で、書いた順に並ぶ (SN-24、SN-25)。
 * 呼び出し側がセッションをアクティブにしておく。
 *
 * @internal
 */
final class SessionSnapshots
{
    private const KEY = '_rosanna_snapshot';

    private const MAX_ENTRIES = 64;

    /**
     * 全件を検証する。別のセッションの署名、別の ID に保存されたもの、壊れたものは捨ててログに出す。
     *
     * @return array<string, Snapshot>
     */
    public static function load(SnapshotSerializer $serializer): array
    {
        return self::read($serializer)[0];
    }

    /** 他のインスタンスは残し、上限を超えたら最も古く書いたものを捨てる */
    public static function store(SnapshotSerializer $serializer, Snapshot $snapshot): void
    {
        $entries = self::read($serializer)[1];
        $instanceId = $snapshot->getInstanceId();

        // 入れ直して新しい側に動かす
        unset($entries[$instanceId]);
        $entries[$instanceId] = self::bound($serializer)->serialize($snapshot);

        while (count($entries) > self::MAX_ENTRIES) {
            error_log(sprintf(
                '[Rosanna] Snapshot evicted (behavior=Session): the session keeps %d instances, %s goes back to its initial state',
                self::MAX_ENTRIES,
                (string) array_key_first($entries),
            ));
            array_shift($entries);
        }

        $_SESSION[self::KEY] = $entries;
    }

    /** @return array{array<string, Snapshot>, array<string, string>} */
    private static function read(SnapshotSerializer $serializer): array
    {
        if (!array_key_exists(self::KEY, $_SESSION)) {
            return [[], []];
        }

        $stored = $_SESSION[self::KEY];
        $bound = self::bound($serializer);
        $snapshots = [];
        $entries = [];
        $dropped = !is_array($stored);

        foreach (is_array($stored) ? $stored : [] as $instanceId => $json) {
            try {
                if (!is_string($json)) {
                    throw new SnapshotVerificationException('Stored snapshot is not a string');
                }
                $snapshot = $bound->deserialize($json);
                if ((string) $instanceId !== $snapshot->getInstanceId()) {
                    throw new SnapshotVerificationException('Stored snapshot names another instance');
                }
                $snapshots[$snapshot->getInstanceId()] = $snapshot;
                $entries[$snapshot->getInstanceId()] = $json;
            } catch (SnapshotVerificationException $e) {
                error_log('[Rosanna] Snapshot rejected (behavior=Session): ' . $e->getMessage());
                $dropped = true;
            }
        }

        // 同じものをリクエストごとに検証して記録し続けない
        if ($dropped) {
            $_SESSION[self::KEY] = $entries;
        }

        return [$snapshots, $entries];
    }

    /** セッション ID に束縛する。別のセッションで得た署名を植え付けられない (02 の 10 節) */
    private static function bound(SnapshotSerializer $serializer): SnapshotSerializer
    {
        return $serializer->withContext((string) session_id());
    }
}
