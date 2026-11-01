<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx;

use PHPUnit\Framework\TestCase;

/**
 * CP-1、CP-2: 依存の向き。パッケージは 1 つでも、描画に php-parser を読み込まない境界は残す。
 */
final class ReferenceDirectionTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** CP-2: `src/` のうち `src/Psx/` の外から `Polidog\Rosanna\Psx` と `PhpParser` を参照しない */
    public function testRuntimeDoesNotReferenceTheCompiler(): void
    {
        $scanned = 0;
        $offenders = [];
        $src = \dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if ($entry->getExtension() !== 'php' || \str_starts_with($entry->getPathname(), "$src/Psx/")) {
                continue;
            }
            ++$scanned;
            // コメントでの言及は参照ではない。文字列の中の `\\` の表記は参照として数える
            $code = '';
            foreach (\token_get_all((string) \file_get_contents($entry->getPathname())) as $token) {
                if (!\is_array($token)) {
                    $code .= $token;
                } elseif ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $code .= $token[1];
                }
            }
            if (\preg_match('/Polidog\\\\+Rosanna\\\\+Psx(?![A-Za-z0-9_])|(?<![A-Za-z0-9_])PhpParser(?![A-Za-z0-9_])/', $code) === 1) {
                $offenders[] = $entry->getPathname();
            }
        }

        self::assertGreaterThan(0, $scanned);
        self::assertSame([], $offenders);
    }

    /** CP-1 */
    public function testRequirements(): void
    {
        $composer = \json_decode((string) \file_get_contents(self::ROOT . '/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($composer));

        self::assertSame(
            // CP-106 の列と `^` の表示幅に mbstring を使う
            ['php' => '>=8.5', 'ext-mbstring' => '*', 'nikic/php-parser' => '^5.7'],
            $composer['require'],
        );
    }

    /** §1: 差し替え用のインターフェースは作らない */
    public function testNoCompilerInterfaces(): void
    {
        foreach (['CompilerInterface', 'PsxParserFactoryInterface', 'PsxPreProcessorInterface', 'PsxParserInterface'] as $name) {
            self::assertFileDoesNotExist(self::ROOT . "/src/Psx/$name.php");
        }
    }

    /** §1: 公開 API は Compiler と CompileCommand だけ */
    public function testEverythingElseIsInternal(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src/Psx', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            \assert($entry instanceof \SplFileInfo);
            if (\in_array($entry->getFilename(), ['Compiler.php', 'CompileCommand.php'], true)) {
                continue;
            }
            self::assertStringContainsString('@internal', (string) \file_get_contents($entry->getPathname()), $entry->getPathname());
        }
    }
}
