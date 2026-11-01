<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Integration;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Psx\Compiler;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * コンパイル結果を本物のランタイムで描画するためのヘルパー。
 * API は 01-runtime.md の RT-9、RT-11、RT-12 に合わせている。
 */
trait RendersPsx
{
    private ?string $workDir = null;

    private string $commandOutput = '';

    protected function tearDown(): void
    {
        try {
            RequestLifecycle::reset();
        } finally {
            if ($this->workDir !== null) {
                TemporaryDirectory::remove($this->workDir);
                $this->workDir = null;
            }
        }
    }

    private function workDir(): string
    {
        return $this->workDir ??= TemporaryDirectory::create('psx-integration-');
    }

    private function request(): Request
    {
        return new Request('GET', '/', '/', '');
    }

    /**
     * RT-12.8: スコープの中で $fn を動かす。
     */
    private function inScope(Rosanna $app, callable $fn): mixed
    {
        return RenderContext::run($app->scope($this->request()), $fn);
    }

    /**
     * `declare(strict_types=1)` のファイルの `fn (array $p) => PSX` をコンパイルして呼ぶ。
     *
     * @param array<string, mixed> $props
     */
    private function element(string $psx, array $props = [], ?Rosanna $app = null): mixed
    {
        $file = $this->workDir() . '/' . \bin2hex(\random_bytes(6)) . '.php';
        \file_put_contents($file, new Compiler()->compile(
            "<?php\ndeclare(strict_types=1);\nreturn fn (array \$p) => $psx;\n",
        ));

        $component = require $file;
        \assert($component instanceof \Closure);

        return $this->inScope($app ?? new Rosanna(), static fn(): mixed => $component($props));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function html(string $psx, array $props = [], ?Rosanna $app = null): string
    {
        $app ??= new Rosanna();
        $element = $this->element($psx, $props, $app);
        \assert($element instanceof Element);

        $html = $this->inScope($app, static fn(): string => $app->renderElement($element));
        \assert(\is_string($html));

        return $html;
    }

    /**
     * `components/` に `.psx` を置き、CompileCommand でコンパイルして読み込む。
     *
     * @param array<string, string> $files ファイル名 (拡張子なし) => ソース
     */
    private function buildApp(array $files): Rosanna
    {
        $code = $this->build($files);
        if ($code !== 0) {
            throw new \RuntimeException("compile failed: {$this->commandOutput}");
        }

        return new Rosanna()->loadComponentManifest($this->cacheDir() . '/manifest.php');
    }

    /**
     * @param array<string, string> $files
     */
    private function build(array $files): int
    {
        $dir = $this->workDir() . '/components';
        if (!\is_dir($dir)) {
            \mkdir($dir);
        }
        foreach ($files as $name => $code) {
            \file_put_contents("$dir/$name.psx", $code);
        }

        \ob_start();
        $code = new CompileCommand()->run(['--cache=' . $this->cacheDir(), $dir], $this->workDir());
        $this->commandOutput = (string) \ob_get_clean();

        return $code;
    }

    private function cacheDir(): string
    {
        return $this->workDir() . '/cache';
    }
}
