<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

interface StateStorageInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function delete(string $key): void;

    public function clearByPrefix(string $prefix): void;
}
