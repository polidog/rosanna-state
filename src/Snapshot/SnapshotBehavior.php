<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Snapshot;

enum SnapshotBehavior: string
{
    case Isolated = 'isolated';
    case Persistent = 'persistent';
    case Session = 'session';
}
