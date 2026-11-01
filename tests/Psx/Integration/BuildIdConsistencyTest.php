<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Tests\Psx\Support\TemporaryDirectory;

/**
 * CP-136、CP-144: `rosanna compile` と `rosanna-state compile` を同じキャッシュで使う。
 * ファイルの有無だけを見ると、新しい manifest と古い deferred manifest の組み合わせを受け入れてしまう。
 */
#[Group('integration')]
final class BuildIdConsistencyTest extends TestCase
{
    private const string GUIDE = 'rosanna-state compile';

    private string $dir;

    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stdout;

    private string $buffer = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-build-id-');
        \mkdir($this->dir . '/components');
        $this->source();
    }

    protected function tearDown(): void
    {
        $this->stopWatching();
        RequestLifecycle::reset();
        TemporaryDirectory::remove($this->dir);
    }

    private function source(string $text = 'deferred', int $mtimeOffset = 0): void
    {
        $path = $this->dir . '/components/Foo.psx';
        \file_put_contents($path, "<?php\nnamespace App;\n"
            . "use Polidog\\Rosanna\\Defer\\Defer;\n"
            . "use function Polidog\\Rosanna\\State\\fc;\n"
            . "return fc(fn (array \$props) => <i>$text</i>, key: 'foo', defer: new Defer('foo'));\n");
        \touch($path, \time() + $mtimeOffset);
    }

    private function manifest(): string
    {
        return $this->dir . '/var/cache/psx/manifest.php';
    }

    private function deferred(): string
    {
        return $this->dir . '/var/cache/psx/deferred-manifest.php';
    }

    private static function rosannaBin(): string
    {
        return \dirname(__DIR__, 3) . '/vendor/polidog/rosanna/bin/rosanna';
    }

    private static function stateBin(): string
    {
        return \dirname(__DIR__, 3) . '/bin/rosanna-state';
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function run_(string $bin, array $arguments = []): array
    {
        $process = \proc_open([\PHP_BINARY, $bin, 'compile', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);

        return [\proc_close($process), $stdout . $stderr];
    }

    private function compile(string $bin): void
    {
        [$code, $output] = $this->run_($bin);
        self::assertSame(0, $code, $output);
    }

    private function assertRefused(string $message = self::GUIDE): void
    {
        try {
            new RosannaState()->loadComponentManifest($this->manifest());
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($message, $e->getMessage());

            return;
        }
        self::fail('Expected RosannaState to refuse the artifacts');
    }

    /** 受け入れるだけでなく、deferred manifest の登録が効いている */
    private function assertAccepted(string $body = 'deferred'): void
    {
        $response = new RosannaState()->loadComponentManifest($this->manifest())->handleDeferred(new Request('GET', '/_defer/foo', '/_defer/foo', ''));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString("<i>$body</i>", $response->body);
        self::assertSame(PsxRenderer::readBuildId($this->manifest()), (require $this->deferred())[PsxRenderer::BUILD_ID_KEY]);
    }

    public function testRosannaCompileAloneIsRefused(): void
    {
        $this->compile(self::rosannaBin());

        self::assertFileExists($this->manifest());
        self::assertFileDoesNotExist($this->deferred());
        $this->assertRefused();
    }

    /** 本体の `Rosanna` は同じ成果物を受ける */
    public function testRosannaCompileIsAcceptedByTheRenderingLayer(): void
    {
        $this->compile(self::rosannaBin());

        self::assertInstanceOf(Rosanna::class, new Rosanna()->loadComponentManifest($this->manifest()));
    }

    public function testStateCompileIsAccepted(): void
    {
        $this->compile(self::stateBin());

        $this->assertAccepted();
    }

    public function testRosannaCompileAfterStateCompileIsRefused(): void
    {
        $this->compile(self::stateBin());
        $this->compile(self::rosannaBin());

        self::assertFileDoesNotExist($this->deferred());
        $this->assertRefused();
    }

    /** 古い deferred manifest が残った (消せなかった、デプロイで戻った) 組み合わせ */
    public function testLeftoverDeferredManifestWithAnotherBuildIdIsRefused(): void
    {
        $this->compile(self::stateBin());
        $old = (string) \file_get_contents($this->deferred());
        $oldId = PsxRenderer::readBuildId($this->manifest());

        $this->source('changed');
        $this->compile(self::rosannaBin());
        \file_put_contents($this->deferred(), $old);

        self::assertNotSame($oldId, PsxRenderer::readBuildId($this->manifest()));
        $this->assertRefused();
    }

    public function testDeferredManifestWithoutABuildIdIsRefused(): void
    {
        $this->compile(self::stateBin());
        \file_put_contents($this->deferred(), "<?php\nreturn ['foo' => ['component' => 'App\\\\Foo', 'cacheControl' => null]];\n");

        $this->assertRefused();
    }

    /** どちらにも ID がない古い成果物を、一致とみなさない */
    public function testArtifactsWithoutAnyBuildIdAreRefused(): void
    {
        $this->compile(self::stateBin());
        \file_put_contents($this->manifest(), "<?php\nreturn [];\n");
        \file_put_contents($this->deferred(), "<?php\nreturn [];\n");

        $this->assertRefused();
    }

    /** 通常の実行を交互に */
    public function testAlternatingNormalRuns(): void
    {
        $ids = [];
        foreach ([1, 2, 3] as $_) {
            $this->compile(self::stateBin());
            $this->assertAccepted();
            $ids[] = PsxRenderer::readBuildId($this->manifest());

            $this->compile(self::rosannaBin());
            self::assertFileDoesNotExist($this->deferred());
            $this->assertRefused();
            $ids[] = PsxRenderer::readBuildId($this->manifest());
        }

        // 同じ入力なので、どちらのコマンドでも同じ ID になる
        self::assertCount(1, \array_unique($ids), \implode(',', $ids));
    }

    /** `--check` を交互に。書き込まず、相手の成果物を古いと報告する */
    public function testAlternatingCheck(): void
    {
        $check = ['--check'];

        $this->compile(self::stateBin());
        [$code, $output] = $this->run_(self::stateBin(), $check);
        self::assertSame(0, $code, $output);
        [$code, $output] = $this->run_(self::rosannaBin(), $check);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('deferred-manifest.php', $output);
        // `--check` は消さない
        $this->assertAccepted();

        $this->compile(self::rosannaBin());
        [$code, $output] = $this->run_(self::rosannaBin(), $check);
        self::assertSame(0, $code, $output);
        [$code, $output] = $this->run_(self::stateBin(), $check);
        self::assertSame(1, $code, $output);
        self::assertStringContainsString('deferred-manifest.php', $output);
        self::assertFileDoesNotExist($this->deferred(), '--check must not write');
        $this->assertRefused();

        $this->compile(self::stateBin());
        [$code, $output] = $this->run_(self::stateBin(), $check);
        self::assertSame(0, $code, $output);
        $this->assertAccepted();
    }

    /** `rosanna-state compile --check` は build ID の合わない deferred manifest を古いと報告する */
    public function testStateCheckDetectsAnotherBuildId(): void
    {
        $this->compile(self::stateBin());
        $old = (string) \file_get_contents($this->deferred());
        $this->source('changed');
        $this->compile(self::stateBin());
        \file_put_contents($this->deferred(), $old);

        [$code, $output] = $this->run_(self::stateBin(), ['--check']);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('deferred-manifest.php', $output);
    }

    /** `--watch` を交互に。止まらないので子プロセスで動かす (`tests` の WatchTest と同じ形) */
    public function testAlternatingWatch(): void
    {
        $this->startWatching(self::stateBin());
        $this->waitFor('Compiled 1');
        $this->assertAccepted();
        $firstId = PsxRenderer::readBuildId($this->manifest());

        // watch の間の再コンパイルでも、両方の ID が揃う
        $this->source('changed', 2);
        $this->waitFor('Compiled 1');
        $this->assertAccepted('changed');
        self::assertNotSame($firstId, PsxRenderer::readBuildId($this->manifest()));
        $this->stopWatching();

        $this->startWatching(self::rosannaBin());
        $this->waitFor('Compiled 1');
        self::assertFileDoesNotExist($this->deferred());
        $this->assertRefused();

        // 本体の watch の再コンパイルでも、deferred manifest は戻らない
        $this->source('again', 4);
        $this->waitFor('Compiled 1');
        self::assertFileDoesNotExist($this->deferred());
        $this->assertRefused();
        $this->stopWatching();

        $this->startWatching(self::stateBin());
        $this->waitFor('Compiled 1');
        $this->assertAccepted('again');
    }

    private function startWatching(string $bin): void
    {
        $process = \proc_open([\PHP_BINARY, $bin, 'compile', '--watch'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        $this->process = $process;
        $this->stdout = $pipes[1];
        \stream_set_blocking($pipes[1], false);
        $this->buffer = '';
    }

    private function stopWatching(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process, 9);
            \proc_close($this->process);
        }
        $this->process = null;
    }

    private function waitFor(string $needle): void
    {
        \assert(\is_resource($this->stdout));
        $deadline = \microtime(true) + 10.0;
        while (\microtime(true) < $deadline) {
            $this->buffer .= (string) \stream_get_contents($this->stdout);
            // `Manifest:` の行まで出たら、この回の書き込みは終わっている
            if (\str_contains($this->buffer, $needle) && \str_contains($this->buffer, 'Manifest:')) {
                $this->buffer = '';

                return;
            }
            \usleep(50_000);
        }
        self::fail("Timed out waiting for '$needle'. Output: {$this->buffer}");
    }
}
