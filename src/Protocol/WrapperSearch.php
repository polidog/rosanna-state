<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use function Polidog\Rosanna\Html\resolveRenderables;

use Polidog\Rosanna\Runtime\Element;

/**
 * replay の木からインスタンスのラッパーを探す (HD-9)。
 *
 * @internal
 */
final class WrapperSearch
{
    /**
     * 深さ優先で探し、なければプレースホルダーの対象を描画してさらに探す。deferred は遅いことが多いので、探索が届いたものだけ描画する。
     * 同じ名前とパラメーターは 1 回だけ。
     *
     * ID は defer ごとに 0 から振られて重なるので、$deferName があればその defer の中だけを探す。パラメーターも揃うものを優先し、
     * なければ同じ名前で最初に見つかったものを使う。他の対象は、その名前のプレースホルダーで見つからないときだけ描画する。
     *
     * @param list<Element> $roots
     * @param callable(string, array<string, string>): ?Element $renderTarget 対象の描画。失敗は null
     * @param string|null $deferName action の送信元の defer。署名しない。探す範囲を選ぶだけで、どの defer の内容も公開のエンドポイントから取れる
     * @param string|null $deferQuery 送信元の defer のクエリ
     * @return array{Element, list<Element>}|null ラッパーと、木のすべて。探さずに残った根も SN-18 の確認に要るので含める
     */
    public static function find(array $roots, string $instanceId, callable $renderTarget, ?string $deferName = null, ?string $deferQuery = null): ?array
    {
        $pending = array_map(static fn(Element $root): array => [$root, null, []], $roots);
        $deferProps = self::sortedProps(self::queryProps($deferQuery));
        $searched = [];
        $visited = [];
        $sameName = null;
        $later = [];

        while (true) {
            $entry = array_shift($pending);
            if ($entry === null) {
                $next = array_shift($later);
                if ($next === null) {
                    break;
                }
                [$name, $props] = $next;
                $target = $renderTarget($name, $props);
                if ($target !== null) {
                    $pending[] = [$target, $name, $props];
                }
                continue;
            }

            [$root, $targetName, $targetProps] = $entry;
            $root = self::expand($root);
            $searched[] = $root;

            $wrapper = $deferName === null || $targetName === $deferName ? self::findWrapper($root, $instanceId) : null;
            if ($wrapper !== null) {
                if ($deferName === null || self::sortedProps($targetProps) === $deferProps) {
                    return [$wrapper, [...$searched, ...array_map(static fn(array $rest): Element => self::expand($rest[0]), $pending)]];
                }
                $sameName ??= $wrapper;
            }

            foreach (self::placeholders($root) as $placeholder) {
                $name = $placeholder->props['__name'] ?? null;
                if (!is_string($name)) {
                    continue;
                }
                $props = self::endpointProps($placeholder);
                $visit = $name . '?' . http_build_query($props);
                if (isset($visited[$visit])) {
                    continue;
                }
                $visited[$visit] = true;

                if ($deferName !== null && $name !== $deferName) {
                    $later[] = [$name, $props];
                    continue;
                }

                $target = $renderTarget($name, $props);
                if ($target !== null) {
                    $pending[] = [$target, $name, $props];
                }
            }
        }

        return $sameName === null ? null : [$sameName, $searched];
    }

    /**
     * ラッパーの外にあるインスタンスの ID (SN-18)。
     *
     * @return list<string>
     */
    public static function instanceIdsOutside(Element $element, string $instanceId): array
    {
        $id = $element->props['data-rosanna'] ?? null;
        if ($id === $instanceId) {
            return [];
        }

        $ids = is_string($id) ? [$id] : [];
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $ids = [...$ids, ...self::instanceIdsOutside($child, $instanceId)];
            }
        }

        return $ids;
    }

    /** 手組みの Element は children に配列や未展開の Renderable を持つことがある。出力と同じ 1 回だけ展開する */
    private static function expand(Element $element): Element
    {
        if ($element->children === []) {
            return $element;
        }

        $children = [];
        foreach (resolveRenderables($element->children) as $child) {
            $children[] = $child instanceof Element ? self::expand($child) : $child;
        }

        return $element->withChildren($children);
    }

    private static function findWrapper(Element $element, string $instanceId): ?Element
    {
        if ($element->type === 'div' && ($element->props['data-rosanna'] ?? null) === $instanceId) {
            return $element;
        }

        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $found = self::findWrapper($child, $instanceId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @return list<Element> */
    private static function placeholders(Element $element): array
    {
        if ($element->type === '__defer__') {
            return [$element];
        }

        $found = [];
        foreach ($element->children as $child) {
            if ($child instanceof Element) {
                $found = [...$found, ...self::placeholders($child)];
            }
        }

        return $found;
    }

    /**
     * エンドポイントがクエリから作る props と同じ形 (DF-4)。
     *
     * @return array<string, string>
     */
    private static function queryProps(?string $query): array
    {
        parse_str($query ?? '', $params);

        $props = [];
        foreach ($params as $key => $value) {
            $key = (string) $key;
            if ($key !== 'key' && $key !== 'fallback' && is_string($value)) {
                $props[$key] = $value;
            }
        }

        return $props;
    }

    /**
     * @param array<string, string> $props
     * @return array<string, string>
     */
    private static function sortedProps(array $props): array
    {
        ksort($props);

        return $props;
    }

    /**
     * Renderer が作るクエリを読み戻した形。エンドポイントが受け取る props と揃える (DF-4)。
     *
     * @return array<string, string>
     */
    private static function endpointProps(Element $placeholder): array
    {
        $params = $placeholder->props['__params'] ?? [];
        $props = [];
        foreach (is_array($params) ? $params : [] as $key => $value) {
            $key = (string) $key;
            if ($key !== 'key' && $key !== 'fallback' && is_scalar($value)) {
                $props[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $props;
    }
}
