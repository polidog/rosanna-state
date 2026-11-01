<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * §8: manifest の形式と、ランタイムなしで確かめられる deferred manifest の動作。
 */
final class ManifestTest extends TestCase
{
    private string $dir;
    private string $cache;
    private string $output = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-manifest-');
        \mkdir($this->dir . '/components');
        $this->cache = $this->dir . '/var/cache/psx';
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    private function compile(): int
    {
        \ob_start();
        try {
            return new CompileCommand()->run([], $this->dir);
        } finally {
            $this->output = (string) \ob_get_clean();
        }
    }

    private function source(string $name, string $code): string
    {
        $path = $this->dir . "/components/$name.psx";
        \file_put_contents($path, $code);

        return $path;
    }

    /**
     * @return array<string, array{file: string, parameters: list<array<string, string>>}>
     */
    private function manifest(): array
    {
        /** @var array<string, array{file: string, parameters: list<array<string, string>>}> */
        return require $this->cache . '/manifest.php';
    }

    /**
     * @return iterable<string, array{string, list<array<string, string>>}>
     */
    public static function parameters(): iterable
    {
        $props = ['kind' => 'props', 'name' => 'props'];
        $repo = ['kind' => 'service', 'name' => 'repo', 'service' => 'App\\Repo'];

        yield 'array first' => ['fn (array $props) => <a />', [$props]];
        yield 'CP-133 untyped first' => ['fn ($props) => <a />', [$props]];
        yield 'no parameters' => ['fn () => <a />', []];
        yield 'closure' => ['function (array $props) { return <a />; }', [$props]];
        yield 'service' => ['fn (array $props, Repo $repo) => <a />', [$props, $repo]];
        yield 'service as the first parameter' => ['fn (Repo $repo) => <a />', [$repo]];
        yield 'nullable service' => ['fn (array $props, ?Repo $repo) => <a />', [$props, $repo]];
        yield 'union with null' => ['fn (array $props, Repo|null $repo) => <a />', [$props, $repo]];
        yield 'null first in a union' => ['fn (array $props, null|Repo $repo) => <a />', [$props, $repo]];
        yield 'service with a default' => ['fn (array $props, ?Repo $repo = null) => <a />', [$props, $repo]];
        yield 'imported service' => ['fn (Log $log) => <a />', [['kind' => 'service', 'name' => 'log', 'service' => 'Psr\\Log']]];
        yield 'alias' => ['fn (Cache $cache) => <a />', [['kind' => 'service', 'name' => 'cache', 'service' => 'Lib\\Store']]];
        yield 'fully qualified' => ['fn (\\Other\\Repo $repo) => <a />', [['kind' => 'service', 'name' => 'repo', 'service' => 'Other\\Repo']]];
        yield 'relative name' => ['fn (Sub\\Repo $repo) => <a />', [['kind' => 'service', 'name' => 'repo', 'service' => 'App\\Sub\\Repo']]];
        yield 'default' => ['fn (array $props, int $n = 1) => <a />', [$props, ['kind' => 'default', 'name' => 'n', 'value' => '1']]];
        yield 'string default' => ["fn (array \$props, string \$s = 'a') => <a />", [$props, ['kind' => 'default', 'name' => 's', 'value' => "'a'"]]];
        yield 'array default' => ['fn (array $props, array $a = [1, 2]) => <a />', [$props, ['kind' => 'default', 'name' => 'a', 'value' => '[1, 2]']]];
        yield 'untyped default' => ['fn (array $props, $v = null) => <a />', [$props, ['kind' => 'default', 'name' => 'v', 'value' => 'null']]];
        yield 'nullable scalar' => ['fn (array $props, ?int $x) => <a />', [$props, ['kind' => 'null', 'name' => 'x']]];
        yield 'scalar union with null' => ['fn (array $props, int|null $x) => <a />', [$props, ['kind' => 'null', 'name' => 'x']]];
        yield 'nullable scalar first' => ['fn (?int $x) => <a />', [['kind' => 'null', 'name' => 'x']]];
        yield 'two classes and null' => ['fn (array $props, A|B|null $x) => <a />', [$props, ['kind' => 'null', 'name' => 'x']]];
        yield 'fc' => ['fc(fn (array $props, Repo $repo) => <a />)', [$props, $repo]];
        yield 'fc fully qualified' => ['\\Polidog\\Rosanna\\Runtime\\fc(fn (array $props) => <a />)', [$props]];
        yield 'fc with a closure' => ['fc(function (array $props, Repo $repo) { return <a />; })', [$props, $repo]];
        yield 'fc with a variable' => ['fc($callable)', []];
        yield 'not a closure' => ['"strlen"', []];
        yield 'not fc' => ['wrap(fn (array $props) => <a />)', []];
    }

