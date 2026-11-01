<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Component
{
    public StorageType $storageType;

    public ?SnapshotPersist $persist;

    /** @throws \InvalidArgumentException 未知の値、または persist を Snapshot 以外と組み合わせたとき (RT-5.2) */
    public function __construct(
        public ?string $name = null,
        StorageType|string $storage = StorageType::Session,
        SnapshotPersist|string|null $persist = null,
    ) {
        $this->storageType = is_string($storage)
            ? (StorageType::tryFrom($storage) ?? throw new \InvalidArgumentException("Unknown storage type: {$storage}"))
            : $storage;
        $this->persist = is_string($persist)
            ? (SnapshotPersist::tryFrom($persist) ?? throw new \InvalidArgumentException("Unknown snapshot persist: {$persist}"))
            : $persist;

        if ($this->persist !== null && $this->storageType !== StorageType::Snapshot) {
            throw new \InvalidArgumentException('#[Component]: persist only applies to Snapshot storage.');
        }
    }

    /**
     * @internal
     * @param class-string $className
     */
    public static function forClass(string $className): ?self
    {
        $attribute = new \ReflectionClass($className)->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance();
    }
}
