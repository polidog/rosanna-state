<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Runtime;

/**
 * 状態の値の差分 (AC-13〜15)。
 */
final class StateDiff
{
    private const REPLACE = 'r';
    private const LIST_ITEM = 'l';
    private const SPLICE = 's';
    private const MAP = 'm';

    /** payload に足される `base` の長さ。これを含めて全体より小さいときだけ差分を使う */
    private const BASE_HASH_BYTES = 64;

    /**
     * キーの順序などの細かな場合分けはせず、最後の一致の確認に任せる (AC-13)。
     *
     * @return array<mixed>|null 差分。使えないときは null
     */
    public static function create(mixed $base, mixed $next): ?array
    {
        $patch = self::diff($base, $next);

        try {
            // AC-13 の大きさは基準の値の JSON で近似する。新しい値全体は JSON にしない
            if (strlen(json_encode($patch, Action::JSON_FLAGS)) + self::BASE_HASH_BYTES >= strlen(json_encode($base, Action::JSON_FLAGS))) {
                return null;
            }

            return self::apply($base, $patch) === $next ? $patch : null;
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<mixed> $patch
     * @throws \InvalidArgumentException 差分の形が不正か、基準の値に当てはまらないとき
     */
    public static function apply(mixed $base, array $patch): mixed
    {
        if (!array_is_list($patch) || $patch === []) {
            throw new \InvalidArgumentException('Patch must be a non-empty list');
        }

        return match ($patch[0]) {
            self::REPLACE => self::applyReplace($patch),
            self::LIST_ITEM => self::applyListItem($base, $patch),
            self::SPLICE => self::applySplice($base, $patch),
            self::MAP => self::applyMap($base, $patch),
            default => throw new \InvalidArgumentException('Unknown patch operation'),
        };
    }

    /** @throws \InvalidArgumentException JSON にできない値のとき */
    public static function hash(mixed $value): string
    {
        try {
            return hash('sha256', json_encode($value, Action::JSON_FLAGS));
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Value cannot be hashed: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @return array<mixed> */
    private static function diff(mixed $base, mixed $next): array
    {
        if (!is_array($base) || !is_array($next)) {
            return [self::REPLACE, $next];
        }
        if (array_is_list($base) && array_is_list($next)) {
            return self::diffList($base, $next);
        }
        if (array_is_list($base) || array_is_list($next)) {
            return [self::REPLACE, $next];
        }

        return self::diffMap($base, $next);
    }

    /**
     * 共通の先頭と末尾を除いて比べるので、先頭行の削除で添字がずれても差分は膨らまない (AC-14)。
     *
     * @param list<mixed> $base
     * @param list<mixed> $next
     * @return array<mixed>
     */
    private static function diffList(array $base, array $next): array
    {
        $baseCount = count($base);
        $nextCount = count($next);
        $shorter = min($baseCount, $nextCount);

        $head = 0;
        while ($head < $shorter && $base[$head] === $next[$head]) {
            $head++;
        }

        $tail = 0;
        while ($tail < $shorter - $head && $base[$baseCount - 1 - $tail] === $next[$nextCount - 1 - $tail]) {
            $tail++;
        }

        $removed = $baseCount - $head - $tail;
        $added = $nextCount - $head - $tail;
        if ($removed === 1 && $added === 1) {
            return [self::LIST_ITEM, $head, self::diff($base[$head], $next[$head])];
        }

        return [self::SPLICE, $head, $removed, array_slice($next, $head, $added)];
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $next
     * @return array<mixed>
     */
    private static function diffMap(array $base, array $next): array
    {
        $set = [];
        foreach ($next as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $set[$key] = [self::REPLACE, $value];
            } elseif ($base[$key] !== $value) {
                $set[$key] = self::diff($base[$key], $value);
            }
        }

        return [self::MAP, $set, array_keys(array_diff_key($base, $next))];
    }

    /**
     * @param list<mixed> $patch
     * @throws \InvalidArgumentException
     */
    private static function applyReplace(array $patch): mixed
    {
        if (count($patch) !== 2) {
            throw new \InvalidArgumentException('Malformed "r" patch');
        }

        return $patch[1];
    }

    /**
     * @param list<mixed> $patch
     * @return list<mixed>
     * @throws \InvalidArgumentException
     */
    private static function applyListItem(mixed $base, array $patch): array
    {
        [, $position, $itemPatch] = count($patch) === 3 ? $patch : [null, null, null];
        if (!is_array($base) || !array_is_list($base) || !is_int($position) || !is_array($itemPatch)
            || !array_key_exists($position, $base)) {
            throw new \InvalidArgumentException('Malformed "l" patch');
        }

        $base[$position] = self::apply($base[$position], $itemPatch);

        return $base;
    }

    /**
     * @param list<mixed> $patch
     * @return list<mixed>
     * @throws \InvalidArgumentException
     */
    private static function applySplice(mixed $base, array $patch): array
    {
        [, $start, $removed, $items] = count($patch) === 4 ? $patch : [null, null, null, null];
        if (!is_array($base) || !array_is_list($base) || !is_int($start) || !is_int($removed) || !is_array($items)
            || $start < 0 || $removed < 0 || $start + $removed > count($base)) {
            throw new \InvalidArgumentException('Malformed "s" patch');
        }

        array_splice($base, $start, $removed, $items);

        return $base;
    }

    /**
     * @param list<mixed> $patch
     * @return array<mixed>
     * @throws \InvalidArgumentException
     */
    private static function applyMap(mixed $base, array $patch): array
    {
        [, $set, $removed] = count($patch) === 3 ? $patch : [null, null, null];
        if (!is_array($base) || !is_array($set) || !is_array($removed)) {
            throw new \InvalidArgumentException('Malformed "m" patch');
        }

        foreach ($removed as $key) {
            if (!is_int($key) && !is_string($key)) {
                throw new \InvalidArgumentException('Malformed "m" patch');
            }
            unset($base[$key]);
        }
        foreach ($set as $key => $itemPatch) {
            if (!is_array($itemPatch)) {
                throw new \InvalidArgumentException('Malformed "m" patch');
            }
            $base[$key] = self::apply($base[$key] ?? null, $itemPatch);
        }

        return $base;
    }
}
