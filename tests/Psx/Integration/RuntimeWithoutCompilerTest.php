<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Tests\Psx\Support\TemporaryDirectory;

/**
 * CP-2、§8 の目的: コンパイル済みの成果物と manifest があれば、コンパイラと php-parser を読み込まずに描画できる。
 * `vendor/autoload.php` からは両方を読めるので、どのクラスが読み込まれたかを子プロセスで数える。
 */
#[Group('integration')]
final class RuntimeWithoutCompilerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = TemporaryDirectory::create('psx-no-compiler-');
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->dir);
    }

    public function testRenderingDoesNotLoadTheCompiler(): void
    {
        $result = $this->render();

        self::assertSame('<b>ok</b>', $result['html']);
        self::assertFalse($result['parserFactoryLoaded']);
        self::assertFalse($result['commandLoaded']);
        self::assertSame([], $result['compilerClasses']);
    }

    /** 検出そのものの確認: コンパイラを動かせば同じ数え方で見つかる */
    public function testCompilingLoadsTheCompiler(): void
    {
        $result = $this->render("(new \\Polidog\\Rosanna\\Psx\\Compiler())->compile('<?php return fn () => <i />;');");

        self::assertTrue($result['parserFactoryLoaded']);
        self::assertContains('Polidog\\Rosanna\\Psx\\Compiler', $result['compilerClasses']);
        self::assertNotEmpty(\array_filter($result['compilerClasses'], static fn(string $class): bool => \str_starts_with($class, 'PhpParser\\')));
    }

    /**
     * @return array{html: string, parserFactoryLoaded: bool, commandLoaded: bool, compilerClasses: list<string>}
     */
    private function render(string $beforeCounting = ''): array
    {
        \mkdir($this->dir . '/components');
        \file_put_contents($this->dir . '/components/Foo.psx', <<<'PSX'
            <?php
            declare(strict_types=1);
            namespace App;
            return fn (array $props) => <b>{$props['v']}</b>;
            PSX);

        \ob_start();
        $code = new CompileCommand()->run(['--cache=' . $this->dir . '/cache', 'components'], $this->dir);
        $output = (string) \ob_get_clean();
        self::assertSame(0, $code, $output);

        $script = $this->dir . '/script.php';
        \file_put_contents($script, \strtr(<<<'PHP'
            <?php
            declare(strict_types=1);
            require %AUTOLOAD%;

            $app = new \Polidog\Rosanna\Rosanna();
            $app->loadComponentManifest(%MANIFEST%);
            $html = \Polidog\Rosanna\Runtime\RenderContext::run(
                $app->scope(new \Polidog\Rosanna\Http\Request('GET', '/', '/', '')),
                static fn () => $app->renderElement(
                    \Polidog\Rosanna\Runtime\RenderContext::scope()->psx->render('App\\Foo', ['v' => 'ok']),
                ),
            );
            %BEFORE_COUNTING%

            $declared = [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()];
            echo json_encode([
                'html' => $html,
                'parserFactoryLoaded' => class_exists('PhpParser\\ParserFactory', false),
                'commandLoaded' => class_exists('Polidog\\Rosanna\\Psx\\CompileCommand', false),
                'compilerClasses' => array_values(array_filter(
                    $declared,
                    static fn (string $class): bool => str_starts_with($class, 'PhpParser\\') || str_starts_with($class, 'Polidog\\Rosanna\\Psx\\'),
                )),
            ], JSON_THROW_ON_ERROR);
            PHP, [
            '%AUTOLOAD%' => \var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            '%MANIFEST%' => \var_export($this->dir . '/cache/manifest.php', true),
            '%BEFORE_COUNTING%' => $beforeCounting,
        ]));

        $process = \proc_open([\PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        self::assertSame(0, \proc_close($process), $stdout . $stderr);

        /** @var array{html: string, parserFactoryLoaded: bool, commandLoaded: bool, compilerClasses: list<string>} */
        return \json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
    }
}
