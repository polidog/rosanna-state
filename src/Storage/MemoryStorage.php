<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

class MemoryStorage implements StateStorageInterface
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clearByPrefix(string $prefix): void
    {
        foreach (array_keys($this->data) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->data[$key]);
            }
        }
    }
}
