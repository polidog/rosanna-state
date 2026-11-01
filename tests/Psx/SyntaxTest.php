<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\Compiler;

/**
 * §2、§6: PSX から生成コードの文字列への変換。
 */
final class SyntaxTest extends TestCase
{
    private const string CE = '\\Polidog\\Rosanna\\Html\\createElement';
    private const string RENDER = '\\Polidog\\Rosanna\\Runtime\\RenderContext::scope()->psx->render';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function generatedCode(): iterable
    {
        $ce = self::CE;
        $render = self::RENDER;

        // §6 の表
        yield 'table: element' => ['<div className="a">x</div>', "$ce('div', ['className' => 'a', 'children' => ['x']])"];
        yield 'table: void' => ['<br />', "$ce('br', [])"];
        yield 'table: fragment' => ['<>x</>', "$ce('Fragment', ['children' => ['x']])"];
        yield 'table: component' => ['<Counter initial={5} />', "$render('App\\\\Counter', ['initial' => 5])"];
        yield 'table: defer' => ['<defer name="a" />', "\\Polidog\\Rosanna\\Html\\H::defer(name: 'a')"];
        yield 'table: &&' => ['<p>{$x && <b/>}</p>', "$ce('p', ['children' => ((\$x) ? ($ce('b', [])) : false)])"];
        yield 'table: ||' => ['<p>{$x || <b/>}</p>', "$ce('p', ['children' => ((\$x) ?: ($ce('b', [])))])"];

        // タグ
        yield 'CP-10 name characters' => ['<my-tag_1 />', "$ce('my-tag_1', [])"];
        yield 'CP-11 uppercase is a component' => ['<Foo />', "$render('App\\\\Foo', [])"];
        yield 'CP-11 lowercase is built in' => ['<foo />', "$ce('foo', [])"];
        yield 'CP-12 empty fragment' => ['<></>', "$ce('Fragment', [])"];
        yield 'CP-13 self-closing equals no children' => ['<div></div>', "$ce('div', [])"];
        yield 'CP-14 whitespace after closing name' => ['<div></div >', "$ce('div', [])"];
        yield 'CP-15 keyword var' => ['<var>x</var>', "$ce('var', ['children' => ['x']])"];
        yield 'CP-15 keyword switch' => ['<switch />', "$ce('switch', [])"];
        yield 'CP-15 svg use' => ['<svg><use href="#a" /></svg>', "$ce('svg', ['children' => [$ce('use', ['href' => '#a'])]])"];

        // 属性
        yield 'CP-20 attribute characters' => ['<a data-x_1="v" />', "$ce('a', ['data-x_1' => 'v'])"];
        yield 'CP-21 single quotes' => ["<a id='v' />", "$ce('a', ['id' => 'v'])"];
        yield 'CP-21 expression' => ['<a id={$v} />', "$ce('a', ['id' => \$v])"];
        yield 'CP-21 omitted value' => ['<a hidden />', "$ce('a', ['hidden' => true])"];
        yield 'CP-21 whitespace around equals' => ['<a id = "v" />', "$ce('a', ['id' => 'v'])"];
        yield 'CP-22 escaped quote' => ['<a id="x\\"y" />', "$ce('a', ['id' => 'x\"y'])"];
        yield 'CP-22 escaped backslash' => ['<a id="x\\\\y" />', "$ce('a', ['id' => 'x\\\\y'])"];
        yield 'CP-22 other escapes stay' => ['<a id="x\\ny" />', "$ce('a', ['id' => 'x\\\\ny'])"];
        yield 'CP-22 entities after escapes' => ['<a id="&quot;\\\\" />', "$ce('a', ['id' => '\"\\\\'])"];
        yield 'CP-24 source order and duplicates' => ['<a b="1" c="2" b="3" />', "$ce('a', ['b' => '1', 'c' => '2', 'b' => '3'])"];
        yield 'CP-25 label for' => ['<label for="a" />', "$ce('label', ['htmlFor' => 'a'])"];
        yield 'CP-25 only on label' => ['<output for="a" />', "$ce('output', ['for' => 'a'])"];
        yield 'CP-25 not on a component' => ['<Foo for="a" />', "$render('App\\\\Foo', ['for' => 'a'])"];
        yield 'CP-26 inline handler reaches the runtime' => ['<a onclick="x()" />', "$ce('a', ['onclick' => 'x()'])"];

        // 子
        yield 'CP-30 mixed children' => ['<p>a<b/>{$c}</p>', "$ce('p', ['children' => ['a', $ce('b', []), \$c]])"];
        yield 'CP-30 tag inside an expression' => ['<p>{f(<b/>)}</p>', "$ce('p', ['children' => f($ce('b', []))])"];
        yield 'CP-31 comment is not a child' => ['<p>{/* c */}a</p>', "$ce('p', ['children' => ['a']])"];
        yield 'CP-31 only a comment' => ['<p>{/* c */}</p>', "$ce('p', [])"];
        yield 'CP-31 whitespace only' => ['<p>{ }</p>', "$ce('p', [])"];
        yield 'CP-31 tag inside a comment' => ['<p>{/* <b>x</b> */}a</p>', "$ce('p', ['children' => ['a']])"];
        yield 'CP-33 brace in string' => ['<p>{"}"}</p>', "$ce('p', ['children' => \"}\"])"];
        yield 'CP-33 brace in single-quoted string' => ["<p>{'{'}</p>", "$ce('p', ['children' => '{'])"];
        yield 'CP-33 brace in block comment' => ['<p>{$a /* } */}</p>', "$ce('p', ['children' => \$a /* } */])"];
        yield 'CP-33 nested braces' => ['<p>{fn() => match(1) { 1 => 2 }}</p>', "$ce('p', ['children' => fn() => match(1) { 1 => 2 }])"];
        yield 'CP-33 attribute syntax is not a comment' => ['<p>{#[A] fn() => 1}</p>', "$ce('p', ['children' => #[A] fn() => 1])"];

        // テキスト
        yield 'CP-40 multi-line trimmed' => ["<p>\n  a\n  b\n</p>", "$ce('p', ['children' => [\n'a b']])\n\n"];
        yield 'CP-40 only whitespace with a newline' => ["<p>\n  \n</p>", "$ce('p', [])\n\n"];
        yield 'CP-41 single line keeps one space' => ['<p>  a   b  </p>', "$ce('p', ['children' => [' a b ']])"];
        yield 'CP-41 space between elements' => ['<p><b/> <i/></p>', "$ce('p', ['children' => [$ce('b', []), ' ', $ce('i', [])]])"];
        yield 'CP-42 closing brace in text' => ['<p>a } b</p>', "$ce('p', ['children' => ['a } b']])"];
        yield 'CP-43 apostrophe' => ["<p>it's</p>", "$ce('p', ['children' => ['it\\'s']])"];
        yield 'CP-43 hash' => ['<p># a</p>', "$ce('p', ['children' => ['# a']])"];
        yield 'CP-43 slashes' => ['<p>http://a</p>', "$ce('p', ['children' => ['http://a']])"];
        yield 'CP-43 inside an expression' => ["<p>{\$x ? <b>it's # //</b> : 1}</p>", "$ce('p', ['children' => \$x ? $ce('b', ['children' => ['it\\'s # //']]) : 1])"];

        // エンティティ
        yield 'CP-50 text' => ['<p>&amp;&lt;&eacute;</p>', "$ce('p', ['children' => ['&<é']])"];
        yield 'CP-50 attribute' => ['<a id="&amp;" />', "$ce('a', ['id' => '&'])"];
        yield 'CP-51 entity spaces survive' => ['<p>a&#32;&#32;b</p>', "$ce('p', ['children' => ['a  b']])"];
        yield 'CP-52 decoded before the runtime check' => ['<a href="java&#115;cript:x" />', "$ce('a', ['href' => 'javascript:x'])"];

        // style と script
        yield 'CP-55 braces stay' => ['<style>a { b: {$c} }</style>', "$ce('style', ['children' => ['a { b: {\$c} }']])"];
        yield 'CP-55 tags and entities stay' => ['<script>a < <b>&amp;</b></script>', "$ce('script', ['children' => ['a < <b>&amp;</b>']])"];
        yield 'CP-55 whitespace stays' => ["<script>\n a;\n\n</script>", "$ce('script', ['children' => ['\n a;\n\n']])"];
        yield 'CP-57 name that only starts like the close' => ['<style></styles</style>', "$ce('style', ['children' => ['</styles']])"];
        yield 'CP-57 close of another element' => ['<script>"</style>"</script>', "$ce('script', ['children' => ['\"</style>\"']])"];
        yield 'CP-56 empty' => ['<style></style>', "$ce('style', [])"];
        yield 'CP-56 self-closing' => ['<script src="a" />', "$ce('script', ['src' => 'a'])"];
        yield 'CP-55 uppercase is a component' => ['<Style>{1}</Style>', "$render('App\\\\Style', ['children' => 1])"];

        // コンポーネント
        yield 'CP-60 props and children' => ['<Foo a="1" b={2}>x<i/></Foo>', "$render('App\\\\Foo', ['a' => '1', 'b' => 2, 'children' => ['x', $ce('i', [])]])"];

        // defer
        yield 'CP-62 all attributes' => [
            '<defer name="a" params={[1]} fallback={<b/>} localCache={true} reloadable localCacheTtl={60} />',
            "\\Polidog\\Rosanna\\Html\\H::defer(name: 'a', params: [1], fallback: $ce('b', []), localCache: true, reloadable: true, localCacheTtl: 60)",
        ];
        yield 'CP-62 empty element' => ['<defer name="a"></defer>', "\\Polidog\\Rosanna\\Html\\H::defer(name: 'a')"];
        yield 'CP-63 without name' => ['<defer />', "$ce('defer', [])"];
        yield 'CP-63 with children' => ['<defer name="a">x</defer>', "$ce('defer', ['name' => 'a', 'children' => ['x']])"];
        yield 'CP-63 unknown attribute' => ['<defer name="a" id="b" />', "$ce('defer', ['name' => 'a', 'id' => 'b'])"];
        yield 'CP-64 does not apply to a plain element' => ['<defer localCache="true" />', "$ce('defer', ['localCache' => 'true'])"];

        // 子の形
        yield 'CP-71 text becomes an array' => ['<p>x</p>', "$ce('p', ['children' => ['x']])"];
        yield 'CP-71 lone expression is passed as is' => ['<ul>{array_map($f, $x)}</ul>', "$ce('ul', ['children' => array_map(\$f, \$x)])"];
        yield 'CP-71 lone expression in a fragment' => ['<>{$x}</>', "$ce('Fragment', ['children' => \$x])"];
        yield 'CP-71 expression with a comment sibling' => ['<p>{/* c */}{$x}</p>', "$ce('p', ['children' => \$x])"];
        yield 'CP-71 element child stays in an array' => ['<p><b/></p>', "$ce('p', ['children' => [$ce('b', [])]])"];
        yield 'CP-72 component lone text' => ['<Foo>x</Foo>', "$render('App\\\\Foo', ['children' => 'x'])"];
        yield 'CP-72 component lone element' => ['<Foo><b/></Foo>', "$render('App\\\\Foo', ['children' => $ce('b', [])])"];
        yield 'CP-72 component lone expression' => ['<Foo>{$x}</Foo>', "$render('App\\\\Foo', ['children' => \$x])"];
        yield 'CP-34/CP-73 built-in' => ['<div>{...$x}</div>', "$ce('div', ['children' => [...\$x]])"];
        yield 'CP-73 fragment' => ['<>{...$x}</>', "$ce('Fragment', ['children' => [...\$x]])"];
        yield 'CP-73 component' => ['<Foo>{...$x}</Foo>', "$render('App\\\\Foo', ['children' => [...\$x]])"];
        yield 'CP-73 comment before the spread' => ['<div>{/* c */ ...$x}</div>', "$ce('div', ['children' => [/* c */ ...\$x]])"];
        yield 'CP-73 whitespace before the spread' => ['<div>{  ...$x  }</div>', "$ce('div', ['children' => [...\$x]])"];
        yield 'CP-34 spread among several children' => ['<div>a{...$x}</div>', "$ce('div', ['children' => ['a', ...\$x]])"];
        yield 'CP-73 operator inside a spread' => ['<div>{...$x && <b/>}</div>', "$ce('div', ['children' => [...((\$x) ? ($ce('b', [])) : false)]])"];
        yield 'CP-75 attribute value escaping' => ['<a id="\'\\\\" />', "$ce('a', ['id' => '\\'\\\\'])"];

        // 非対応に見えるが通るもの
        yield 'CP-76 numeric attributes stay strings' => ['<input width="100" step="0.5" disabled="disabled" />', "$ce('input', ['width' => '100', 'step' => '0.5', 'disabled' => 'disabled'])"];
        yield 'CP-76 numeric expressions' => ['<p>{0}</p>', "$ce('p', ['children' => 0])"];
    }