    /**
     * CP-132、CP-133
     *
     * @param list<array<string, string>> $expected
     */
    #[DataProvider('parameters')]
    public function testParameters(string $component, array $expected): void
    {
        $this->source('Foo', "<?php\nnamespace App;\nuse Psr\\Log;\nuse Lib\\Store as Cache;\nreturn $component;\n");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertSame($expected, $this->manifest()['App\\Foo']['parameters']);
    }

    /** CP-132: 名前空間なしの `return` */
    public function testParametersWithoutANamespace(): void
    {
        $this->source('Foo', '<?php return fn (Repo $repo) => <a />;');

        self::assertSame(0, $this->compile(), $this->output);
        self::assertSame([['kind' => 'service', 'name' => 'repo', 'service' => 'Repo']], $this->manifest()['Foo']['parameters']);
    }

    /** CP-132: トップレベルの `return` だけを見る */
    public function testOnlyTheTopLevelReturnCounts(): void
    {
        $this->source('Foo', "<?php\n\$h = fn (int \$bad) => <a />;\nfunction helper(int \$bad) { return fn (int \$alsoBad) => 1; }\nreturn fn (array \$props) => <b />;\n");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertSame([['kind' => 'props', 'name' => 'props']], $this->manifest()['Foo']['parameters']);
    }

