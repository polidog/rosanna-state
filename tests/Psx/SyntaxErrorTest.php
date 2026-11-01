<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\Compiler;

/**
 * §2 のエラーと非対応の構文、§5 の CP-105〜CP-108。
 */
final class SyntaxErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parseErrors(): iterable
    {
        yield 'mismatched closing tag' => ['<?php return <a></b>;', 'Mismatched closing tag: expected </a>, got </b>'];
        yield 'CP-12 named close of a fragment' => ['<?php return <>x</b>;', 'Expected fragment close `</>`, got `</b>`'];
        yield 'CP-14 case mismatch' => ['<?php return <Div></div>;', 'Mismatched closing tag: expected </Div>, got </div>'];
        yield 'CP-57 uppercase raw close' => ['<?php return <script>a</SCRIPT>;', 'Mismatched closing tag: expected </script>, got </SCRIPT>'];
        yield 'unclosed style' => ['<?php return <style>a { b };', 'Unclosed <style>'];
        yield 'unclosed script' => ['<?php return <script>a;', 'Unclosed <script>'];
        yield 'unterminated attribute string' => ['<?php return <a id="x />;', 'Unterminated attribute string literal'];
        yield 'unclosed brace' => ['<?php return <a>{$x', 'Unclosed brace expression'];
        yield 'CP-23 empty attribute expression' => ['<?php return <a id={} />;', "Attribute 'id' has an empty expression"];
        yield 'CP-23 comment-only attribute expression' => ['<?php return <a id={/* c */} />;', "Attribute 'id' has an empty expression"];
        yield 'missing attribute value' => ['<?php return <a id= />;', "Expected attribute value after '=' for 'id'"];
        yield 'unsupported: attribute spread' => ['<?php return <div {...$a} />;', "Expected '>', got '{'"];
        yield 'unsupported: namespaced attribute' => ['<?php return <svg xlink:href="a" />;', "Expected '>', got ':'"];
        yield 'unsupported: dotted tag' => ['<?php return <Foo.Bar />;', "Expected '>', got '.'"];
        yield 'unsupported: namespaced tag' => ['<?php return <Foo\\Bar />;', "Expected '>', got '\\'"];
        yield 'CP-64 localCache string' => ['<?php return <defer name="a" localCache="true" />;', "<defer> attribute 'localCache' must not be a string"];
        yield 'CP-64 reloadable string' => ['<?php return <defer name="a" reloadable="true" />;', "<defer> attribute 'reloadable' must not be a string"];
        yield 'CP-64 localCacheTtl string' => ['<?php return <defer name="a" localCacheTtl="60"></defer>;', "<defer> attribute 'localCacheTtl' must not be a string"];
        yield 'duplicate defer attribute' => ['<?php return <defer name="a" name="b" />;', "<defer> attribute 'name' is specified more than once"];
    }

    #[DataProvider('parseErrors')]
    public function testParseErrors(string $source, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        new Compiler()->compile($source);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidPhp(): iterable
    {
        yield 'CP-105 child' => ['<?php return <p>{1 +}</p>;', 1];
        yield 'CP-105 attribute' => ['<?php return <p id={1 +} />;', 1];
        yield 'CP-105 statement in a child' => ['<?php return <p>{if ($x) { 1; }}</p>;', 1];
        yield 'CP-105 outside of PSX' => ["<?php\n\nreturn 1 +;\n", 3];
        yield 'CP-105 spread in an attribute' => ['<?php return <p id={...$a} />;', 1];
        yield 'not recognized: space after <' => ['<?php return < div />;', 1];
        yield 'not recognized: cast' => ['<?php return (string) <a/>;', 1];
        yield 'not recognized: comparison' => ['<?php return $x == <b/>;', 1];
        yield 'not recognized: nested comparison' => ["<?php return <p>\n{\$x == <b/>}\n</p>;", 2];
        yield 'CP-108 after a multi-line region' => ["<?php\n\$a = <div>\n  x\n</div>;\nreturn 1 +;\n", 5];
    }

    #[DataProvider('invalidPhp')]
    public function testInvalidPhpIsACompileError(string $source, int $line): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("PSX compile error at line $line: generated PHP is invalid: ");

        new Compiler()->compile($source);
    }

    /** CP-107 */
    public function testCompileErrorShowsSourceLineAndKeepsPrevious(): void
    {
        try {
            new Compiler()->compile("<?php\n\nreturn <p>\n  {1 +}\n</p>;\n");
            self::fail('Expected a compile error');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('PSX compile error at line 4: generated PHP is invalid: ', $e->getMessage());
            self::assertStringEndsWith("\n      {1 +}", $e->getMessage());
            self::assertInstanceOf(\PhpParser\Error::class, $e->getPrevious());
        }
    }

    /** CP-106 */
    /** @return iterable<string, array{string, int}> */
    public static function notASingleExpression(): iterable
    {
        yield 'CP-21 attribute with a second array element' => ['<?php return <div id={1, "x" => 2} />;', 1];
        yield 'CP-21 attribute with a key' => ['<?php return <div id={"a" => 1} />;', 1];
        yield 'CP-30 child with a list' => ['<?php return <div>{1, 2}</div>;', 1];
        yield 'CP-30 child with a trailing comma' => ['<?php return <div>{1,}</div>;', 1];
        yield 'CP-30 child closing the generated array' => ['<?php return <div>{1]); print("ran\n"); f([2}</div>;', 1];
        yield 'CP-30 child closing a parenthesis' => ['<?php return <div>{1); print("ran\n"); (2}</div>;', 1];
        yield 'CP-30 parentheses that only balance outside' => ['<?php return <defer name="a" params={[]) + (1} />;', 1];
        yield 'CP-30 brackets that only balance outside' => ['<?php return <div>{1] + [2}</div>;', 1];
        yield 'CP-73 two spreads' => ['<?php return <div>{...$a, ...$b}</div>;', 1];
        yield 'CP-73 spread that closes the array' => ['<?php return <div>{...$a]); print("ran\n"); f([...$b}</div>;', 1];
        yield 'CP-101 line of the expression' => ["<?php\nreturn <div>\n  <b>x</b>\n  {1, 2}\n</div>;\n", 4];
        yield 'CP-101 line of the attribute' => ["<?php\nreturn <div\n  id={1, 2}\n/>;\n", 3];
    }

    #[DataProvider('notASingleExpression')]
    public function testBraceMustHoldASingleExpression(string $source, int $line): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("PSX compile error at line $line: ");

        new Compiler()->compile($source);
    }

    public function testSingleExpressionErrorShowsTheSourceLine(): void
    {
        try {
            new Compiler()->compile("<?php\nreturn <p>{1, 2}</p>;\n");
            self::fail('Expected a compile error');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('{...} must hold a single expression', $e->getMessage());
            self::assertStringEndsWith("\n    return <p>{1, 2}</p>;", $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function singleExpressions(): iterable
    {
        yield 'call with several arguments' => ['<?php return <p>{f(1, 2)}</p>;'];
        yield 'array' => ['<?php return <p>{[1, 2]}</p>;'];
        yield 'arrow function' => ['<?php return <p>{fn () => [1, 2]}</p>;'];
        yield 'match' => ['<?php return <p>{match ($x) { 1 => 2, default => 3 }}</p>;'];
        yield 'nested tag' => ['<?php return <p>{$x ? <a>{1}</a> : <b id={2} />}</p>;'];
        yield 'operator lowering' => ['<?php return <p>{$x && <b />}</p>;'];
        yield 'spread' => ['<?php return <p>{...$xs}</p>;'];
        yield 'spread next to a sibling' => ['<?php return <p><a />{...$xs}</p>;'];
        yield 'comment after the expression' => ["<?php return <p>{\$x // c\n}</p>;"];
        yield 'comment before the expression' => ['<?php return <p>{/* c */ $x}</p>;'];
        yield 'comment only child' => ['<?php return <p>{/* c */}</p>;'];
        yield 'empty child' => ['<?php return <p>{}</p>;'];
        yield 'multi line' => ["<?php return <p id={f(\n  1,\n  2\n)} />;"];
        yield 'defer attribute' => ['<?php return <defer name="a" params={[\'a\' => 1]} />;'];
    }

    #[DataProvider('singleExpressions')]
    public function testASingleExpressionIsAccepted(string $source): void
    {
        self::assertNotSame('', new Compiler()->compile($source));
    }

    /** CP-106: 列は文字単位、`^` は表示幅に合わせる */
    public function testParseErrorColumnCountsCharactersAndTheCaretUsesTheDisplayWidth(): void
    {
        try {
            new Compiler()->compile("<?php\nreturn <div>日本語<b></c></div>;\n");
            self::fail('Expected a parse error');
        } catch (\RuntimeException $e) {
            $lines = \explode("\n", $e->getMessage());
            self::assertStringStartsWith('PSX parse error at line 2, column 22: ', $lines[0]);
            self::assertSame('    return <div>日本語<b></c></div>;', $lines[1]);
            self::assertSame(\str_repeat(' ', 4 + 24) . '^', $lines[2]);
        }
    }

    /** CP-106: 半角カナは幅 1、不正な UTF-8 でも例外の組み立てで止まらない */
    public function testParseErrorPositionWithOtherEncodings(): void
    {
        foreach (["<?php\nreturn <div>ｱｲ<b></c></div>;\n" => [21, 20], "<?php\nreturn <div>\xFF<b></c></div>;\n" => [20, 19], "<?php\n\treturn <a></b>;\n" => [15, 14]] as $source => [$column, $width]) {
            try {
                new Compiler()->compile($source);
                self::fail('Expected a parse error');
            } catch (\RuntimeException $e) {
                $lines = \explode("\n", $e->getMessage());
                self::assertStringStartsWith("PSX parse error at line 2, column $column: ", $lines[0]);
                self::assertSame(\str_repeat(' ', 4 + $width) . '^', $lines[2]);
            }
        }
    }

    public function testParseErrorFormat(): void
    {
        try {
            new Compiler()->compile("<?php\nreturn <a></b>;\n");
            self::fail('Expected a parse error');
        } catch (\RuntimeException $e) {
            self::assertSame(
                "PSX parse error at line 2, column 14: Mismatched closing tag: expected </a>, got </b>\n"
                . "    return <a></b>;\n"
                . '                 ^',
                $e->getMessage(),
            );
        }
    }

    /**
     * CP-108: usePHP は領域の置き換えで行が詰まり、この 2 つが line 7、line 2 になる。
     *
     * @return iterable<string, array{string, int, int, string}>
     */
    public static function positions(): iterable
    {
        yield 'second region' => [
            "<?php\n\$a = <div>\n  x\n  y\n</div>;\n\n\$b = <p>\n  z\n</q>;\n",
            9,
            4,
            '</q>;',
        ];
        yield 'third region' => [
            "<?php\n\$a = <div>\n</div>;\n\$b = <i>\n\n</i>;\n\$c = <p></q>;\n",
            7,
            12,
            '$c = <p></q>;',
        ];
        yield 'nested tag in an expression' => [
            "<?php\nreturn <ul>\n  {array_map(\n    fn (\$x) => <li>\n      <b>{\$x}</i>\n    </li>,\n    \$xs,\n  )}\n</ul>;\n",
            5,
            17,
            '      <b>{$x}</i>',
        ];
        yield 'nested after a previous sibling' => [
            "<?php\nreturn <div>\n  <p>{\$a}</p>\n\n  {\$b && <em></strong>}\n</div>;\n",
            5,
            22,
            '  {$b && <em></strong>}',
        ];
        yield 'nested twice' => [
            "<?php\nreturn <a>\n  {<b>\n    {<c>\n      {<d></e>}\n    </c>}\n  </b>}\n</a>;\n",
            5,
            14,
            '      {<d></e>}',
        ];
        yield 'after a multi-byte line' => [
            "<?php\n// 日本語\nreturn <a id={}></a>;\n",
            3,
            16,
            'return <a id={}></a>;',
        ];
    }

    #[DataProvider('positions')]
    public function testErrorPositionIsTheRealSourcePosition(string $source, int $line, int $column, string $shown): void
    {
        try {
            new Compiler()->compile($source);
            self::fail('Expected a parse error');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith("PSX parse error at line $line, column $column: ", $e->getMessage());
            $lines = \explode("\n", $e->getMessage());
            self::assertSame('    ' . $shown, $lines[1]);
            self::assertSame('    ' . \str_repeat(' ', $column - 1) . '^', $lines[2]);
        }
    }

    public function testUnclosedBraceAndStringPointAtTheirStart(): void
    {
        foreach ([
            ["<?php\nreturn <a>\n  {\$x\n</a>;\n", 'line 3, column 3: Unclosed brace expression'],
            ["<?php\nreturn <a\n  id=\"x />;\n", 'line 3, column 6: Unterminated attribute string literal'],
        ] as [$source, $expected]) {
            try {
                new Compiler()->compile($source);
                self::fail('Expected a parse error');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }

    /** CP-64: 式で書けば通る */
    public function testDeferAttributeExpressionsAreAccepted(): void
    {
        self::assertStringContainsString(
            'localCache: true, reloadable: false, localCacheTtl: 60',
            new Compiler()->compile('<?php return <defer name="a" localCache={true} reloadable={false} localCacheTtl={60} />;'),
        );
    }

    /** §3 のエラー: タグと判定されなかった `<` は CP-105 に任せる */
    public function testUnrecognizedTagIsNotAParseError(): void
    {
        $this->expectExceptionMessage('PSX compile error');

        new Compiler()->compile('<?php return (int) <a/>;');
    }
}
