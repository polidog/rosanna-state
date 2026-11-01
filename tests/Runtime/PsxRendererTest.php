<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Defer\DeferredManifestCompiler;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Runtime\StackTraceRewriter;
use Polidog\Rosanna\State\Tests\Runtime\Support\RendersInScope;

/** RT-11、CP-150〜152 */
final class PsxRendererTest extends TestCase
{
    use RendersInScope;

    private string $dir;

    protected function setUp(): void
    {
        RequestLifecycle::reset();
        $this->dir = sys_get_temp_dir() . '/rosanna-psx-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/cache', recursive: true);
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, array<string, mixed>> $entries */
    private function manifest(array $entries): string
    {
        $path = $this->dir . '/cache/' . PsxRenderer::MANIFEST_FILENAME;
        file_put_contents($path, '<?php return ' . var_export($entries, true) . ';');

        return $path;
    }

    private function compiled(string $name, string $code): string
    {
        $path = "{$this->dir}/{$name}.php";
        file_put_contents($path, $code);

        return $path;
    }

    private function psxWith(string $fqcn, string $code, array $parameters = []): PsxRenderer
    {
        $psx = new PsxRenderer();
        $psx->loadManifest($this->manifest([$fqcn => ['file' => $this->compiled(md5($fqcn), $code), 'parameters' => $parameters]]));

        return $psx;
    }

    public function testRT113ResolverReachesInsideFc(): void
    {
        $code = <<<'PHP'
            <?php
            use function Polidog\Rosanna\State\fc;
            use Polidog\Rosanna\Runtime\Element;
            return fc(static fn(array $props, string $extra) => new Element('p', [], [$props['a'], $extra]), 'k');
            PHP;
        $psx = $this->psxWith('App\\C', $code, [['kind' => 'props', 'name' => 'props'], ['kind' => 'service', 'name' => 'extra', 'service' => 'X']]);
        $resolver = self::resolver(['injected']);
        $psx->setArgumentResolver($resolver);

        $wrapper = $psx->render('App\\C', ['a' => 'prop', 'key' => 'row']);

        self::assertStringEndsWith('.php:4#k/row', $wrapper->props['data-rosanna'], 'the instance id is still from the component');
        self::assertSame(['prop', 'injected'], $wrapper->children[0]->children);
        self::assertSame('App\\C', $resolver->seen[0]);
        self::assertSame(['a' => 'prop'], $resolver->seen[2]);
        self::assertSame('X', $resolver->seen[1][1]['service']);
    }

    public function testRT115DeferredManifestEntriesAreReturned(): void
    {
        $path = $this->manifest([PsxRenderer::BUILD_ID_KEY => 'id', 'App\\C' => ['file' => '/x.php', 'parameters' => []]]);
        file_put_contents(
            dirname($path) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME,
            '<?php return ["#build-id" => "id", "user-header" => ["component" => "App\\\\C", "cacheControl" => "private, no-store"], "other" => ["component" => "App\\\\D", "cacheControl" => null]];',
        );

        self::assertSame([
            'user-header' => ['component' => 'App\\C', 'cacheControl' => 'private, no-store'],
            'other' => ['component' => 'App\\D', 'cacheControl' => null],
        ], DeferredManifestCompiler::read($path));
    }

    public function testRT115NoDeferredManifestIsEmpty(): void
    {
        // ファイルがないときは例外になった (BuildIdConsistencyTest)。空でも必ず書くので、defer なしはこの形になる
        $path = $this->manifest([PsxRenderer::BUILD_ID_KEY => 'id']);
        file_put_contents(dirname($path) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME, '<?php return ["#build-id" => "id"];');

        self::assertSame([], DeferredManifestCompiler::read($path));
    }

    public function testRT115BadDeferredEntries(): void
    {
        $codes = [
            '<?php return 1;' => 'did not return an array',
            '<?php return ["#build-id" => "id", "a" => "x"];' => 'Invalid deferred manifest entry',
            '<?php return ["#build-id" => "id", "a" => ["component" => 1]];' => 'Invalid deferred manifest entry',
            '<?php return ["#build-id" => "id", "a" => ["component" => "C", "cacheControl" => 1]];' => 'Invalid deferred manifest entry',
        ];
        foreach ($codes as $code => $message) {
            $path = $this->manifest([PsxRenderer::BUILD_ID_KEY => 'id']);
            file_put_contents(dirname($path) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME, $code);
            try {
                DeferredManifestCompiler::read($path);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $code);
                continue;
            }
            self::fail($code);
        }
    }

    public function testCP150To152ActualCompiledArtifactMapsBackToThePsxSource(): void
    {
        $source = $this->dir . '/src/Boom.psx';
        mkdir(dirname($source));
        file_put_contents($source, <<<'PSX'
            <?php

            namespace App\Psx;

            use function Polidog\Rosanna\State\fc;

            return fc(function (array $props) {
                throw new \DomainException('from psx');
            });
            PSX);

        $cache = $this->dir . '/cache';
        ob_start();
        $exit = new CompileCommand(new DeferredManifestCompiler()->compile(...))->run(['--cache=' . $cache, $this->dir . '/src'], $this->dir);
        $output = (string) ob_get_clean();
        self::assertSame(0, $exit, $output);

        $psx = new PsxRenderer();
        $psx->loadManifest($cache . '/' . PsxRenderer::MANIFEST_FILENAME);

        try {
            $psx->render('App\\Psx\\Boom');
            self::fail();
        } catch (\DomainException $e) {
            $artifact = $e->getFile();
            self::assertStringEndsNotWith('.psx', $artifact);
            $real = (string) realpath($source);
            self::assertSame($real, StackTraceRewriter::rewriteFile($artifact));
            self::assertSame(8, $e->getLine(), 'CP-101: the lines match the source');

            $formatted = StackTraceRewriter::formatException(new \RuntimeException('outer', previous: $e));
            self::assertStringContainsString("DomainException: from psx at {$real}:8", $formatted);
            self::assertStringContainsString('Caused by: DomainException', $formatted);
            self::assertStringNotContainsString($artifact, $formatted, 'closure names carry the path too');
        }
    }

    /** @param list<mixed> $arguments */
    private static function resolver(array $arguments): PsxArgumentResolver
    {
        return new class ($arguments) implements PsxArgumentResolver {
            /** @var list<mixed> */
            public array $seen = [];

            /** @param list<mixed> $arguments */
            public function __construct(private readonly array $arguments) {}

            public function resolve(string $fqcn, array $metadata, array $props): array
            {
                $this->seen = [$fqcn, $metadata, $props];

                return $this->arguments;
            }
        };
    }
}
