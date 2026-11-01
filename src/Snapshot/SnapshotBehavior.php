<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Snapshot;

enum SnapshotBehavior: string
{
    case Isolated = 'isolated';
    case Persistent = 'persistent';
    case Session = 'session';
}
