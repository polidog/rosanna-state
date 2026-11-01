<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Runtime\Support\RendersInScope;

/** Defer と H::defer、属性名は js-contract */
final class DeferTest extends TestCase
{
    use RendersInScope;

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        yield 'slash' => ['a/b'];
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'query' => ['a?b'];
        yield 'dots' => ['..'];
        yield 'trailing newline' => ["a\n"];
    }

    #[DataProvider('invalidNames')]
    public function testConstructorRejectsInvalidNames(string $name): void
    {
        self::assertFalse(Defer::isValidName($name));
        $this->expectException(\InvalidArgumentException::class);
        new Defer($name);
    }

    #[DataProvider('invalidNames')]
    public function testHelperRejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        H::defer($name);
    }

    public function testRendererRefusesAHandBuiltInvalidName(): void
    {
        $this->expectException(\RuntimeException::class);
        self::html(static fn() => new Element('__defer__', ['__name' => 'a/b']));
    }

    public function testValidNames(): void
    {
        self::assertTrue(Defer::isValidName('user-Header_1'));
    }

    public function testLocalCacheTtlIsNormalisedAndRequiresLocalCache(): void
    {
        self::assertSame(0, new Defer('a', localCache: true, localCacheTtl: -5)->localCacheTtl);
        self::assertSame(0, new Defer('a', localCacheTtl: -5)->localCacheTtl);
        self::assertSame(60, new Defer('a', localCache: true, localCacheTtl: 60)->localCacheTtl);

        $this->expectException(\InvalidArgumentException::class);
        new Defer('a', localCacheTtl: 60);
    }

    public function testBuildPlaceholderForwardsOptionsAndStripsFrameworkProps(): void
    {
        $fallback = H::p(children: 'wait');
        $element = new Defer('late', localCache: true, reloadable: true, localCacheTtl: 30)->buildPlaceholder([
            'fallback' => $fallback, 'key' => 'k', 'a' => 1, 'b' => null, 'c' => true,
        ]);

        self::assertSame('__defer__', $element->type);
        self::assertSame(['a' => 1, 'c' => true], $element->props['__params']);
        self::assertSame($fallback, $element->props['__fallback']);
        self::assertTrue($element->props['__localCache']);
        self::assertTrue($element->props['__reloadable']);
        self::assertSame(30, $element->props['__localCacheTtl']);
    }

    public function testBuildPlaceholderRejectsANonElementFallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Defer('late')->buildPlaceholder(['fallback' => 'text']);
    }

    public function testBuildPlaceholderRejectsNonScalarProps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Defer('late')->buildPlaceholder(['a' => ['x']]);
    }

    public function testPlaceholderAttributes(): void
    {
        self::assertSame(
            '<div data-rosanna-defer-url="/_defer/late"></div>',
            self::html(static fn() => H::defer('late')),
        );
        self::assertSame(
            '<div data-rosanna-defer-url="/_defer/late?a=1&amp;b=%3Cx%3E&amp;t=1&amp;f=0" data-rosanna-defer-cache data-rosanna-defer-cache-ttl="30" data-rosanna-defer-name="late"><p>wait</p></div>',
            self::html(static fn() => H::defer('late', ['a' => 1, 'b' => '<x>', 't' => true, 'f' => false, 'n' => null], H::p(children: 'wait'), true, true, 30)),
        );
    }

    public function testTtlNeedsLocalCacheToBeEmitted(): void
    {
        $html = self::html(static fn() => H::defer('late', localCacheTtl: 30));
        self::assertStringNotContainsString('cache', $html);
    }

    public function testRendererRefusesBadParams(): void
    {
        foreach ([['a' => ['x']], ['x'], ['' => 'x']] as $params) {
            try {
                self::html(static fn() => H::defer('late', $params));
                self::fail();
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('late', $e->getMessage());
            }
        }
    }
}
