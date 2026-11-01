<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

/**
 * PHP のネイティブセッションの開始と再開の判断を 1 か所に置く (U-6)。
 *
 * @internal
 */
final class NativeSession
{
    public static function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * 開始できないときは false。壊れた Cookie での警告はクライアントの入力によるものなので抑え、判断は呼び出し側に任せる。
     */
    public static function start(): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }

        return self::isActive();
    }

    /** @param array<string, string>|null $cookies null なら $_COOKIE を見る */
    public static function hasCookie(?array $cookies = null): bool
    {
        $name = session_name();
        if ($name === false) {
            return false;
        }
        $cookie = ($cookies ?? $_COOKIE)[$name] ?? null;

        return is_string($cookie) && $cookie !== '';
    }

    /** @param array<string, string>|null $cookies null なら $_COOKIE を見る */
    public static function resumeFromCookie(?array $cookies = null): void
    {
        if (self::hasCookie($cookies)) {
            self::start();
        }
    }
}
