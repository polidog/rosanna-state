<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Protocol;

use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\State\Action;
use Polidog\Rosanna\State\ComponentState;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\State\Storage\StorageType;

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
