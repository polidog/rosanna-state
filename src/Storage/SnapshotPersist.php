<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

enum SnapshotPersist: string
{
    case SessionStorage = 'sessionStorage';
    case LocalStorage = 'localStorage';
}
