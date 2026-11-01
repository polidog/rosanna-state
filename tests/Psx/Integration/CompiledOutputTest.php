<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

/**
 * §2、§6: コンパイル結果を本物のランタイムで描画した HTML。
 */
#[Group('integration')]
final class CompiledOutputTest extends TestCase
{
    use RendersPsx;

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function rendered(): iterable
    {
        // タグと属性
        yield 'CP-13' => ['<div />', [], '<div></div>'];
        yield 'CP-12 fragment' => ['<div><>a<b>c</b></></div>', [], '<div>a<b>c</b></div>'];
        yield 'CP-15 var' => ['<var>x</var>', [], '<var>x</var>'];
        yield 'CP-15 svg use' => ['<svg><use href="#a" /></svg>', [], '<svg><use href="#a"></use></svg>'];
        yield 'CP-21 omitted value' => ['<input disabled />', [], '<input disabled />'];
        yield 'CP-24 later wins' => ['<a id="1" id="2">x</a>', [], '<a id="2">x</a>'];
        yield 'CP-25 label' => ['<label for="a">x</label>', [], '<label for="a">x</label>'];
        yield 'className' => ['<div className="a">x</div>', [], '<div class="a">x</div>'];

        // 子
        yield 'CP-30 tag in a call' => ['<ul>{array_map(fn ($x) => <li>{$x}</li>, ["a", "b"])}</ul>', [], '<ul><li>a</li><li>b</li></ul>'];
        yield 'CP-31' => ['<p>{/* c */}a</p>', [], '<p>a</p>'];
        yield 'CP-32' => ["<p>{1 // c\n}</p>", [], '<p>1</p>'];
        yield 'CP-41' => ['<p>  a   b  </p>', [], '<p> a b </p>'];
        yield 'CP-42' => ['<p>a } b</p>', [], '<p>a } b</p>'];
        yield 'CP-43' => ["<p>it's # // x</p>", [], '<p>it&#039;s # // x</p>'];
        yield 'nested apostrophe' => ["<p>{true && <b>it's</b>}</p>", [], '<p><b>it&#039;s</b></p>'];

        // エンティティ
        yield 'CP-50 not double-escaped' => ['<p title="a&amp;b">a&amp;b &lt;i&gt;</p>', [], '<p title="a&amp;b">a&amp;b &lt;i&gt;</p>'];
        yield 'CP-51' => ['<p>a&#32;&#32;b</p>', [], '<p>a  b</p>'];

        // style と script
        yield 'CP-55 style' => ['<style>a > b { c: "d" }</style>', [], '<style>a > b { c: "d" }</style>'];
        yield 'CP-55 script' => ['<script>if (a < b) { c("&amp;"); }</script>', [], '<script>if (a < b) { c("&amp;"); }</script>'];
        yield 'CP-56 empty' => ['<style></style>', [], '<style></style>'];

        // 演算子 (usePHP の CompiledOutputTest と同じ範囲)
        yield '&& true' => ['<p>{$p["a"] && <b>x</b>}</p>', ['a' => true], '<p><b>x</b></p>'];
        yield '&& false' => ['<p>{$p["a"] && <b>x</b>}</p>', ['a' => false], '<p></p>'];
        yield 'CP-67 && zero' => ['<p>{$p["a"] && <b>x</b>}</p>', ['a' => 0], '<p></p>'];
        yield 'literal' => ['<p>{0 && <b>x</b>}</p>', [], '<p></p>'];
        yield '|| truthy' => ['<p>{$p["a"] || <b>x</b>}</p>', ['a' => 'v'], '<p>v</p>'];
        yield '|| falsy' => ['<p>{$p["a"] || <b>x</b>}</p>', ['a' => ''], '<p><b>x</b></p>'];
        yield '?? value' => ['<p>{$p["x"] ?? <b>d</b>}</p>', ['x' => 'v'], '<p>v</p>'];
        yield '?? no value' => ['<p>{$p["x"] ?? <b>d</b>}</p>', [], '<p><b>d</b></p>'];
        yield '?:' => ['<p>{$p["x"] ?: <b>d</b>}</p>', ['x' => ''], '<p><b>d</b></p>'];
        yield 'ternary' => ['<p>{$p["x"] ? <b>y</b> : <i>n</i>}</p>', ['x' => false], '<p><i>n</i></p>'];
        yield 'and' => ['<p>{$p["a"] and <b>x</b>}</p>', ['a' => true], '<p><b>x</b></p>'];
        yield 'or' => ['<p>{$p["a"] or <b>x</b>}</p>', ['a' => false], '<p><b>x</b></p>'];
        yield 'chained' => ['<p>{$p["a"] && $p["b"] && <b>x</b>}</p>', ['a' => 1, 'b' => 1], '<p><b>x</b></p>'];
        yield '&& then ||' => ['<p>{$p["a"] && <b>x</b> || <i>y</i>}</p>', ['a' => false], '<p><i>y</i></p>'];
        yield '|| then &&' => ['<p>{$p["a"] || $p["b"] && <b>x</b>}</p>', ['a' => 0, 'b' => 1], '<p><b>x</b></p>'];
        yield 'parens' => ['<p>{($p["a"]) && (<b>x</b>)}</p>', ['a' => 1], '<p><b>x</b></p>'];
        yield 'ternary right' => ['<p>{$p["a"] && ($p["b"] ? <b>x</b> : null)}</p>', ['a' => 1, 'b' => 1], '<p><b>x</b></p>'];
        yield 'ternary left of and' => ['<p>{$p["a"] ? 1 : 0 and <b>x</b>}</p>', ['a' => 1], '<p><b>x</b></p>'];
        yield 'ternary right of or' => ['<p>{$p["a"] or $p["b"] ? <b>x</b> : <i>y</i>}</p>', ['a' => 0, 'b' => 0], '<p><i>y</i></p>'];
        yield 'nested' => ['<p>{$p["a"] && <b>{$p["a"] && <i>y</i>}</b>}</p>', ['a' => 1], '<p><b><i>y</i></b></p>'];
        yield 'in array_map' => ['<ul>{$p["a"] && array_map(fn ($x) => <li>{$x}</li>, ["k"])}</ul>', ['a' => 1], '<ul><li>k</li></ul>'];
        yield 'comment before the operator' => ["<p>{\$p['a'] // c\n && <b>x</b>}</p>", ['a' => 1], '<p><b>x</b></p>'];
        yield 'after return position' => ['$p["a"] && <b>x</b>', ['a' => 1], '<b>x</b>'];

        // CP-71
        yield 'CP-71 list from an expression' => ['<div>{$p["l"]}</div>', ['l' => ['a', 'b']], '<div>ab</div>'];

        // CP-73 (#119)
        yield 'CP-73 built-in' => ['<div>{...$p["l"]}</div>', ['l' => ['a', 'b']], '<div>ab</div>'];
        yield 'CP-73 fragment' => ['<div><>{...$p["l"]}</></div>', ['l' => ['a', 'b']], '<div>ab</div>'];
        yield 'CP-73 comment' => ['<div>{/* c */ ...$p["l"]}</div>', ['l' => ['a', 'b']], '<div>ab</div>'];
        yield 'CP-34 among others' => ['<div>x{...$p["l"]}y</div>', ['l' => ['a', 'b']], '<div>xaby</div>'];
        yield 'CP-73 elements' => ['<ul>{...array_map(fn ($x) => <li>{$x}</li>, $p["l"])}</ul>', ['l' => [1, 2]], '<ul><li>1</li><li>2</li></ul>'];

        // CP-76 (#118)
        yield 'CP-76 zero' => ['<p>{0}</p>', [], '<p>0</p>'];
        yield 'CP-76 int' => ['<p>{42}</p>', [], '<p>42</p>'];
        yield 'CP-76 width' => ['<img width="100" />', [], '<img width="100" />'];
        yield 'CP-76 step' => ['<input step="0.5" />', [], '<input step="0.5" />'];
        yield 'CP-76 disabled' => ['<input disabled="disabled" />', [], '<input disabled="disabled" />'];
        yield 'CP-76 int attribute' => ['<img width={100} />', [], '<img width="100" />'];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('rendered')]
    public function testRenderedHtml(string $psx, array $props, string $expected): void
    {
        self::assertSame($expected, $this->html($psx, $props));
    }

