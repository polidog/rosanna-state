<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Snapshot;

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Runtime\Snapshot;

final class SnapshotUrl
{
    public const PARAM = '_rosanna_snapshot';

    /**
     * フラグメントは落とす。
     *
     * @throws \InvalidArgumentException 同一オリジンのパスでないとき (SN-20)
     */
    public static function with(string $url, Snapshot $snapshot, SnapshotSerializer $serializer): string
    {
        self::assertSameOriginPath($url);

        $parts = explode('?', explode('#', $url, 2)[0], 2);
        $query = self::removeParam($parts[1] ?? '');
        $param = self::PARAM . '=' . rawurlencode($serializer->serialize($snapshot));

        return $parts[0] . '?' . ($query === '' ? '' : $query . '&') . $param;
    }

    /** `?_rosanna_snapshot[]=1` は配列で届くので null にする (SN-22) */
    public static function extract(Request $request): ?string
    {
        $snapshot = $request->query[self::PARAM] ?? null;

        return is_string($snapshot) ? $snapshot : null;
    }

    /**
     * PHP が `_rosanna_snapshot` として読む区切りだけを取り除き、残りはバイト単位で保つ (SN-21)。
     * 判定は区切りごとの parse_str() に任せ、`%5Frosanna_snapshot` や `_rosanna_snapshot[]` も同じに扱う。
     */
    public static function removeParam(string $query): string
    {
        $kept = [];
        foreach (explode('&', $query) as $segment) {
            parse_str($segment, $parsed);
            if (!array_key_exists(self::PARAM, $parsed)) {
                $kept[] = $segment;
            }
        }

        return implode('&', $kept);
    }

    /** @throws \InvalidArgumentException */
    private static function assertSameOriginPath(string $url): void
    {
        // ブラウザは `\` を `/` に読み、タブや改行を取り除くので、`/\evil.example` や `/\t/evil.example` も別のホストになる
        if (!str_starts_with($url, '/') || preg_match('~^/[/\\\\]|[\x00-\x1F\x7F]~', $url) === 1) {
            throw new \InvalidArgumentException('Redirect URL must be a same-origin path beginning with a single "/".');
        }

        // 単一の `/` で始まればスキームもホストも持たない。`/item:42` は parse_url() が読めないので渡さない (HD-14)
        if (!str_contains($url, '\\')) {
            return;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || isset($parsed['scheme']) || isset($parsed['host'])) {
            throw new \InvalidArgumentException('Redirect URL must be a same-origin path beginning with a single "/".');
        }
    }
}
