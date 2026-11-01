<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\Compiler;
use Polidog\Rosanna\Psx\PsxPreProcessor;

/**
 * §3: PSX の領域の検出。
 */
final class PreProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notPsx(): iterable
    {
        yield 'variables' => ['<?php return $a < $b;'];
        yield 'numbers' => ['<?php return 1 < 2;'];
        yield 'less-than a name' => ['<?php return $a <b;'];
        yield 'heredoc' => ["<?php return <<<EOT\n<div>\nEOT;\n"];
        yield 'nowdoc' => ["<?php return <<<'EOT'\n<div>\nEOT;\n"];
        yield 'double-quoted string' => ['<?php return "<div>";'];
        yield 'single-quoted string' => ["<?php return '<div>';"];
        yield 'line comment' => ["<?php // <div>\nreturn 1;"];
        yield 'hash comment' => ["<?php # <div>\nreturn 1;"];
        yield 'block comment' => ['<?php /* <div> */ return 1;'];
        yield 'not-equal between values' => ['<?php return 1 <> 2;'];
        yield 'after a closing paren' => ['<?php return f() <a;'];
        yield 'after a closing bracket' => ['<?php return $a[0] <b;'];
        yield 'inline HTML' => ["<div>\n<?php return 1;"];
    }

    /** CP-80 */
    #[DataProvider('notPsx')]
    public function testNotARegion(string $source): void
    {
        $code = new PsxPreProcessor()->process($source);

        self::assertSame([], $code->regions);
        self::assertSame($source, $code->code);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function regions(): iterable
    {
        yield 'return' => ['<?php return <a />;'];
        yield 'echo' => ['<?php echo <a />;'];
        yield 'print' => ['<?php print <a />;'];
        yield 'open tag' => ['<?php <a />;'];
        yield 'open tag with echo' => ['<?= <a /> ?>'];
        yield 'assignment' => ['<?php $x = <a />;'];
        yield 'argument' => ['<?php f(<a />);'];
        yield 'second argument' => ['<?php f(1, <a />);'];
        yield 'array element' => ['<?php $x = [<a />];'];
        yield 'keyed array element' => ["<?php \$x = ['k' => <a />];"];
        yield 'ternary then' => ['<?php $x = $y ? <a /> : 1;'];
        yield 'ternary else' => ['<?php $x = $y ? 1 : <a />;'];
        yield 'elvis' => ['<?php $x = $y ?: <a />;'];
        yield 'coalesce' => ['<?php $x = $y ?? <a />;'];
        yield 'coalesce assign' => ['<?php $x ??= <a />;'];
        yield 'match arm' => ['<?php $x = match($y) { 1 => <a /> };'];
        yield 'arrow function' => ['<?php $x = fn() => <a />;'];
        yield 'after fn' => ['<?php $x = fn <a />;'];
        yield 'after ->' => ['<?php $x-><a />;'];
        yield 'after ?->' => ['<?php $x?-><a />;'];
        yield 'yield' => ['<?php function g() { yield <a />; }'];
        yield 'yield from' => ['<?php function g() { yield from <a />; }'];
        yield '&&' => ['<?php $x && <a />;'];
        yield '||' => ['<?php $x || <a />;'];
        yield 'and' => ['<?php $x and <a />;'];
        yield 'or' => ['<?php $x or <a />;'];
        yield 'xor' => ['<?php $x xor <a />;'];
        yield 'brace' => ['<?php { <a />; }'];
        yield 'semicolon' => ['<?php 1; <a />;'];
        yield 'not' => ['<?php !<a />;'];
        yield 'bit and' => ['<?php $x & <a />;'];
        yield 'bit or' => ['<?php $x | <a />;'];
        yield 'bit xor' => ['<?php $x ^ <a />;'];
        yield 'plus' => ['<?php $x + <a />;'];
        yield 'minus' => ['<?php $x - <a />;'];
        yield 'times' => ['<?php $x * <a />;'];
        yield 'divide' => ['<?php $x / <a />;'];
        yield 'modulo' => ['<?php $x % <a />;'];
        yield 'concat' => ['<?php $x . <a />;'];
        yield 'whitespace does not change the state' => ["<?php return\n   <a />;"];
        yield 'comment does not change the state' => ['<?php return /* c */ <a />;'];
        yield 'keyword tag' => ['<?php return <use />;'];
        yield 'underscore tag' => ['<?php return <_a />;'];
    }

    /** CP-81、CP-82 */
    #[DataProvider('regions')]
    public function testRegion(string $source): void
    {
        $code = new PsxPreProcessor()->process($source);

        self::assertCount(1, $code->regions);
        self::assertStringContainsString(PsxPreProcessor::placeholder(0), $code->code);
    }

    /** CP-81 */
    public function testFragmentOnlyAtExpressionStart(): void
    {
        self::assertCount(1, new PsxPreProcessor()->process('<?php return <>x</>;')->regions);
    }

    /** CP-84 */
    public function testPlaceholderName(): void
    {
        self::assertSame('\\Polidog\\Rosanna\\Psx\\Internal\\__psx_region_2__()', PsxPreProcessor::placeholder(2));

        $code = new PsxPreProcessor()->process('<?php $a = <a />; $b = <b />;');

        self::assertSame(
            '<?php $a = \\Polidog\\Rosanna\\Psx\\Internal\\__psx_region_0__(); $b = \\Polidog\\Rosanna\\Psx\\Internal\\__psx_region_1__();',
            $code->code,
        );
    }

    /** CP-83: 奇数個のアポストロフィを含むテキストが、別の領域を壊さない */
    public function testOddApostrophesDoNotBreakOtherRegions(): void
    {
        $compiled = new Compiler()->compile("<?php\n\$a = <p>don't</p>;\n\$b = <p>it's # not // a comment</p>;\n\$c = '<div>';\nreturn <i>x</i>;\n");

        self::assertStringContainsString("'don\\'t'", $compiled);
        self::assertStringContainsString("'it\\'s # not // a comment'", $compiled);
        self::assertStringContainsString("\$c = '<div>';", $compiled);
        self::assertStringContainsString("createElement('i'", $compiled);
    }

    /** CP-33: タグのテキストは `{式}` の括弧の対応に影響しない */
    public function testTextInsideExpressionDoesNotConfuseBraceMatching(): void
    {
        $compiled = new Compiler()->compile("<?php return <div>{\$x && <p>don't } # // </p>}<b>after</b></div>;");

        self::assertStringContainsString("'don\\'t } # // '", $compiled);
        self::assertStringContainsString("'after'", $compiled);
    }

    /** CP-92: `style`、`script` の中身で素の PHP として壊れるソース */
    public function testBrokenRawTextDoesNotHideNamespace(): void
    {
        $source = "<?php\nnamespace App;\nreturn <div><script>if (a) { b('</script><style>a { content: \"</style><Foo /></div>;";

        $compiler = new Compiler();
        $compiler->compile($source);

        self::assertSame(['App\\Foo'], $compiler->getLastReferences());
    }

    public function testRegionOffsetsAreSourceOffsets(): void
    {
        $source = '<?php $a = <a>x</a>; $b = <b />;';
        $regions = new PsxPreProcessor()->process($source)->regions;

        self::assertSame('<a>x</a>', \substr($source, $regions[0]->start, $regions[0]->end - $regions[0]->start));
        self::assertSame('<b />', \substr($source, $regions[1]->start, $regions[1]->end - $regions[1]->start));
    }
}
