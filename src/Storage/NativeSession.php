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
    /** release() で手放した ID。次の start() まで持つ */
    private static ?string $releasedId = null;

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
            self::adoptCookieAfterRelease();
            @session_start();
        }

        return self::isActive();
    }

    /**
     * 書き込んで閉じ、ID も手放す (RT-9.6)。PHP は閉じた後も ID を覚えていて次の session_start() で Cookie より優先する。
     */
    public static function release(): void
    {
        if (self::isActive()) {
            session_write_close();
        }

        $id = session_id();
        if ($id === false || $id === '') {
            return;
        }

        self::$releasedId = $id;
        // ヘッダーの送信後は変えられない。残っても次の start() が入れ替える
        @session_id('');
        $_SESSION = [];
    }

    /** 空の ID では PHP が Cookie を読まず新しい ID を作るので、次の訪問者の Cookie を渡す。host が決めた ID は変えない */
    private static function adoptCookieAfterRelease(): void
    {
        if (self::$releasedId === null) {
            return;
        }

        $id = session_id();
        if ($id === '' || $id === self::$releasedId) {
            $cookie = self::hasCookie() ? $_COOKIE[session_name()] : '';
            @session_id(preg_match('/^[A-Za-z0-9,-]+$/D', $cookie) === 1 ? $cookie : '');
        }
        self::$releasedId = null;
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
