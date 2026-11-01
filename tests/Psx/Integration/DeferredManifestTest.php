<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\State\DeferredManifestCompiler;
use Polidog\Rosanna\State\Http\Request;

/**
 * CP-135、CP-136: `fc(defer: new Defer(...))` の収集と、ランタイムの読み込み (DF-13、RT-11.5)。
 */
#[Group('integration')]
final class DeferredManifestTest extends TestCase
{
    use RendersPsx;

    private function deferred(string $name, ?string $cacheControl = null): string
    {
        $cache = $cacheControl === null ? '' : ', cacheControl: ' . \var_export($cacheControl, true);

        return "<?php\nnamespace App;\n"
            . "use Polidog\\Rosanna\\State\\Component\\Defer;\n"
            . "use function Polidog\\Rosanna\\State\\fc;\n"
            . "return fc(fn (array \$props) => <i>deferred</i>, key: '$name', defer: new Defer('$name'$cache));\n";
    }

    private function deferredPath(): string
    {
        return $this->cacheDir() . '/deferred-manifest.php';
    }

    /** CP-135 と §8 の形式 */
    public function testCollectsDeferredComponents(): void
    {
        self::assertSame(0, $this->build([
            'UserHeader' => $this->deferred('user-header', 'private, no-store'),
            'Plain' => "<?php\nnamespace App;\nreturn fn (array \$props) => <i />;\n",
            'Zeta' => $this->deferred('a-first'),
        ]), $this->commandOutput);

        self::assertSame(
            [
                PsxRenderer::BUILD_ID_KEY => PsxRenderer::readBuildId($this->cacheDir() . '/manifest.php'),
                'a-first' => ['component' => 'App\\Zeta', 'cacheControl' => null],
                'user-header' => ['component' => 'App\\UserHeader', 'cacheControl' => 'private, no-store'],
            ],
            require $this->deferredPath(),
        );

        $lines = (array) \file($this->deferredPath(), \FILE_IGNORE_NEW_LINES);
        self::assertSame('<?php', $lines[0]);
        self::assertStringContainsString('Auto-generated', (string) $lines[1]);
    }

    /** CP-136: 空でも必ず書き、同じ build ID を持つ */
    public function testDeferredManifestIsEmptiedWhenNoEntriesRemain(): void
    {
        self::assertSame(0, $this->build(['Foo' => $this->deferred('foo')]));
        self::assertFileExists($this->deferredPath());

        self::assertSame(0, $this->build(['Foo' => "<?php\nnamespace App;\nreturn fn (array \$props) => <i />;\n"]), $this->commandOutput);
        self::assertSame([], DeferredManifestCompiler::read($this->cacheDir() . '/manifest.php'));
        self::assertSame([PsxRenderer::BUILD_ID_KEY => PsxRenderer::readBuildId($this->cacheDir() . '/manifest.php')], require $this->deferredPath());
    }

    /** CP-122: 同じ内容なら書かない */
    public function testDeferredManifestIsNotRewritten(): void
    {
        self::assertSame(0, $this->build(['Foo' => $this->deferred('foo')]));
        \touch($this->deferredPath(), 1_000_000);

        self::assertSame(0, $this->build([]));
        \clearstatcache();
        self::assertSame(1_000_000, \filemtime($this->deferredPath()));
    }

    /** §8 のエラー: 名前の重複は exit 1 で、両方の FQCN を表示する */
    public function testDuplicateDeferredNameExitsOne(): void
    {
        self::assertSame(1, $this->build([
            'First' => $this->deferred('same'),
            'Second' => $this->deferred('same'),
        ]));
        self::assertStringContainsString("'same'", $this->commandOutput);
        self::assertStringContainsString('App\\First', $this->commandOutput);
        self::assertStringContainsString('App\\Second', $this->commandOutput);
    }

    /** CP-123: 変更がなければ `--check` が通り、deferred manifest が古ければ落ちる */
    public function testCheckCoversTheDeferredManifest(): void
    {
        self::assertSame(0, $this->build(['Foo' => $this->deferred('foo')]));
        $run = function (): int {
            \ob_start();
            $code = new \Polidog\Rosanna\Psx\CompileCommand(new DeferredManifestCompiler()->compile(...))->run(['--check', '--cache=' . $this->cacheDir(), $this->workDir() . '/components'], $this->workDir());
            $this->commandOutput = (string) \ob_get_clean();

            return $code;
        };

        self::assertSame(0, $run(), $this->commandOutput);

        \unlink($this->deferredPath());
        self::assertSame(1, $run());
        self::assertStringContainsString('deferred-manifest.php', $this->commandOutput);
        self::assertFileDoesNotExist($this->deferredPath());
    }

    /** DF-13、RT-11.5: `loadComponentManifest()` が登録し、エンドポイントで描画できる */
    public function testRuntimeRegistersFromTheDeferredManifest(): void
    {
        $app = $this->buildApp(['Foo' => $this->deferred('foo', 'private, max-age=60')]);

        $response = $app->handleDeferred(new Request('GET', '/_defer/foo', '/_defer/foo', ''));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<i>deferred</i>', $response->body);
        self::assertSame('private, max-age=60', $response->headers['Cache-Control'] ?? null);
    }
}
