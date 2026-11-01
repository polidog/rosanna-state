<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * tests/Integration/fixtures/app.php を組み込みサーバーで動かし、実際の HTTP を送る。
 * Set-Cookie とセッションファイルは CLI の中では確かめられない。
 */
abstract class AppServerTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/rosanna-app-' . bin2hex(random_bytes(6));
        mkdir(self::$dir);
        mkdir(self::$dir . '/sessions');
        touch(self::$dir . '/error.log');

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        self::$port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);

        $server = proc_open([
            PHP_BINARY, '-n',
            '-d', 'session.save_path=' . self::$dir . '/sessions',
            '-d', 'session.use_strict_mode=0',
            '-d', 'output_buffering=0',
            '-d', 'log_errors=1',
            '-d', 'error_log=' . self::$dir . '/error.log',
            '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/app.php',
        ], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
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
        foreach ([...(glob(self::$dir . '/sessions/*') ?: []), self::$dir . '/error.log'] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir . '/sessions');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        foreach (self::sessionFiles() as $file) {
            unlink($file);
        }
        file_put_contents(self::$dir . '/error.log', '');
    }

    /** @return list<string> */
    protected static function sessionFiles(): array
    {
        return glob(self::$dir . '/sessions/sess_*') ?: [];
    }

    protected static function serverLog(): string
    {
        return (string) file_get_contents(self::$dir . '/error.log');
    }

    protected static function origin(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    protected static function http(string $target, string $method = 'GET', array $headers = [], string $body = ''): array
    {
        $socket = fsockopen('127.0.0.1', self::$port, $errno, $errstr, 5.0);
        self::assertNotFalse($socket, $errstr);

        $request = "{$method} {$target} HTTP/1.1\r\nHost: 127.0.0.1:" . self::$port . "\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }
        if ($method === 'POST') {
            $request .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
        }
        fwrite($socket, $request . "\r\n" . $body);

        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $responseBody] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines);

        return ['status' => (int) (explode(' ', $statusLine)[1] ?? 0), 'headers' => $lines, 'body' => $responseBody];
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     * @return array{status: int, headers: list<string>, body: string}
     */
    protected static function postForm(string $target, array $fields, ?string $cookie = null, bool $partial = false, array $headers = []): array
    {
        $headers += ['Origin' => self::origin()];
        if ($cookie !== null) {
            $headers['Cookie'] = $cookie;
        }
        if ($partial) {
            $headers['X-Rosanna-Partial'] = '1';
        }

        return self::http($target, 'POST', $headers, http_build_query($fields));
    }

    /** @param array{headers: list<string>} $response */
    protected static function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /** @param array{headers: list<string>} $response */
    protected static function cookieOf(array $response): ?string
    {
        $value = self::header($response, 'Set-Cookie');

        return $value === null ? null : trim(explode(';', $value)[0]);
    }
}