    /**
     * CP-133 の 5
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'scalar first' => ['fn (int $n) => <a />', 'n'];
        yield 'scalar later' => ['fn (array $props, string $s) => <a />', 's'];
        yield 'array later' => ['fn (array $props, array $more) => <a />', 'more'];
        yield 'untyped later' => ['fn ($props, $more) => <a />', 'more'];
        yield 'two classes' => ['fn (array $props, A|B $x) => <a />', 'x'];
        yield 'inside fc' => ['fc(fn (array $props, int $n) => <a />)', 'n'];
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParameter(string $component, string $name): void
    {
        $this->source('Foo', "<?php\nnamespace App;\nreturn $component;\n");

        self::assertSame(1, $this->compile());
        self::assertStringContainsString('App\\Foo', $this->output);
        self::assertStringContainsString('$' . $name, $this->output);
        self::assertStringContainsString('a default value', $this->output);
    }

    /** CP-130 */
    public function testManifestIsSortedAndMarkedAsGenerated(): void
    {
        $this->source('Zed', '<?php return fn () => <a />;');
        $this->source('Alpha', "<?php\nnamespace Zzz;\nreturn fn (array \$props, Repo \$r, int \$n = 1, ?int \$x) => <a />;");
        $this->source('Mid', '<?php return fn () => <a />;');

        self::assertSame(0, $this->compile(), $this->output);

        self::assertSame(['Mid', 'Zed', 'Zzz\\Alpha'], \array_keys($this->manifest()));
        $lines = \file($this->cache . '/manifest.php', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertSame('<?php', $lines[0]);
        self::assertStringContainsString('Auto-generated', $lines[1]);
    }

    /** §8 の形式どおり */
    public function testManifestFormat(): void
    {
        $source = $this->source('Counter', "<?php\nnamespace App;\nreturn fn (array \$props, Repo \$repo, int \$n = 1, ?int \$x) => <a />;");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertSame(
            ['App\\Counter' => [
                'file' => $this->cache . '/' . \sha1($source) . '.php',
                'parameters' => [
                    ['kind' => 'props', 'name' => 'props'],
                    ['kind' => 'service', 'name' => 'repo', 'service' => 'App\\Repo'],
                    ['kind' => 'default', 'name' => 'n', 'value' => '1'],
                    ['kind' => 'null', 'name' => 'x'],
                ],
            ]],
            $this->manifest(),
        );
    }

    /** CP-136 */
    public function testLeftoverDeferredManifestIsRemoved(): void
    {
        $this->source('Foo', '<?php return fn () => <a />;');
        \mkdir($this->cache, 0o777, true);
        \file_put_contents($this->cache . '/deferred-manifest.php', "<?php\nreturn ['x' => ['component' => 'Foo', 'cacheControl' => null]];\n");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertFileDoesNotExist($this->cache . '/deferred-manifest.php');
    }

    /** CP-135: `Defer` の文字列を含まないファイルは実行しない */
    public function testFileWithoutTheDeferStringIsNotRequired(): void
    {
        $this->source('Foo', "<?php\nthrow new \\Exception('must not run');\nreturn fn () => <a />;");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertFileDoesNotExist($this->cache . '/deferred-manifest.php');
    }

    /** CP-135: 小文字のファイルは manifest に載らないので実行しない */
    public function testLowercaseFileIsNotRequired(): void
    {
        $this->source('page', "<?php\n// Defer\nthrow new \\Exception('must not run');\nreturn fn () => <a />;");

        self::assertSame(0, $this->compile(), $this->output);
    }

    /** §8 のエラー: 読み込みの例外は exit 1 で、ファイルと内容を表示する */
    public function testRequireFailureExitsOneWithFileAndMessage(): void
    {
        $source = $this->source('Foo', "<?php\n// Defer\nthrow new \\Exception('boom at the top');\nreturn fn () => <a />;");

        self::assertSame(1, $this->compile());
        self::assertStringContainsString($source, $this->output);
        self::assertStringContainsString(CompileCommand::cachePathFor($this->cache, $source), $this->output);
        self::assertStringContainsString('boom at the top', $this->output);
    }

    /** CP-135: 含んでいても FunctionComponent でなければ集めない */
    public function testFileMentioningDeferThatIsNotAFunctionComponent(): void
    {
        $this->source('Foo', "<?php\n// Defer\nreturn fn () => 'x';");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertFileDoesNotExist($this->cache . '/deferred-manifest.php');
    }

    /** CP-135: トップレベルの出力がコマンドの出力へ混ざらない */
    public function testTopLevelOutputIsDiscarded(): void
    {
        $this->source('Foo', "<?php\n// Defer\necho 'leak';\nreturn fn () => 'x';");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertStringNotContainsString('leak', $this->output);
    }

    /** CP-123: 古い成果物を読み込むと、defer の宣言を読みに require してしまう */
    public function testCheckDoesNotLoadAStaleArtifactThatDeclaresDefer(): void
    {
        $source = $this->source('Foo', "<?php\nuse Polidog\\Rosanna\\Runtime\\FunctionComponent;\n// Defer\nreturn fn () => 'x';");
        self::assertSame(0, $this->compile(), $this->output);

        $marker = $this->dir . '/loaded';
        \file_put_contents(
            CompileCommand::cachePathFor($this->cache, $source),
            "<?php\n// Defer\n\\file_put_contents(" . \var_export($marker, true) . ", 'x');\nreturn fn () => 'x';\n",
        );
        $this->source('Foo', "<?php\nuse Polidog\\Rosanna\\Runtime\\FunctionComponent;\n// Defer changed\nreturn fn () => 'x';");

        \ob_start();
        $code = new CompileCommand()->run(['--check'], $this->dir);
        $output = (string) \ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('Out of date', $output);
        self::assertFileDoesNotExist($marker);
    }

    /** CP-132: 名前付き引数の順番によらず、`component` の引数を読む */
    public function testParametersOfANamedComponentArgument(): void
    {
        $this->source('Foo', "<?php\nuse function Polidog\\Rosanna\\Runtime\\fc;\nreturn fc(key: 'k', component: fn (array \$p, \\ArrayObject \$r) => <a />, storageType: \\Polidog\\Rosanna\\Storage\\StorageType::Memory);\n");

        self::assertSame(0, $this->compile(), $this->output);
        $manifest = require $this->cache . '/manifest.php';

        self::assertSame([
            ['kind' => 'props', 'name' => 'p'],
            ['kind' => 'service', 'name' => 'r', 'service' => 'ArrayObject'],
        ], $manifest['Foo']['parameters']);
    }

    /** CP-133: 名前付きでも解釈できないパラメータはエラーになる */
    public function testInvalidParameterOfANamedComponentArgument(): void
    {
        $this->source('Foo', "<?php\nuse function Polidog\\Rosanna\\Runtime\\fc;\nreturn fc(key: 'k', component: fn (array \$p, int \$bad) => <a />);\n");

        self::assertSame(1, $this->compile());
        self::assertStringContainsString('$bad', $this->output);
    }

    /** CP-132: 位置引数のあとの名前付き引数は従来どおり最初の引数を読む */
    public function testParametersOfAPositionalComponentWithNamedOptions(): void
    {
        $this->source('Foo', "<?php\nuse function Polidog\\Rosanna\\Runtime\\fc;\nreturn fc(fn (array \$p, \\ArrayObject \$r) => <a />, key: 'k');\n");

        self::assertSame(0, $this->compile(), $this->output);
        $manifest = require $this->cache . '/manifest.php';

        self::assertCount(2, $manifest['Foo']['parameters']);
    }

    /** CP-123: `--check` は古い成果物を読み込まない */
    public function testCheckDoesNotRequireAStaleArtifact(): void
    {
        $source = $this->source('Foo', "<?php\n// Defer\nreturn fn () => 'x';");
        self::assertSame(0, $this->compile());

        \file_put_contents(CompileCommand::cachePathFor($this->cache, $source), "<?php\nthrow new \\Exception('must not run');\n");
        $this->source('Foo', "<?php\n// Defer changed\nreturn fn () => 'x';");

        \ob_start();
        $code = new CompileCommand()->run(['--check'], $this->dir);
        $output = (string) \ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('Out of date', $output);
        self::assertStringNotContainsString('must not run', $output);
    }
}
