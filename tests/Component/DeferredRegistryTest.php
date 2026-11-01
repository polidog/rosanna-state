<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\DeferredRegistry;

final class DeferredRegistryTest extends TestCase
{
    public function testGetReturnsTheRegistrationAndNullForAnUnknownName(): void
    {
        $registry = new DeferredRegistry()->register('panel', 'App\\Panel', 'public, max-age=60');

        self::assertSame(['component' => 'App\\Panel', 'cacheControl' => 'public, max-age=60'], $registry->get('panel'));
        self::assertNull($registry->get('nope'));
    }

    public function testNamesAreStringsEvenWhenPhpMakesTheKeyAnInteger(): void
    {
        $registry = new DeferredRegistry()->register('panel', 'App\\Panel')->register('123', 'App\\Numeric');

        self::assertSame(['panel', '123'], $registry->names());
    }

    public function testDF3TheSameNameIsRefused(): void
    {
        $registry = new DeferredRegistry()->register('panel', 'App\\Panel');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Deferred name 'panel' is already registered (component: App\\Panel)");
        $registry->register('panel', 'App\\Panel');
    }

    public function testAnInvalidNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeferredRegistry()->register('a/b', 'App\\Panel');
    }

    public function testRegisterIfNewIgnoresAnIdenticalRegistration(): void
    {
        $registry = new DeferredRegistry()
            ->registerIfNew('panel', 'App\\Panel', 'private')
            ->registerIfNew('panel', 'App\\Panel', 'private');

        self::assertSame(['component' => 'App\\Panel', 'cacheControl' => 'private'], $registry->get('panel'));
    }

    public function testRegisterIfNewRefusesADifferentComponent(): void
    {
        $registry = new DeferredRegistry()->registerIfNew('panel', 'App\\Panel', null);

        $this->expectException(\InvalidArgumentException::class);
        $registry->registerIfNew('panel', 'App\\Other', null);
    }

    public function testRegisterIfNewRefusesADifferentCachePolicy(): void
    {
        $registry = new DeferredRegistry()->registerIfNew('panel', 'App\\Panel', null);

        $this->expectException(\InvalidArgumentException::class);
        $registry->registerIfNew('panel', 'App\\Panel', 'private');
    }
}
