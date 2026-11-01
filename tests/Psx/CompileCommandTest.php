<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * §7: CompileCommand。ランタイムは定数しか使わない。
 */
final class CompileCommandTest extends TestCase
{
    private string $dir;
    private string $cache;
    private string $manifest;
    private string $output = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-command-');
        \mkdir($this->dir . '/components');
        $this->cache = $this->dir . '/var/cache/psx';
        $this->manifest = $this->cache . '/manifest.php';
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    /**
     * @param list<string> $argv
     */
    private function run_(array $argv = []): int
    {
        \ob_start();
        try {
            return new CompileCommand()->run($argv, $this->dir);
        } finally {
            $this->output = (string) \ob_get_clean();
        }
    }

    private function source(string $name, string $code): string
    {
        $path = $this->dir . '/components/' . $name;
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o777, true);
        }
        \file_put_contents($path, $code);

        return $path;
    }

    private function component(string $name, string $body = '<div />'): string
    {
        return $this->source("$name.psx", "<?php\nnamespace App;\nreturn fn (array \$props) => $body;\n");
    }

    /**
     * @return array<string, array{file: string, parameters: list<array<string, string>>}>
     */
    private function loadManifest(string $path = ''): array
    {
        /** @var array<string, array{file: string, parameters: list<array<string, string>>}> */
        return require $path === '' ? $this->manifest : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $source): array
    {
        /** @var array<string, mixed> */
        return \json_decode((string) \file_get_contents(CompileCommand::metaPathFor($this->cache, $source)), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testConstants(): void
    {
        self::assertSame('var/cache/psx', CompileCommand::DEFAULT_CACHE_SUBDIR);
        self::assertSame(3, CompileCommand::CACHE_VERSION);
        self::assertSame('manifest.php', PsxRenderer::MANIFEST_FILENAME);
        self::assertSame('deferred-manifest.php', PsxRenderer::DEFERRED_MANIFEST_FILENAME);
    }

    /** CP-110、CP-114、CP-115、CP-116 */
    public function testCompilesDefaultDirectoryIntoDefaultCache(): void
    {
        $source = $this->component('Counter');

        self::assertSame(0, $this->run_(), $this->output);

        $artifact = $this->cache . '/' . \sha1($source) . '.php';
        self::assertFileExists($artifact);
        self::assertStringContainsString("createElement('div'", (string) \file_get_contents($artifact));
        self::assertSame(['Counter.psx'], \array_values(\array_diff((array) \scandir($this->dir . '/components'), ['.', '..'])));

        self::assertSame(
            [
                'hash' => \hash('sha256', "3\0" . (string) \file_get_contents($source)),
                'refs' => [],
                'source' => $source,
            ],
            \json_decode((string) \file_get_contents($artifact . '.meta'), true),
        );
        self::assertSame(['App\\Counter'], \array_keys($this->loadManifest()));
        self::assertSame($artifact, $this->loadManifest()['App\\Counter']['file']);
    }

    /** CP-115 §10 が読む形 */
    public function testMetaRecordsReferencesAndSource(): void
    {
        $this->component('Inner');
        $source = $this->component('Outer', '<Inner />');

        self::assertSame(0, $this->run_());
        self::assertSame(['App\\Inner'], $this->meta($source)['refs']);
        self::assertSame($source, $this->meta($source)['source']);
    }

    public function testCustomCacheAndArgumentOrder(): void
    {
        $this->component('Counter');

        self::assertSame(0, $this->run_(['components', '--cache=out/psx']));
        self::assertFileExists($this->dir . '/out/psx/manifest.php');
        self::assertDirectoryDoesNotExist($this->cache);
    }

    public function testAbsoluteCachePath(): void
    {
        $this->component('Counter');

        self::assertSame(0, $this->run_(['--cache=' . $this->dir . '/abs']));
        self::assertStringStartsWith($this->dir . '/abs/', $this->loadManifest($this->dir . '/abs/manifest.php')['App\\Counter']['file']);
    }

    /** CP-131 */
    public function testManifestFileIsAbsoluteForARelativeCache(): void
    {
        $this->component('Counter');

        self::assertSame(0, $this->run_(['--cache=rel/cache']));
        self::assertStringStartsWith($this->dir . '/rel/cache/', $this->loadManifest($this->dir . '/rel/cache/manifest.php')['App\\Counter']['file']);
    }

    /**
     * 未知のオプションは exit 1 (§7)。何も作らない。
     */
    public function testUnknownOptionsExitOne(): void
    {
        $this->component('Counter');

        foreach (['--chek', '--check=1', '--cache', '--cache=', '-x', '--', '--help'] as $option) {
            self::assertSame(1, $this->run_([$option]), $option);
            self::assertStringContainsString($option, $this->output);
        }
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** §7: `--clean` と組み合わせても誤りは止める */
    public function testUnknownOptionWinsOverClean(): void
    {
        self::assertSame(1, $this->run_(['--clean', '--chek']));
    }

    /** CP-111 */
    public function testMissingPathExitsOneBeforeCreatingTheCache(): void
    {
        self::assertSame(1, $this->run_(['nope']));
        self::assertStringContainsString('nope', $this->output);
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** CP-111 */
    public function testMissingPathFailsEvenWhenAnotherIsValid(): void
    {
        $this->component('Counter');

        self::assertSame(1, $this->run_(['components', 'nope']));
        self::assertStringContainsString('nope', $this->output);
        self::assertStringNotContainsString('components', $this->output);
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** CP-111: `--check` が誤ったパスで成功しない */
    public function testMissingPathFailsCheck(): void
    {
        self::assertSame(1, $this->run_(['nope', '--check']));
    }

    /** CP-110、CP-111 */
    public function testMissingDefaultComponentsDirectoryExitsOne(): void
    {
        \rmdir($this->dir . '/components');

        self::assertSame(1, $this->run_());
        self::assertStringContainsString('components', $this->output);
    }

    /** CP-112 */
    public function testDirectoryWithoutPsxExitsZero(): void
    {
        self::assertSame(0, $this->run_());
        self::assertStringContainsString('No .psx files', $this->output);
    }

    /** CP-113 */
    public function testScanIsRecursiveAndOnlyTakesPsx(): void
    {
        $this->source('deep/er/A.psx', '<?php return fn () => <a />;');
        $this->source('B.psx', '<?php return fn () => <a />;');
        $this->source('C.txt', '<?php return 1 +;');
        $this->source('D.psx.bak', '<?php return 1 +;');

        self::assertSame(0, $this->run_(), $this->output);
        self::assertSame(['A', 'B'], \array_keys($this->loadManifest()));
    }

    /** CP-113 */
    public function testSymlinksAreNotFollowed(): void
    {
        $outside = $this->dir . '/outside';
        \mkdir($outside);
        \file_put_contents($outside . '/Linked.psx', '<?php return fn () => <a />;');
        \symlink($outside, $this->dir . '/components/dirlink');
        \symlink($outside . '/Linked.psx', $this->dir . '/components/Filelink.psx');
        $this->component('Real');

        self::assertSame(0, $this->run_(), $this->output);
        self::assertSame(['App\\Real'], \array_keys($this->loadManifest()));
    }

    /** CP-113: ディレクトリとその中のファイルを同時に渡しても重複にならない */
    public function testSameFileGivenTwiceIsDeduplicated(): void
    {
        $source = $this->component('Counter');

        self::assertSame(0, $this->run_(['components', $source, 'components/Counter.psx', './components']), $this->output);
        self::assertSame(['App\\Counter'], \array_keys($this->loadManifest()));
    }

    /** CP-113: 結果がソートされ、順序によらず manifest が同じ */
    public function testResultDoesNotDependOnArgumentOrder(): void
    {
        $this->source('a/One.psx', '<?php return fn () => <a />;');
        $this->source('b/Two.psx', '<?php return fn () => <a />;');

        self::assertSame(0, $this->run_(['components/a', 'components/b']));
        $first = (string) \file_get_contents($this->manifest);
        self::assertSame(0, $this->run_(['components/b', 'components/a']));

        self::assertSame($first, \file_get_contents($this->manifest));
    }

    /** CP-117 */
    public function testDirectoryIsNotPartOfTheFqcn(): void
    {
        $this->source('deep/Foo.psx', "<?php\nnamespace App\\Ui;\nreturn fn () => <a />;");

        self::assertSame(0, $this->run_(), $this->output);
        self::assertSame(['App\\Ui\\Foo'], \array_keys($this->loadManifest()));
    }

    /** CP-117、CP-118 */
    public function testLowercaseFilesAreCompiledButNotInTheManifest(): void
    {
        $page = $this->source('a/page.psx', "<?php\nnamespace App;\nreturn fn () => <a />;");
        $this->source('b/page.psx', "<?php\nnamespace App;\nreturn fn () => <b />;");
        $this->source('c/layout.psx', "<?php\nnamespace App;\nreturn fn () => <i />;");
        $this->component('Counter');

        self::assertSame(0, $this->run_(), $this->output);
        self::assertSame(['App\\Counter'], \array_keys($this->loadManifest()));
        self::assertFileExists(CompileCommand::cachePathFor($this->cache, $page));
        self::assertFileExists(CompileCommand::cachePathFor($this->cache, $this->dir . '/components/b/page.psx'));
    }

    /** CP-119 */
    public function testDuplicateFqcnShowsBothSourcePaths(): void
    {
        $a = $this->source('a/Foo.psx', "<?php\nnamespace App;\nreturn fn () => <a />;");
        $b = $this->source('b/Foo.psx', "<?php\nnamespace App;\nreturn fn () => <b />;");

        self::assertSame(1, $this->run_());
        self::assertStringContainsString('App\\Foo', $this->output);
        self::assertStringContainsString($a, $this->output);
        self::assertStringContainsString($b, $this->output);
    }

    /** CP-120 */
    public function testUnresolvedReferenceExitsOne(): void
    {
        $source = $this->component('Outer', '<Missing />');

        self::assertSame(1, $this->run_());
        self::assertStringContainsString($source, $this->output);
        self::assertStringContainsString("unresolved component 'App\\Missing'", $this->output);
        self::assertStringContainsString('@psx-runtime App\\Missing', $this->output);
    }

    /** CP-120: 小文字のファイルは解決先にならない */
    public function testLowercaseFileIsNotAResolutionTarget(): void
    {
        $this->source('page.psx', "<?php\nnamespace App;\nreturn fn () => <a />;");
        $this->component('Outer', '<page />');
        $this->component('Other', '<Page />');

        self::assertSame(1, $this->run_());
        self::assertStringContainsString("unresolved component 'App\\Page'", $this->output);
    }

    /** CP-120 */
    public function testPsxRuntimeDeclarationResolves(): void
    {
        $this->source('Outer.psx', "<?php\nnamespace App;\n// @psx-runtime App\\Registered\nreturn fn () => <Registered />;");

        self::assertSame(0, $this->run_(), $this->output);
    }

    /** CP-120: `@psx-runtime` はファイルをまたいで有効 */
    public function testPsxRuntimeDeclarationWorksAcrossFiles(): void
    {
        $this->source('Aaa.psx', "<?php\nnamespace App;\nreturn fn () => <Registered />;");
        $this->source('Zzz.psx', "<?php\nnamespace App;\n/* @psx-runtime \\App\\Registered */\nreturn fn () => <a />;");

        self::assertSame(0, $this->run_(), $this->output);
    }

    /** CP-120 */
    public function testReferenceToAnotherComponentResolvesRegardlessOfOrder(): void
    {
        $this->component('Aaa', '<Zzz />');
        $this->component('Zzz');

        self::assertSame(0, $this->run_(), $this->output);
    }

    /** CP-120: use の参照は manifest の FQCN と比べる */
    public function testUseAliasIsResolved(): void
    {
        $this->source('Lib.psx', "<?php\nnamespace Vendor\\Pkg;\nreturn fn () => <a />;");
        $this->source('Outer.psx', "<?php\nnamespace App;\nuse Vendor\\Pkg\\Lib as Thing;\nreturn fn () => <Thing />;");

        self::assertSame(0, $this->run_(), $this->output);
    }

    /** CP-121 */
    public function testSecondRunSkipsUnchangedFiles(): void
    {
        $source = $this->component('Counter');
        self::assertSame(0, $this->run_());

        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        \file_put_contents($artifact, '<?php // sentinel');

        self::assertSame(0, $this->run_());
        self::assertSame('<?php // sentinel', \file_get_contents($artifact));
        self::assertStringContainsString('1 cached', $this->output);
    }

    /** CP-121 */
    public function testSourceChangeRecompiles(): void
    {
        $source = $this->component('Counter', '<div />');
        self::assertSame(0, $this->run_());

        $this->component('Counter', '<span />');
        self::assertSame(0, $this->run_());

        self::assertStringContainsString("createElement('span'", (string) \file_get_contents(CompileCommand::cachePathFor($this->cache, $source)));
    }

    /** CP-116、CP-121: CACHE_VERSION が変わると hash が一致しない */
    public function testHashFromAnotherVersionRecompiles(): void
    {
        $source = $this->component('Counter');
        self::assertSame(0, $this->run_());

        $metaPath = CompileCommand::metaPathFor($this->cache, $source);
        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        \file_put_contents($metaPath, (string) \json_encode([
            'hash' => \hash('sha256', "2\0" . (string) \file_get_contents($source)),
            'refs' => [],
            'source' => $source,
        ]));
        \file_put_contents($artifact, '<?php // old');

        self::assertSame(0, $this->run_());
        self::assertStringContainsString("createElement('div'", (string) \file_get_contents($artifact));
        self::assertStringNotContainsString('cached', $this->output);
    }

    /** CP-115: `source` のない `.meta` は使わない */
    public function testMetaWithoutSourceRecompiles(): void
    {
        $source = $this->component('Counter');
        self::assertSame(0, $this->run_());

        $metaPath = CompileCommand::metaPathFor($this->cache, $source);
        $meta = $this->meta($source);
        unset($meta['source']);
        \file_put_contents($metaPath, (string) \json_encode($meta));

        self::assertSame(0, $this->run_());
        self::assertSame($source, $this->meta($source)['source']);
    }

    /** CP-121 */
    public function testBrokenMetaAndMissingArtifactRecompile(): void
    {
        $source = $this->component('Counter');
        self::assertSame(0, $this->run_());

        \file_put_contents(CompileCommand::metaPathFor($this->cache, $source), 'not json');
        self::assertSame(0, $this->run_());
        self::assertStringNotContainsString('cached', $this->output);

        \unlink(CompileCommand::cachePathFor($this->cache, $source));
        self::assertSame(0, $this->run_());
        self::assertFileExists(CompileCommand::cachePathFor($this->cache, $source));
    }

    /** CP-121: 参照先を消すと、変更のないファイルでも検出する */
    public function testDeletedReferenceIsDetectedInAnUnchangedFile(): void
    {
        $inner = $this->component('Inner');
        $this->component('Outer', '<Inner />');
        self::assertSame(0, $this->run_());

        \unlink($inner);

        self::assertSame(1, $this->run_());
        self::assertStringContainsString("unresolved component 'App\\Inner'", $this->output);
    }

    /** CP-122 */
    public function testUnchangedFilesAreNotRewritten(): void
    {
        $source = $this->component('Counter');
        self::assertSame(0, $this->run_());

        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        $paths = [$artifact, $artifact . '.meta', $this->manifest];
        foreach ($paths as $path) {
            \touch($path, 1_000_000);
        }
        \clearstatcache();

        // ソースは同じだが meta を壊して再コンパイルさせても、同じ内容の成果物は書かない
        \file_put_contents($artifact . '.meta', 'x');
        \touch($artifact . '.meta', 1_000_000);
        self::assertSame(0, $this->run_());
        \clearstatcache();

        self::assertSame(1_000_000, \filemtime($artifact));
        self::assertSame(1_000_000, \filemtime($this->manifest));
    }

    /** CP-122: rename で置き換わるので、読み込み中の inode は書き換わらない。一時ファイルも残らない */
    public function testWritesAreAtomicAndLeaveNoTemporaryFiles(): void
    {
        $source = $this->component('Counter', '<div />');
        self::assertSame(0, $this->run_());

        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        $inode = \fileinode($artifact);
        $reader = \fopen($artifact, 'r');
        self::assertIsResource($reader);

        $this->component('Counter', '<span />');
        self::assertSame(0, $this->run_());
        \clearstatcache();

        self::assertNotSame($inode, \fileinode($artifact));
        self::assertStringContainsString("'div'", (string) \stream_get_contents($reader));
        \fclose($reader);
        self::assertSame([], \glob($this->cache . '/*.tmp'));
    }

    /** §7 のエラー: 書き込みの失敗は exit 1 で、一時ファイルを残さない */
    public function testWriteFailureExitsOneAndLeavesNoTemporaryFile(): void
    {
        $source = $this->component('Counter');
        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        // ディレクトリへの rename は権限によらず失敗する
        \mkdir($artifact, 0o777, true);

        self::assertSame(1, $this->run_());
        self::assertStringContainsString($artifact, $this->output);
        self::assertSame([], \glob($this->cache . '/*.tmp'));
    }

    /** §7 のエラー: 出力先がファイルでも例外を投げない */
    public function testCacheThatIsAFileExitsOne(): void
    {
        $this->component('Counter');
        \file_put_contents($this->dir . '/file', '');

        self::assertSame(1, $this->run_(['--cache=file/sub']));
    }

    /** CP-123 */
    public function testCheckFailsAndWritesNothingWhenNothingWasBuilt(): void
    {
        $source = $this->component('Counter');

        self::assertSame(1, $this->run_(['--check']));
        self::assertStringContainsString('Out of date', $this->output);
        self::assertStringContainsString($source, $this->output);
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** CP-123 */
    public function testCheckPassesAfterABuild(): void
    {
        $this->component('Counter');
        $this->component('Other', '<Counter />');
        self::assertSame(0, $this->run_());

        self::assertSame(0, $this->run_(['--check']), $this->output);
    }

    /** CP-123 */
    public function testCheckDetectsAStaleArtifactAndLeavesItAlone(): void
    {
        $source = $this->component('Counter', '<div />');
        self::assertSame(0, $this->run_());
        $artifact = CompileCommand::cachePathFor($this->cache, $source);
        $before = \file_get_contents($artifact);
        $metaBefore = \file_get_contents($artifact . '.meta');

        $this->component('Counter', '<span />');

        self::assertSame(1, $this->run_(['--check']));
        self::assertStringContainsString($source, $this->output);
        self::assertSame($before, \file_get_contents($artifact));
        self::assertSame($metaBefore, \file_get_contents($artifact . '.meta'));
    }

    /** CP-123 */
    public function testCheckDetectsAStaleManifest(): void
    {
        $this->component('Counter');
        self::assertSame(0, $this->run_());
        \file_put_contents($this->manifest, "<?php\nreturn [];\n");

        self::assertSame(1, $this->run_(['--check']));
        self::assertStringContainsString($this->manifest, $this->output);
        self::assertSame("<?php\nreturn [];\n", \file_get_contents($this->manifest));
    }

    /** CP-123、CP-136 */
    public function testCheckDetectsALeftoverDeferredManifest(): void
    {
        $this->component('Counter');
        self::assertSame(0, $this->run_());
        $deferred = $this->cache . '/deferred-manifest.php';
        \file_put_contents($deferred, "<?php\nreturn [];\n");

        self::assertSame(1, $this->run_(['--check']));
        self::assertStringContainsString($deferred, $this->output);
        self::assertFileExists($deferred);
    }

    /** CP-123: `--check` でも解析エラーは exit 1 で、ファイル名が付く */
    public function testCheckReportsCompileErrors(): void
    {
        $source = $this->source('Bad.psx', '<?php return <p>{1 +}</p>;');

        self::assertSame(1, $this->run_(['--check']));
        self::assertStringContainsString($source, $this->output);
        self::assertStringContainsString('PSX compile error', $this->output);
    }

    /** §7: `--check` と `--watch` は両立しない。CI で止まらなくなるのを防ぐ */
    public function testCheckAndWatchCannotBeCombined(): void
    {
        $this->component('Counter');

        self::assertSame(1, $this->run_(['--check', '--watch']));
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** CP-124 */
    public function testCleanRemovesEverythingInTheCache(): void
    {
        $this->component('Counter');
        self::assertSame(0, $this->run_());
        \mkdir($this->cache . '/sub');
        \file_put_contents($this->cache . '/sub/x', '');

        self::assertSame(0, $this->run_(['--clean']));

        self::assertDirectoryExists($this->cache);
        self::assertSame(['.', '..'], \scandir($this->cache));
        self::assertFileExists($this->dir . '/components/Counter.psx');
    }

    /** CP-124 */
    public function testCleanDoesNotFollowSymlinks(): void
    {
        \mkdir($this->cache, 0o777, true);
        $keep = $this->dir . '/keep';
        \mkdir($keep);
        \file_put_contents($keep . '/file', 'x');
        \symlink($keep, $this->cache . '/link');

        self::assertSame(0, $this->run_(['--clean']));

        self::assertFileExists($keep . '/file');
        self::assertSame(['.', '..'], \scandir($this->cache));
    }

    /** CP-124 */
    public function testCleanWithoutACacheExitsZero(): void
    {
        self::assertSame(0, $this->run_(['--clean']));
        self::assertDirectoryDoesNotExist($this->dir . '/var');
    }

    /** CP-124: パスを確かめず、`--check`、`--watch` より優先する */
    public function testCleanIgnoresPathsAndWinsOverCheckAndWatch(): void
    {
        $this->component('Counter');
        self::assertSame(0, $this->run_());

        self::assertSame(0, $this->run_(['--clean', '--check', '--watch', 'nope']));
        self::assertFileDoesNotExist($this->manifest);
    }

    /** §7 のエラー */
    public function testCompilerErrorsNameTheSourceFile(): void
    {
        $source = $this->source('Bad.psx', "<?php\nreturn <a></b>;\n");

        self::assertSame(1, $this->run_());
        self::assertStringContainsString($source . ': PSX parse error at line 2', $this->output);
    }

    /** §7 のエラー: 最初のエラーで止める */
    public function testStopsAtTheFirstError(): void
    {
        $this->source('Aaa.psx', '<?php return <a></b>;');
        $this->source('Zzz.psx', '<?php return <c></d>;');

        self::assertSame(1, $this->run_());
        self::assertStringContainsString('Aaa.psx', $this->output);
        self::assertStringNotContainsString('Zzz.psx', $this->output);
    }

    /** §4 のエラー: namespace を取り出せないファイルは登録しない */
    public function testMalformedNamespaceIsReportedWithThePath(): void
    {
        $source = $this->source('Bad.psx', "<?php\nnamespace 1App;\nreturn 1;\n");

        self::assertSame(1, $this->run_());
        self::assertStringContainsString($source, $this->output);
        self::assertStringContainsString('namespace', $this->output);
    }

    /** §3 のテスト: `style`、`script` の中身で素の PHP として壊れるソース */
    public function testRawTextThatBreaksPhpStillResolvesTheNamespace(): void
    {
        $this->source('Styled.psx', "<?php\nnamespace App;\nreturn fn () => <div><style>a::after { content: \"'\" }</style><script>if (a) { b('</script></div>;\n");

        self::assertSame(0, $this->run_(), $this->output);
        self::assertSame(['App\\Styled'], \array_keys($this->loadManifest()));
    }

    /** CP-114 */
    public function testCachePathForIsStable(): void
    {
        $source = $this->component('Counter');

        self::assertSame('/c/' . \sha1($source) . '.php', CompileCommand::cachePathFor('/c', $source));
        self::assertSame('/c/' . \sha1($source) . '.php', CompileCommand::cachePathFor('/c/', $source));
        self::assertSame(
            '/c/' . \sha1($source) . '.php',
            CompileCommand::cachePathFor('/c', $this->dir . '/components/../components/Counter.psx'),
        );
        self::assertSame('/c/' . \sha1('/no/such.psx') . '.php', CompileCommand::cachePathFor('/c', '/no/such.psx'));
        self::assertSame('/c/' . \sha1($source) . '.php.meta', CompileCommand::metaPathFor('/c', $source));
    }

    /** CP-126: メッセージは標準出力に書く */
    public function testMessagesGoToStandardOutput(): void
    {
        $this->component('Counter');

        self::assertSame(0, $this->run_());
        self::assertStringContainsString('Compiled 1', $this->output);
        self::assertStringContainsString($this->manifest, $this->output);
    }
}
