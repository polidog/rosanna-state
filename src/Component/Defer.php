<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Component;

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

/**
 * defer のエンドポイントの宣言。クラスの属性としても、`fc(defer: new Defer(...))` の値としても使う。
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Defer
{
    /** 区切りのない形。URL のパスの 1 段になるので URL に安全な文字だけを許す */
    public const NAME_PATTERN = '[A-Za-z0-9_-]+';

    /**
     * @param string $name 登録名。prefix の下のパスになり、アプリの中で一意にする
     * @param string|null $cacheControl エンドポイントの Cache-Control。クライアントの localStorage のキャッシュとは別
     * @param bool $localCache クライアントの localStorage に保存する
     * @param bool $reloadable 解決後もラッパーを残し、後から取得し直せるようにする
     * @param int $localCacheTtl localStorage の保存の秒数。0 以下は期限なしで、0 に揃える
     * @throws \InvalidArgumentException 名前が不正か、localCache なしで正の TTL を指定したとき
     */
    public function __construct(
        public string $name,
        public ?string $cacheControl = null,
        public bool $localCache = false,
        public bool $reloadable = false,
        public int $localCacheTtl = 0,
    ) {
        self::assertValidName($name);

        $this->localCacheTtl = max(0, $localCacheTtl);
        if ($this->localCacheTtl > 0 && !$localCache) {
            throw new \InvalidArgumentException("Defer target '{$name}': localCacheTtl has no effect without localCache: true.");
        }
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^' . self::NAME_PATTERN . '$/D', $name) === 1;
    }

    /** @throws \InvalidArgumentException */
    public static function assertValidName(string $name): void
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException('Deferred component name must match `' . self::NAME_PATTERN . "`, got: '{$name}'");
        }
    }

    /**
     * ページ側のプレースホルダーを作る。残りの props はクエリとして `{prefix}/{name}` に送る。
     * `key` はインスタンスを分ける識別子で、URL から他人の状態を選ばせないために送らない (DF-4)。
     *
     * @param array<string, mixed> $props
     * @throws \InvalidArgumentException fallback が Element でない、または props にスカラーでない値があるとき
     */
    public function buildPlaceholder(array $props): Element
    {
        $fallback = $props['fallback'] ?? null;
        unset($props['fallback'], $props['key']);

        if ($fallback !== null && !$fallback instanceof Element) {
            throw new \InvalidArgumentException(sprintf("Defer target '%s' expected `fallback` prop to be an Element, got %s", $this->name, get_debug_type($fallback)));
        }

        $params = [];
        foreach ($props as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf("Defer target '%s' prop '%s' must be scalar (forwarded via query string); got %s", $this->name, $key, get_debug_type($value)));
            }
            $params[$key] = $value;
        }

        return H::defer($this->name, $params, $fallback, $this->localCache, $this->reloadable, $this->localCacheTtl);
    }
}
