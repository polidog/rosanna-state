<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\Compiler;
use Polidog\Rosanna\Tests\Psx\Support\StubRuntime;

/**
 * §5: 行番号、検証、参照の記録。
 */
final class CompilerTest extends TestCase
{
    /** CP-103 */
    public function testSourceWithoutRegionsIsReturnedAsIs(): void
    {
        $source = "<?php\n\n// comment\nreturn 1   +   2 ; \n";

        self::assertSame($source, new Compiler()->compile($source));
    }

    /** CP-100: PSX の外の PHP はそのまま写す */
    public function testCodeOutsideRegionsIsCopied(): void
    {
        $compiled = new Compiler()->compile("<?php\n  \$a  =  1 ; // keep\n\$b = <br />;\n\t/* keep */ return  \$b ;\n");

        self::assertStringContainsString("  \$a  =  1 ; // keep\n", $compiled);
        self::assertStringContainsString("\t/* keep */ return  \$b ;\n", $compiled);
    }

    /** CP-104 */
    public function testStrictTypesIsNeitherAddedNorRemoved(): void
    {
        self::assertStringNotContainsString('strict_types', new Compiler()->compile('<?php return <a />;'));

        $withDeclare = "<?php\ndeclare(strict_types=1);\nreturn <a />;";
        self::assertStringStartsWith("<?php\ndeclare(strict_types=1);\nreturn ", new Compiler()->compile($withDeclare));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linePreserving(): iterable
    {
        yield 'multi-line markup' => ["<?php\n\$a = <div>\n  <p>\n    x\n  </p>\n\n  <b />\n</div>;\n"];
        yield 'comment child' => ["<?php\n\$a = <div>\n  {/* a\n     b\n     c */}\n  <p />\n</div>;\n"];
        yield 'style' => ["<?php\n\$a = <style>\n  a { b: c }\n\n</style>;\n"];
        yield 'operator' => ["<?php\n\$a = <div>\n  {\$b\n    && <b>\n      x\n    </b>}\n</div>;\n"];
        yield 'attributes' => ["<?php\n\$a = <div\n  id=\"a\"\n  title={\n    \$b\n  }\n  hidden\n/>;\n"];
        yield 'multi-line attribute string' => ["<?php\n\$a = <div title=\"a\n  b\n  c\"\n/>;\n"];
        yield 'entity newline' => ["<?php\n\$a = <div title=\"a&#10;&#10;b\">c&#10;d</div>;\n"];
        yield 'fragment' => ["<?php\n\$a = <>\n  x\n\n</>;\n"];
        yield 'component' => ["<?php\n\$a = <Foo\n  a=\"1\"\n>\n  <b />\n</Foo>;\n"];
        yield 'defer' => ["<?php\n\$a = <defer\n  name=\"a\"\n  params={[\n    1,\n  ]}\n/>;\n"];
        yield 'array_map' => ["<?php\n\$a = <ul>\n  {array_map(\n    fn (\$x) => <li>\n      {\$x}\n    </li>,\n    \$xs,\n  )}\n</ul>;\n"];
        yield 'line comment expression' => ["<?php\n\$a = <p>{\$x // c\n}</p>;\n"];
    }

    /**
     * CP-101、CP-102: 領域の後に置いた `throw` の行が保たれる。
     */
    #[DataProvider('linePreserving')]
    public function testLinesAfterARegionAreKept(string $source): void
    {
        $lines = \substr_count($source, "\n");
        $source .= "throw new \\Exception('marker');\n";

        $compiled = new Compiler()->compile($source);

        self::assertSame(\substr_count($source, "\n"), \substr_count($compiled, "\n"));
        self::assertStringContainsString('marker', \explode("\n", $compiled)[$lines]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function runtimeErrors(): iterable
    {
        yield 'after a region' => ["<?php\n\$a = <div>\n  x\n</div>;\nthrow new \\Exception('e');\n", 5];
        yield 'after a second region' => ["<?php\n\$a = <p>\n</p>;\n\$b = <i>\n\n</i>;\nthrow new \\Exception('e');\n", 7];
        yield 'expression child' => ["<?php\nreturn <div>\n  <p>a</p>\n  {boom()}\n</div>;\n", 4];
        yield 'expression child on its own lines' => ["<?php\nreturn <div>\n  {\n\n    boom()\n  }\n</div>;\n", 5];
        yield 'attribute on a later line' => ["<?php\nreturn <div\n  id=\"a\"\n  title={boom()}\n/>;\n", 4];
        yield 'second attribute expression lines' => ["<?php\nreturn <div\n  a={1}\n\n  b={\n    boom()\n  }\n/>;\n", 6];
        yield 'nested expression' => ["<?php\nreturn <ul>\n  {array_map(\n    fn (\$x) => <li>\n      {boom()}\n    </li>,\n    [1],\n  )}\n</ul>;\n", 5];
        yield 'after a comment child' => ["<?php\nreturn <div>\n  {/* a\n     b */}\n  {boom()}\n</div>;\n", 5];
        yield 'after style' => ["<?php\nreturn <div>\n  <style>\n    a { b: c }\n  </style>\n  {boom()}\n</div>;\n", 6];
        yield 'operator right side' => ["<?php\nreturn <div>\n  {true\n    && <b>\n      {boom()}\n    </b>}\n</div>;\n", 5];
        yield 'operator left side' => ["<?php\nreturn <div>\n  {\n    boom()\n    && <b />}\n</div>;\n", 4];
        yield 'component prop' => ["<?php\nreturn <Foo\n  a=\"1\"\n  b={boom()}\n/>;\n", 4];
        yield 'component child' => ["<?php\nreturn <Foo>\n  <p />\n  {boom()}\n</Foo>;\n", 4];
        yield 'defer attribute' => ["<?php\nreturn <defer\n  name=\"a\"\n  params={boom()}\n/>;\n", 4];
        yield 'spread' => ["<?php\nreturn <div>\n  {\n    ...boom()\n  }\n</div>;\n", 4];
        yield 'after an entity newline' => ["<?php\nreturn <div title=\"a&#10;b\">\n  {boom()}\n</div>;\n", 3];
        yield 'after a multi-line attribute string' => ["<?php\nreturn <div title=\"a\nb\"\n  id={boom()}\n/>;\n", 4];
    }

    /**
     * §5 のテスト: PSX の中の実行時エラーの行が、ソースの行と一致する。
     */
    #[DataProvider('runtimeErrors')]
    public function testThrowableLineMatchesTheSource(string $source, int $line): void
    {
        $source = \str_replace('boom()', '(function () { throw new \\Exception(\'boom\'); })()', $source);

        $result = StubRuntime::evaluate(new Compiler()->compile($source));

        self::assertSame(\Exception::class, $result['thrown'] ?? null, \json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame($line, $result['line']);
    }

    /** CP-100: createElement の呼び出しの行もタグの行になる */
    public function testCallLineIsTheTagLine(): void
    {
        $compiled = new Compiler()->compile("<?php\nreturn <div>\n\n  <span />\n  <Foo />\n</div>;\n");
        $lines = \explode("\n", $compiled);

        self::assertStringContainsString("createElement('div'", $lines[1]);
        self::assertStringContainsString("createElement('span'", $lines[3]);
        self::assertStringContainsString("render('Foo'", $lines[4]);
    }

    /** CP-102: デコードした改行で行が増えず、値は変わらない */
    public function testDecodedNewlinesKeepTheirValue(): void
    {
        $result = StubRuntime::evaluate(new Compiler()->compile('<?php return <div title="a&#10;b">c&#10;d</div>;'));

        self::assertSame(['title' => "a\nb", 'children' => ["c\nd"]], $result['value']['params'] ?? null);
    }

    public function testReferencesIncludeNestedTags(): void
    {
        $compiler = new Compiler();
        $compiler->compile('<?php namespace App; use Lib\\Baz; return <Foo a={<Bar />}><div>{array_map(fn ($x) => <Baz />, [])}</div></Foo>;');

        self::assertSame(['App\\Foo', 'App\\Bar', 'Lib\\Baz'], $compiler->getLastReferences());
    }

    public function testReferencesAreUnique(): void
    {
        $compiler = new Compiler();
        $compiler->compile('<?php return <><Foo /><Foo /></>;');

        self::assertSame(['Foo'], $compiler->getLastReferences());
    }

    public function testReferencesAreResetOnEachCall(): void
    {
        $compiler = new Compiler();
        $compiler->compile('<?php return <Foo />;');
        $compiler->compile('<?php return <Bar />;');
        self::assertSame(['Bar'], $compiler->getLastReferences());

        $compiler->compile('<?php return 1;');
        self::assertSame([], $compiler->getLastReferences());

        $compiler->compile('<?php return <Foo />;');
        try {
            $compiler->compile('<?php return <a></b>;');
            self::fail('Expected a parse error');
        } catch (\RuntimeException) {
            self::assertSame([], $compiler->getLastReferences());
        }
    }

    public function testNestingDepthDoesNotExplode(): void
    {
        $psx = '<a />';
        for ($i = 0; $i < 12; $i++) {
            $psx = "<div>{\$x && $psx}</div>";
        }

        $start = \microtime(true);
        new Compiler()->compile("<?php return $psx;");

        self::assertLessThan(5.0, \microtime(true) - $start);
    }

    /** CP-100: プレースホルダは位置で扱う。同じ文字列を含むソースを壊さない */
    #[\PHPUnit\Framework\Attributes\DataProvider('placeholderLookalikes')]
    public function testPlaceholderTextInTheSourceIsLeftAlone(string $source, string $expected): void
    {
        self::assertSame($expected, new Compiler()->compile($source));
    }

    /** @return iterable<string, array{string, string}> */
    public static function placeholderLookalikes(): iterable
    {
        $call = '\Polidog\Rosanna\Psx\Internal\__psx_region_0__()';
        $a = "\\Polidog\\Rosanna\\Html\\createElement('a', [])";

        yield 'single quoted string' => ["<?php\n\$s = '$call'; return [<a/>, \$s];\n", "<?php\n\$s = '$call'; return [$a, \$s];\n"];
        yield 'double quoted string' => ["<?php\nreturn [\"\\\\Polidog\", <a/>, '$call'];\n", "<?php\nreturn [\"\\\\Polidog\", $a, '$call'];\n"];
        yield 'line comment before the region' => ["<?php\n// $call\nreturn <a/>;\n", "<?php\n// $call\nreturn $a;\n"];
        yield 'block comment after the region' => ["<?php\nreturn <a/>; /* $call */\n", "<?php\nreturn $a; /* $call */\n"];
        yield 'heredoc' => ["<?php\n\$s = <<<T\n$call\nT;\nreturn <a/>;\n", "<?php\n\$s = <<<T\n$call\nT;\nreturn $a;\n"];
        yield 'real call with the number of a region' => ["<?php\nreturn [$call, <a/>];\n", "<?php\nreturn [$call, $a];\n"];
        yield 'real call with no region at all' => ["<?php\nreturn $call;\n", "<?php\nreturn $call;\n"];
        yield 'inside an expression' => ["<?php\nreturn <b>{['$call', <a/>]}</b>;\n", "<?php\nreturn \\Polidog\\Rosanna\\Html\\createElement('b', ['children' => ['$call', $a]]);\n"];
    }

    /** CP-65: 利用者が呼ぶ同じ形の関数は領域ではないので、右辺にあっても `&&` を書き換えない */
    public function testALookalikeCallIsNotTreatedAsATagByTheLowering(): void
    {
        $call = '\Polidog\Rosanna\Psx\Internal\__psx_region_1__()';
        $output = new Compiler()->compile("<?php\nreturn [\$x && $call, \$x && <a/>];\n");

        self::assertStringContainsString("\$x && $call", $output);
        self::assertStringContainsString('(($x) ? (', $output);
    }

    /** CP-65、CP-101: 書き換えで位置がずれても領域の順番と行を保つ */
    public function testLoweringKeepsTheRegionsInPlace(): void
    {
        $output = new Compiler()->compile("<?php\nreturn \$x && <a/>\n  || <b/>;\n");

        self::assertSame(3, \substr_count($output, "\n"));
        self::assertLessThan(\strpos($output, "'b'"), \strpos($output, "'a'"));
    }
}
