<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * CP-125: `--watch` は止まらないので、子プロセスで動かす。
 */
final class WatchTest extends TestCase
{
    private string $dir;

    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stdout;

    private string $buffer = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-watch-');
        \mkdir($this->dir . '/components');
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process, 9);
            \proc_close($this->process);
        }
        TemporaryDirectory::remove($this->dir);
    }

    public function testRecompilesOnChangeSurvivesFailuresAndDoesNotRepeatErrors(): void
    {
        $source = $this->dir . '/components/Foo.psx';
        \file_put_contents($source, '<?php return fn () => <div />;');

        $this->start();
        $this->waitFor('Compiled 1');
        $manifest = $this->dir . '/var/cache/psx/manifest.php';
        self::assertFileExists($manifest);

        // 失敗しても終了しない
        $this->change($source, '<?php return <a></b>;', 1);
        $this->waitFor('Mismatched closing tag');
        $this->buffer = '';

        // CP-125: 失敗の後、変更がなければ同じエラーを繰り返さない
        \usleep(1_300_000);
        $this->drain();
        self::assertStringNotContainsString('Mismatched', $this->buffer);
        self::assertTrue(\proc_get_status($this->process)['running']);

        // 直すと再びコンパイルする
        $this->change($source, '<?php return fn () => <span />;', 2);
        $this->waitFor('Compiled 1');

        // 一覧の変化を検出する
        $this->buffer = '';
        \file_put_contents($this->dir . '/components/Bar.psx', '<?php return fn () => <i />;');
        $this->waitFor('Compiled 2');

        \unlink($this->dir . '/components/Bar.psx');
        $this->waitFor('Compiled 1');
    }

    private function start(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = 'require ' . \var_export($autoload, true) . ';'
            . 'exit(new \\Polidog\\Rosanna\\Psx\\CompileCommand()->run(["--watch"], ' . \var_export($this->dir, true) . '));';

        $process = \proc_open([\PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $this->stdout = $pipes[1];
        \stream_set_blocking($pipes[1], false);
    }

    private function change(string $path, string $code, int $seconds): void
    {
        \file_put_contents($path, $code);
        // mtime の粒度は 1 秒なので、確実に変わるようにする
        \touch($path, \time() + $seconds);
    }

    private function drain(): void
    {
        \assert(\is_resource($this->stdout));
        $this->buffer .= (string) \stream_get_contents($this->stdout);
    }

    private function waitFor(string $needle): void
    {
        $deadline = \microtime(true) + 10.0;
        while (\microtime(true) < $deadline) {
            $this->drain();
            if (\str_contains($this->buffer, $needle)) {
                $this->buffer = '';

                return;
            }
            \usleep(50_000);
        }
        self::fail("Timed out waiting for '$needle'. Output: {$this->buffer}");
    }
}
