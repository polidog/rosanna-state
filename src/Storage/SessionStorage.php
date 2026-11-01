<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Storage;

/**
 * $_SESSION に置くストレージ。読み取りはセッションを開始しない (ST-2)。
 *
 * 非アクティブな $_SESSION は読まない。閉じたセッションの残りや、ワーカーの前のリクエストの値かもしれないため。
 */
final class SessionStorage implements StateStorageInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        NativeSession::resumeFromCookie();
        if (!NativeSession::isActive() || !array_key_exists($key, $_SESSION)) {
            return $default;
        }

        return $_SESSION[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $this->requireSession();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        NativeSession::resumeFromCookie();

        return NativeSession::isActive() && array_key_exists($key, $_SESSION);
    }

    public function delete(string $key): void
    {
        if ($this->sessionForRemoval()) {
            unset($_SESSION[$key]);
        }
    }

    public function clearByPrefix(string $prefix): void
    {
        if (!$this->sessionForRemoval()) {
            return;
        }
        foreach (array_keys($_SESSION) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * 消すものがないのにセッションを始めない。Cookie があるのに開始できなければ、消せなかったことを隠さない。
     */
    private function sessionForRemoval(): bool
    {
        if (!NativeSession::isActive() && !NativeSession::hasCookie()) {
            return false;
        }
        $this->requireSession();

        return true;
    }

    private function requireSession(): void
    {
        if (!NativeSession::start()) {
            throw new \LogicException(
                'Cannot write Session state: the PHP session could not be started. '
                . 'Output may already have been sent; render Session components before any output.',
            );
        }
    }
}
