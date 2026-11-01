<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Storage\StorageType;

/**
 * 1 回の handle() の応答の判断の入力。ハンドラーのインスタンスに残さないために値で渡す (RQ-3)。
 *
 * @internal
 */
final readonly class ActionContext
{
    /** @param list<string> $carriedIds 復元した、運ばれたスナップショットのインスタンス */
    public function __construct(
        public Request $request,
        public RenderScope $scope,
        public Action $action,
        public ComponentState $state,
        public StorageType $storage,
        public SnapshotBehavior $behavior,
        public string $instanceId,
        public string $componentName,
        public bool $registered,
        public array $carriedIds,
        public bool $stale,
        public ?string $deferQuery,
        public ?string $deferName,
    ) {}
}