    #[DataProvider('generatedCode')]
    public function testGeneratedCode(string $psx, string $expected): void
    {
        $prefix = '<?php namespace App; return ';
        $compiled = new Compiler()->compile($prefix . $psx . ";\n");

        self::assertSame($prefix . $expected . ";\n", $compiled);
    }

    /** CP-32 */
    public function testTrailingLineCommentDoesNotSwallowGeneratedCode(): void
    {
        foreach (['//', '#'] as $comment) {
            $compiled = new Compiler()->compile("<?php return <p id={\$a $comment c\n}>{\$b $comment d\n}</p>;");

            self::assertStringContainsString("\$a $comment c\n", $compiled);
            self::assertStringContainsString("\$b $comment d\n", $compiled);
            self::assertStringEndsWith('])' . ';', $compiled);
        }
    }

    /** CP-74 */
    public function testGeneratedCodeNeverUsesGetApp(): void
    {
        $compiled = new Compiler()->compile('<?php return <Foo><Bar /><div>{<Baz />}</div></Foo>;');

        self::assertStringNotContainsString('getApp', $compiled);
        self::assertSame(3, \substr_count($compiled, 'RenderContext::scope()->psx->render('));
    }

    /** CP-70 */
    public function testBuiltInTagsDoNotGoThroughH(): void
    {
        $compiled = new Compiler()->compile('<?php return <div><span>x</span></div>;');

        self::assertStringNotContainsString('H::', $compiled);
        self::assertStringNotContainsString('use ', $compiled);
    }

    /** CP-55: `style` のテキストは CP-50 の対象外だが、シングルクォートはエスケープする */
    public function testRawTextEscapesForPhp(): void
    {
        $compiled = new Compiler()->compile("<?php return <style>a::after{content:'\\201C'}</style>;");

        self::assertStringContainsString("'a::after{content:\\'\\\\201C\\'}'", $compiled);
    }
}
