<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\NamespaceContext;

/**
 * §4: 名前解決。
 */
final class NamespaceContextTest extends TestCase
{
    public function testNamespaceOnly(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App\\Ui; return 1;');

        self::assertSame('App\\Ui', $context->getNamespace());
        self::assertSame('App\\Ui\\Foo', $context->resolve('Foo'));
    }

    /** CP-90 */
    public function testUseWinsOverNamespace(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use Lib\\Foo; return 1;');

        self::assertSame('Lib\\Foo', $context->resolve('Foo'));
        self::assertSame('App\\Bar', $context->resolve('Bar'));
    }

    public function testAlias(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use Lib\\Foo as Bar; return 1;');

        self::assertSame('Lib\\Foo', $context->resolve('Bar'));
        self::assertSame('App\\Foo', $context->resolve('Foo'));
    }

    public function testGroupUse(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use Lib\\{Foo, Sub\\Bar as Baz, function qux}; return 1;');

        self::assertSame('Lib\\Foo', $context->resolve('Foo'));
        self::assertSame('Lib\\Sub\\Bar', $context->resolve('Baz'));
        self::assertSame('App\\qux', $context->resolve('qux'));
    }

    public function testNoNamespace(): void
    {
        $context = NamespaceContext::fromSource('<?php return 1;');

        self::assertSame('', $context->getNamespace());
        self::assertSame('Foo', $context->resolve('Foo'));
    }

    /** CP-91 */
    public function testUseFunctionAndConstAreIgnored(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use function Lib\\Foo; use const Lib\\Bar; return 1;');

        self::assertSame('App\\Foo', $context->resolve('Foo'));
        self::assertSame('App\\Bar', $context->resolve('Bar'));
    }

    /** CP-93 */
    public function testOnlyTagsAreRecorded(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use Lib\\Repo; return 1;');

        $context->resolve('Foo');

        self::assertSame('Lib\\Repo', $context->resolveClassName(new Name('Repo')));
        self::assertSame(['App\\Foo'], $context->getResolvedReferences());
    }

    public function testClassNames(): void
    {
        $context = NamespaceContext::fromSource('<?php namespace App; use Lib\\Sub; return 1;');

        self::assertSame('Other\\Repo', $context->resolveClassName(new FullyQualified('Other\\Repo')));
        self::assertSame('Lib\\Sub\\Repo', $context->resolveClassName(new Name('Sub\\Repo')));
        self::assertSame('App\\Repo', $context->resolveClassName(new Name('Repo')));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function runtimeDeclarations(): iterable
    {
        yield 'line comment' => ["<?php\n// @psx-runtime App\\Foo\nreturn 1;", ['App\\Foo']];
        yield 'hash comment' => ["<?php\n# @psx-runtime App\\Foo\nreturn 1;", ['App\\Foo']];
        yield 'block comment' => ["<?php\n/* @psx-runtime App\\Foo */\nreturn 1;", ['App\\Foo']];
        yield 'doc block' => ["<?php\n/**\n * @psx-runtime App\\Foo */\nreturn 1;", ['App\\Foo']];
        yield 'leading backslash' => ["<?php\n// @psx-runtime \\App\\Foo\nreturn 1;", ['App\\Foo']];
        yield 'several' => ["<?php\n// @psx-runtime A\n// @psx-runtime B\nreturn 1;", ['A', 'B']];
        yield 'in a string' => ["<?php\nreturn '// @psx-runtime App\\Foo';", []];
        yield 'not at the start of a line comment' => ["<?php\n// see @psx-runtime App\\Foo\nreturn 1;", []];
    }

    /**
     * CP-94
     *
     * @param list<string> $expected
     */
    #[DataProvider('runtimeDeclarations')]
    public function testRuntimeDeclarations(string $source, array $expected): void
    {
        self::assertSame($expected, NamespaceContext::fromSource($source)->getRuntimeDeclarations());
    }

    public function testMalformedNamespaceIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('namespace');

        NamespaceContext::fromSource("<?php namespace 1App;\nreturn 1;");
    }

    public function testGlobalNamespaceBlockIsNotAnError(): void
    {
        self::assertSame('', NamespaceContext::fromSource('<?php namespace { return 1; }')->getNamespace());
    }

    public function testNamespaceWordInACommentIsNotADeclaration(): void
    {
        $context = NamespaceContext::fromSource("<?php\n// the namespace foo is unused\nreturn 'namespace bar';");

        self::assertSame('', $context->getNamespace());
    }
}
