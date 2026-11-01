<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Defer\DeferredManifestCompiler;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\State\Tests\Psx\Support\TemporaryDirectory;

/**
 * §8: `rosanna-state compile` の deferred manifest の動作のうち、ランタイムなしで確かめられるもの。
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
            return new CompileCommand(new DeferredManifestCompiler()->compile(...))->run([], $this->dir);
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
        yield 'fc fully qualified' => ['\\Polidog\\Rosanna\\State\\fc(fn (array $props) => <a />)', [$props]];
        yield 'fc with a closure' => ['fc(function (array $props, Repo $repo) { return <a />; })', [$props, $repo]];
        yield 'fc with a variable' => ['fc($callable)', []];
        yield 'not a closure' => ['"strlen"', []];
        yield 'not fc' => ['wrap(fn (array $props) => <a />)', []];
    }

    /** CP-135: `Defer` の文字列を含まないファイルは実行しない */
    public function testFileWithoutTheDeferStringIsNotRequired(): void
    {
        $this->source('Foo', "<?php\nthrow new \\Exception('must not run');\nreturn fn () => <a />;");

        self::assertSame(0, $this->compile(), $this->output);
        self::assertSame([], DeferredManifestCompiler::read($this->cache . '/manifest.php'));
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
        self::assertSame([], DeferredManifestCompiler::read($this->cache . '/manifest.php'));
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
        $source = $this->source('Foo', "<?php\nuse Polidog\\Rosanna\\State\\FunctionComponent;\n// Defer\nreturn fn () => 'x';");
        self::assertSame(0, $this->compile(), $this->output);

        $marker = $this->dir . '/loaded';
        \file_put_contents(
            CompileCommand::cachePathFor($this->cache, $source),
            "<?php\n// Defer\n\\file_put_contents(" . \var_export($marker, true) . ", 'x');\nreturn fn () => 'x';\n",
        );
        $this->source('Foo', "<?php\nuse Polidog\\Rosanna\\State\\FunctionComponent;\n// Defer changed\nreturn fn () => 'x';");

        \ob_start();
        $code = new CompileCommand(new DeferredManifestCompiler()->compile(...))->run(['--check'], $this->dir);
        $output = (string) \ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('Out of date', $output);
        self::assertFileDoesNotExist($marker);
    }

    /** CP-123: `--check` は古い成果物を読み込まない */
    public function testCheckDoesNotRequireAStaleArtifact(): void
    {
        $source = $this->source('Foo', "<?php\n// Defer\nreturn fn () => 'x';");
        self::assertSame(0, $this->compile());

        \file_put_contents(CompileCommand::cachePathFor($this->cache, $source), "<?php\nthrow new \\Exception('must not run');\n");
        $this->source('Foo', "<?php\n// Defer changed\nreturn fn () => 'x';");

        \ob_start();
        $code = new CompileCommand(new DeferredManifestCompiler()->compile(...))->run(['--check'], $this->dir);
        $output = (string) \ob_get_clean();

        self::assertSame(1, $code);
        self::assertStringContainsString('Out of date', $output);
        self::assertStringNotContainsString('must not run', $output);
    }
}
