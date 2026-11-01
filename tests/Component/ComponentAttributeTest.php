<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

#[Component(name: 'custom-name')]
final class NamedForAttributeTest extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

final class UnnamedForAttributeTest extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

/** RT-5.1、RT-5.2 */
final class ComponentAttributeTest extends TestCase
{
    public function testRT51NameComesFromTheAttribute(): void
    {
        self::assertSame('custom-name', NamedForAttributeTest::getComponentName());
    }

    public function testRT51NameFallsBackToTheClass(): void
    {
        self::assertSame(UnnamedForAttributeTest::class, UnnamedForAttributeTest::getComponentName());
    }

    public function testRT52Defaults(): void
    {
        $attribute = new Component();
        self::assertNull($attribute->name);
        self::assertSame(StorageType::Session, $attribute->storageType);
        self::assertNull($attribute->persist);
    }

    public function testRT52EnumOrString(): void
    {
        self::assertSame(StorageType::Memory, new Component(storage: StorageType::Memory)->storageType);
        self::assertSame(StorageType::Snapshot, new Component(storage: 'snapshot')->storageType);
        self::assertSame(SnapshotPersist::LocalStorage, new Component(storage: 'snapshot', persist: SnapshotPersist::LocalStorage)->persist);
        self::assertSame(SnapshotPersist::SessionStorage, new Component(storage: 'snapshot', persist: 'sessionStorage')->persist);
    }

    public function testRT52UnknownStorageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Component(storage: 'redis');
    }

    public function testRT52UnknownPersistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Component(storage: 'snapshot', persist: 'cookie');
    }

    public function testRT52PersistRequiresSnapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Component(storage: 'session', persist: 'localStorage');
    }
}
