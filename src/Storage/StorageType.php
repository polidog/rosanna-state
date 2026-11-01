<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Storage;

enum StorageType: string
{
    case Session = 'session';
    case Memory = 'memory';
    case Snapshot = 'snapshot';
}
