<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\Compiler;
use Polidog\Rosanna\Tests\Psx\Support\StubRuntime;

/**
 * CP-65〜CP-68: スタブのランタイムで評価し、どの値が返るかを確かめる。
 * 描画まで確かめるものは Integration/CompiledOutputTest にある。
 */
final class OperatorLoweringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, mixed>, mixed}>
     */
    public static function operators(): iterable
    {
        $b = ['type' => 'b', 'params' => ['children' => ['x']]];
        $i = ['type' => 'i', 'params' => ['children' => ['y']]];

        yield 'CP-65 && true' => ['$p["a"] && <b>x</b>', ['a' => true], $b];
        yield 'CP-65 && false' => ['$p["a"] && <b>x</b>', ['a' => false], false];
        yield 'CP-67 && zero returns false' => ['$p["a"] && <b>x</b>', ['a' => 0], false];
        yield 'CP-65 literal' => ['true && <b>x</b>', [], $b];
        yield 'CP-65 and' => ['($p["a"] and <b>x</b>)', ['a' => true], $b];
        yield 'CP-66 || truthy left' => ['$p["a"] || <b>x</b>', ['a' => 'v'], 'v'];
        yield 'CP-66 || falsy left' => ['$p["a"] || <b>x</b>', ['a' => ''], $b];
        yield 'CP-66 or' => ['($p["a"] or <b>x</b>)', ['a' => false], $b];
        yield 'coalesce with a value' => ['$p["a"] ?? <b>x</b>', ['a' => 'v'], 'v'];
        yield 'coalesce without a value' => ['$p["a"] ?? <b>x</b>', [], $b];
        yield 'elvis' => ['$p["a"] ?: <b>x</b>', ['a' => ''], $b];
        yield 'ternary' => ['$p["a"] ? <b>x</b> : <i>y</i>', ['a' => false], $i];
        yield 'chained &&' => ['$p["a"] && $p["b"] && <b>x</b>', ['a' => 1, 'b' => 1], $b];
        yield 'chained && stops' => ['$p["a"] && $p["b"] && <b>x</b>', ['a' => 1, 'b' => 0], false];
        yield '&& then ||' => ['$p["a"] && <b>x</b> || <i>y</i>', ['a' => false], $i];
        yield '&& then || left' => ['$p["a"] && <b>x</b> || <i>y</i>', ['a' => true], $b];
        yield '|| then &&' => ['$p["a"] || $p["b"] && <b>x</b>', ['a' => 0, 'b' => 1], $b];
        yield 'parenthesized operands' => ['($p["a"]) && (<b>x</b>)', ['a' => 1], $b];
        yield 'ternary on the right' => ['$p["a"] && ($p["b"] ? <b>x</b> : null)', ['a' => 1, 'b' => 1], $b];
        yield 'ternary on the left of and' => ['($p["a"] ? 1 : 0 and <b>x</b>)', ['a' => 1], $b];
        yield 'ternary on the right of or' => ['($p["a"] or $p["b"] ? <b>x</b> : <i>y</i>)', ['a' => 0, 'b' => 0], $i];
        yield 'nested in a child' => ['<u>{$p["a"] && <b>{$p["a"] && <i>y</i>}</b>}</u>', ['a' => 1], ['type' => 'u', 'params' => ['children' => ['type' => 'b', 'params' => ['children' => $i]]]]];
        yield 'inside array_map' => ['$p["a"] && array_map(fn ($v) => $v && <b>x</b>, [1, 0])', ['a' => 1], [$b, false]];
        yield 'comment before the operator' => ["\$p['a'] // c\n && <b>x</b>", ['a' => 1], $b];
        yield 'block comment before the operator' => ['$p["a"] /* c */ && <b>x</b>', ['a' => 1], $b];
        yield 'CP-73 inside a spread' => ['<u>{...$p["a"] && [<b>x</b>]}</u>', ['a' => 1], ['type' => 'u', 'params' => ['children' => [$b]]]];
        yield 'in an attribute' => ['<u id={$p["a"] && <b>x</b>} />', ['a' => 1], ['type' => 'u', 'params' => ['id' => $b]]];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('operators')]
    public function testOperatorResult(string $expression, array $props, mixed $expected): void
    {
        $compiled = new Compiler()->compile("<?php\nreturn fn (array \$p) => $expression;\n");

        self::assertSame(['value' => $expected], StubRuntime::evaluate($compiled, $props));
    }

    /** §2 のテスト: `return` の直後 */
    public function testDirectlyAfterReturn(): void
    {
        $compiled = new Compiler()->compile('<?php $x = true; return $x && <b />;');

        self::assertSame(['value' => ['type' => 'b', 'params' => []]], StubRuntime::evaluate($compiled));
    }

    /** CP-67 */
    public function testOperatorsWithoutATagAreUntouched(): void
    {
        $compiled = new Compiler()->compile('<?php return <p id={$a && $b} title={$a and $b}>{$a || $b}</p>;');

        self::assertStringContainsString("'id' => \$a && \$b", $compiled);
        self::assertStringContainsString("'title' => \$a and \$b", $compiled);
        self::assertStringContainsString("'children' => \$a || \$b", $compiled);

        $outside = new Compiler()->compile('<?php $x = $a && $b; return <p />;');
        self::assertStringContainsString('$x = $a && $b;', $outside);
    }

    /** CP-68: 差し込みなのでコメントと行が残る */
    public function testCommentsAndLinesSurvive(): void
    {
        $source = "<?php\nreturn <p>{\n  /* left */ \$a /* gap */\n  && /* right */ <b>\n  x\n  </b>\n}</p>;\n";

        $compiled = new Compiler()->compile($source);

        self::assertStringContainsString('/* left */', $compiled);
        self::assertStringContainsString('/* gap */', $compiled);
        self::assertStringContainsString('/* right */', $compiled);
        self::assertSame(\substr_count($source, "\n"), \substr_count($compiled, "\n"));
    }
}
