<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Psx\Support;

/**
 * コンパイル結果を、スタブのランタイムを読んだ子プロセスで評価する。
 */
final class StubRuntime
{
    /**
     * @param array<string, mixed> $props 返り値がクロージャのときに渡す引数
     *
     * @return array<string, mixed> `value`、または `thrown`、`message`、`line`
     */
    public static function evaluate(string $compiled, array $props = []): array
    {
        $file = \tempnam(\sys_get_temp_dir(), 'psx-stub-');
        \assert($file !== false);
        \file_put_contents($file, $compiled);

        try {
            $process = \proc_open(
                [\PHP_BINARY, __DIR__ . '/stub-runtime.php', $file, \json_encode((object) $props, \JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            \assert(\is_resource($process));
            $stdout = (string) \stream_get_contents($pipes[1]);
            $stderr = (string) \stream_get_contents($pipes[2]);
            \proc_close($process);
        } finally {
            \unlink($file);
        }

        $result = \json_decode($stdout, true);
        if (!\is_array($result)) {
            throw new \RuntimeException("Stub runtime failed: $stdout $stderr");
        }

        /** @var array<string, mixed> $result */
        return $result;
    }
}
