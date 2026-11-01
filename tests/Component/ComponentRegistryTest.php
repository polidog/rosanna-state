<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Component\ComponentRegistry;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

#[Component(name: 'shared-name', storage: 'snapshot', persist: 'sessionStorage')]
#[Defer(name: 'slot', cacheControl: 'public, max-age=60')]
final class FirstRegistered extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

#[Component(name: 'shared-name')]
final class SecondRegistered extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

final class PlainRegistered extends BaseComponent
{
    public function render(): Element
    {
        return H::div();
    }
}

/** RT-10 */
final class ComponentRegistryTest extends TestCase
{
    public function testRT10RegisterAndLookUp(): void
    {
        $registry = new ComponentRegistry();
        self::assertSame($registry, $registry->register(PlainRegistered::class));

        self::assertTrue($registry->has(PlainRegistered::class));
        self::assertFalse($registry->has('nope'));
        self::assertSame(PlainRegistered::class, $registry->get(PlainRegistered::class));
        self::assertNull($registry->get('nope'));
        self::assertSame([PlainRegistered::class => PlainRegistered::class], $registry->all());
    }

    public function testRT101ReRegisteringDropsTheOldSettings(): void
    {
        $registry = new ComponentRegistry();
        $registry->register(FirstRegistered::class);
        self::assertSame(StorageType::Snapshot, $registry->getStorageType('shared-name'));
        self::assertSame(SnapshotPersist::SessionStorage, $registry->getPersist('shared-name'));
        self::assertSame('slot', $registry->getDefer('shared-name')?->name);

        $registry->register(SecondRegistered::class);
        self::assertSame(SecondRegistered::class, $registry->get('shared-name'));
        self::assertSame(StorageType::Session, $registry->getStorageType('shared-name'));
        self::assertNull($registry->getPersist('shared-name'));
        self::assertNull($registry->getDefer('shared-name'));
    }

    public function testRT102DefaultStorageIsSession(): void
    {
        $registry = new ComponentRegistry()->register(PlainRegistered::class);
        self::assertSame(StorageType::Session, $registry->getStorageType('unknown'));
        self::assertSame(StorageType::Session, $registry->getStorageType(PlainRegistered::class));
    }

    public function testRT103Create(): void
    {
        $registry = new ComponentRegistry()->register(PlainRegistered::class);
        self::assertInstanceOf(PlainRegistered::class, $registry->create(PlainRegistered::class));
        self::assertNull($registry->create('nope'));
    }

    public function testNonComponentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ComponentRegistry()->register(\stdClass::class); // @phpstan-ignore argument.type
    }
}
