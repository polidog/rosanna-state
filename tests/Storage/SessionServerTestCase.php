<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Storage;

use PHPUnit\Framework\TestCase;

/**
 * 組み込みサーバーの子プロセスに実際の HTTP を送る。
 */
abstract class SessionServerTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $sessionDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$sessionDir = sys_get_temp_dir() . '/rosanna-server-' . bin2hex(random_bytes(6));
        mkdir(self::$sessionDir);

        // 空いている port を OS に選ばせる
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $command = [
            PHP_BINARY, '-n', '-d', 'session.save_path=' . self::$sessionDir, '-d', 'session.use_strict_mode=0',
            '-d', 'output_buffering=0', '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/session_app.php',
        ];
        $server = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($server);
        self::$server = $server;

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('The built-in server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (glob(self::$sessionDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(self::$sessionDir);
    }

    /** @return list<string> */
    protected static function sessionFiles(): array
    {
        return glob(self::$sessionDir . '/sess_*') ?: [];
    }

    protected static function removeSessionFiles(): void
    {
        foreach (self::sessionFiles() as $file) {
            unlink($file);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    protected static function http(string $target, string $method = 'GET', array $headers = [], string $body = ''): array
    {
        $socket = fsockopen('127.0.0.1', self::$port, $errno, $errstr, 2.0);
        self::assertNotFalse($socket, $errstr);

        $request = "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        if ($body !== '') {
            $request .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n" . 'Content-Length: ' . strlen($body) . "\r\n";
        }
        fwrite($socket, $request . "\r\n" . $body);

        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines);

        return ['status' => (int) (explode(' ', $statusLine)[1] ?? 0), 'headers' => $lines, 'body' => $responseBody];
    }

    /** @param array{headers: list<string>} $response */
    protected static function setCookieOf(array $response): ?string
    {
        foreach ($response['headers'] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                return trim(explode(';', substr($line, strlen('Set-Cookie:')))[0]);
            }
        }

        return null;
    }
}