    /**
     * CP-52: エンコードした `javascript:` は、デコードの後もランタイムで遮断される。
     *
     * @return iterable<string, array{string}>
     */
    public static function encodedJavascriptUrls(): iterable
    {
        yield 'numeric' => ['<a href="java&#115;cript:alert(1)">x</a>'];
        yield 'hex' => ['<a href="java&#x73;cript:alert(1)">x</a>'];
        yield 'named colon' => ['<a href="javascript&colon;alert(1)">x</a>'];
        yield 'tab' => ['<a href="java&Tab;script:alert(1)">x</a>'];
        yield 'newline' => ['<a href="java&#10;script:alert(1)">x</a>'];
    }

    #[DataProvider('encodedJavascriptUrls')]
    public function testEncodedJavascriptUrlIsBlocked(string $psx): void
    {
        $html = $this->html($psx);

        self::assertStringNotContainsStringIgnoringCase('javascript', \str_replace(["\t", "\n", '&#10;', '&#9;'], '', $html));
        self::assertStringContainsString('x', $html);
    }

    /** CP-26: 属性名の検証とインラインの `on*` の拒否はランタイムが行う */
    public function testInlineHandlerIsRejectedByTheRuntime(): void
    {
        try {
            $html = $this->html('<a onclick="alert(1)">x</a>');
        } catch (\RuntimeException|\InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::assertStringNotContainsStringIgnoringCase('onclick', $html);
    }

    /** CP-26 */
    public function testEntityDecodedAttributeValueIsEscapedOnOutput(): void
    {
        self::assertStringContainsString('&quot;', $this->html('<a title="&quot;&gt;&lt;script&gt;">x</a>'));
        self::assertStringNotContainsString('<script', $this->html('<a title="&quot;&gt;&lt;script&gt;">x</a>'));
    }

    /**
     * §6: `createElement()` の結果が、同じ意味の `H::xxx()` の結果と一致する。
     *
     * @return iterable<string, array{string, \Closure(): Element}>
     */
    public static function sameAsH(): iterable
    {
        yield 'div' => ['<div className="a" id="b">x</div>', static fn(): Element => H::div(className: 'a', id: 'b', children: ['x'])];
        yield 'nested' => ['<ul><li>a</li></ul>', static fn(): Element => H::ul(children: [H::li(children: ['a'])])];
        yield 'void' => ['<br />', static fn(): Element => H::br()];
        yield 'label' => ['<label for="a">x</label>', static fn(): Element => H::label(htmlFor: 'a', children: ['x'])];
        yield 'attribute with a dash' => ['<button aria-label="c">x</button>', static fn(): Element => H::button(...['aria-label' => 'c'], children: ['x'])];
        yield 'fragment' => ['<>a</>', static fn(): Element => H::Fragment(['a'])];
    }

    /**
     * @param \Closure(): Element $expected
     */
    #[DataProvider('sameAsH')]
    public function testCreateElementMatchesH(string $psx, \Closure $expected): void
    {
        self::assertEquals($expected(), $this->element($psx));
    }

    /** §6 の表 */
    public function testDeferPlaceholderMatchesH(): void
    {
        self::assertEquals(H::defer(name: 'a'), $this->element('<defer name="a" />'));
    }

    /** CP-78: 式で書いた defer の属性は strict_types の下でも通る */
    public function testDeferWithTypedExpressionsUnderStrictTypes(): void
    {
        self::assertEquals(
            H::defer(name: 'a', params: ['x' => 1], localCache: true, reloadable: true, localCacheTtl: 60),
            $this->element('<defer name="a" params={["x" => 1]} localCache={true} reloadable localCacheTtl={60} />'),
        );
    }

    /** CP-63 */
    public function testOtherDeferIsAPlainElement(): void
    {
        self::assertSame('<defer id="a"></defer>', $this->html('<defer id="a" />'));
    }

    /** CP-71: `{array_map(...)}` の結果が入れ子にならない */
    public function testLoneExpressionListIsNotNested(): void
    {
        $element = $this->element('<ul>{array_map(fn ($x) => <li>{$x}</li>, ["a", "b"])}</ul>');

        self::assertInstanceOf(Element::class, $element);
        self::assertCount(2, $element->children);
        self::assertInstanceOf(Element::class, $element->children[0]);
        self::assertSame('li', $element->children[0]->type);
    }

    /** CP-71: テキストだけの子は配列になる */
    public function testTextChildBecomesAList(): void
    {
        $element = $this->element('<p>x</p>');

        self::assertInstanceOf(Element::class, $element);
        self::assertSame(['x'], $element->children);
    }

    /** CP-55 */
    public function testRawContentIsASingleChild(): void
    {
        $element = $this->element("<script>\n a { b }\n</script>");

        self::assertInstanceOf(Element::class, $element);
        self::assertSame(["\n a { b }\n"], $element->children);
    }

    /** §5 のテスト: 本物のランタイムで実行時エラーの行を確かめる */
    public function testThrowableLineMatchesTheSource(): void
    {
        $file = $this->workDir() . '/line.php';
        \file_put_contents($file, new \Polidog\Rosanna\Psx\Compiler()->compile(
            "<?php\nreturn fn (array \$p) => <div>\n  <span>a</span>\n  {/* c\n  */}\n  <b title={\$p['f']()}>\n    x\n  </b>\n</div>;\n",
        ));
        $component = require $file;
        \assert($component instanceof \Closure);

        try {
            $component(['f' => static function (): void {
                throw new \DomainException('boom');
            }]);
            self::fail('Expected the exception');
        } catch (\DomainException $e) {
            $frame = \array_find($e->getTrace(), static fn(array $f): bool => ($f['file'] ?? '') === $file);
            self::assertIsArray($frame);
            self::assertSame(6, $frame['line'] ?? null);
        }
    }
}
