<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

final class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private array $components = [];

    /** @var array<string, Component> */
    private array $attributes = [];

    /** @var array<string, Defer> */
    private array $defers = [];

    /**
     * 同じ名前の再登録は、古いクラスの設定を引き継がず上書きする (RT-10.1)。
     *
     * @param class-string<ComponentInterface> $className
     * @throws \InvalidArgumentException ComponentInterface を実装していないか、属性が不正なとき
     */
    public function register(string $className): self
    {
        if (!is_subclass_of($className, ComponentInterface::class)) {
            throw new \InvalidArgumentException("{$className} must implement " . ComponentInterface::class);
        }

        $name = $className::getComponentName();
        $component = Component::forClass($className);
        $defer = new \ReflectionClass($className)->getAttributes(Defer::class)[0] ?? null;

        unset($this->attributes[$name], $this->defers[$name]);
        $this->components[$name] = $className;
        if ($component !== null) {
            $this->attributes[$name] = $component;
        }
        if ($defer !== null) {
            $this->defers[$name] = $defer->newInstance();
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /** @return class-string<ComponentInterface>|null */
    public function get(string $name): ?string
    {
        return $this->components[$name] ?? null;
    }

    /** DI はしない。コンストラクタに引数を要するクラスは登録せず、host が Element を作って埋め込む (RT-10.3) */
    public function create(string $name): ?ComponentInterface
    {
        $className = $this->components[$name] ?? null;

        return $className === null ? null : new $className();
    }

    public function getStorageType(string $name): StorageType
    {
        return $this->attributes[$name]->storageType ?? StorageType::Session;
    }

    public function getPersist(string $name): ?SnapshotPersist
    {
        return $this->attributes[$name]->persist ?? null;
    }

    public function getDefer(string $name): ?Defer
    {
        return $this->defers[$name] ?? null;
    }

    /** @return array<string, class-string<ComponentInterface>> */
    public function all(): array
    {
        return $this->components;
    }
}
