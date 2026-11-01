<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Snapshot\SnapshotUrl;

/**
 * redirect の行き先 (HD-13)。リクエスト行はクライアントが決めるので、パスだけが origin を離れる原因になる。
 *
 * @internal
 */
final class RedirectTarget
{
    /**
     * 常に `/` 1 つで始まり、`\` を含まない。クエリは保ち、`_rosanna_snapshot` を除き、フラグメントは落とす。
     */
    public static function fromUri(string $uri): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return '/';
        }

        $withoutFragment = explode('#', $uri, 2)[0];
        [$path, $query] = array_pad(explode('?', $withoutFragment, 2), 2, '');

        // 絶対形式のリクエスト行だけ authority を読み飛ばす。`/:80` は parse_url() が読めないので `/` で始まるものを渡さない
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.\-]*:~', $path) === 1) {
            $parsed = parse_url($path);
            $path = is_array($parsed) ? ($parsed['path'] ?? '/') : '/';
        }

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $query = SnapshotUrl::removeParam($query);

        return $query === '' ? $path : $path . '?' . $query;
    }
}
