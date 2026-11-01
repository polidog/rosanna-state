<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Storage;

/**
 * 別プロセスのテストのセッションを、テストごとの一時ディレクトリに閉じ込める。
 */
trait SessionTestHelper
{
    private static function isolateSessions(): string
    {
        $dir = sys_get_temp_dir() . '/rosanna-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        session_save_path($dir);
        ini_set('session.use_cookies', '1');
        ini_set('session.cache_limiter', '');
        $_COOKIE = [];

        return $dir;
    }

    /** Cookie を持つ訪問者を作る */
    private static function visitorWithCookie(string $sessionId): void
    {
        session_id($sessionId);
        $_COOKIE[(string) session_name()] = $sessionId;
    }

    /** @param array<string, mixed> $values */
    private static function seedSession(string $sessionId, array $values): void
    {
        session_id($sessionId);
        session_start();
        foreach ($values as $key => $value) {
            $_SESSION[$key] = $value;
        }
        session_write_close();
        $_SESSION = [];
        session_id('');
    }
}
