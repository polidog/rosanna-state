<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

/**
 * 描画パスとインスタンス ID (RT-8)。リクエストをまたいで残さない (RT-9.6)。
 */
final class RenderPass
{
    /** @var array<string, int> 名前ごとの連番 */
    private static array $instanceCounts = [];

    /** @var list<string> */
    private static array $componentStack = [];

    /** 新しいパスを始める */
    public static function beginRender(): void
    {
        self::$instanceCounts = [];
        self::$componentStack = [];
    }

    /**
     * いまのパスを保存する。
     *
     * @return array{instanceCounts: array<string, int>, componentStack: list<string>}
     */
    public static function currentPass(): array
    {
        return ['instanceCounts' => self::$instanceCounts, 'componentStack' => self::$componentStack];
    }

    /**
     * 保存したパスに戻す。入れ子のパスが使った番号を再利用しないよう、名前ごとに大きいほうの連番を残す (RT-8.5)。
     *
     * @param array{instanceCounts: array<string, int>, componentStack: list<string>} $pass
     */
    public static function resumePass(array $pass): void
    {
        foreach ($pass['instanceCounts'] as $name => $count) {
            self::$instanceCounts[$name] = max($count, self::$instanceCounts[$name] ?? 0);
        }
        self::$componentStack = $pass['componentStack'];
    }

    /**
     * インスタンス ID を決める (RT-8.1)。連番はパスごと、名前ごとに数え、親の ID を含めない (RT-8.3)。
     *
     * @internal
     */
    public static function nextInstanceId(string $name, ?string $key = null): string
    {
        if ($key === null) {
            $key = (string) (self::$instanceCounts[$name] ?? 0);
            self::$instanceCounts[$name] = (int) $key + 1;
        }

        return $name . '#' . $key;
    }

    /**
     * ID を決めてスタックに積む。
     *
     * @internal
     */
    public static function beginComponent(string $name, ?string $key = null): string
    {
        $id = self::nextInstanceId($name, $key);
        self::enterComponent($id);

        return $id;
    }

    /**
     * 決まっている ID をスタックに積む。連番は使わない (RT-5.4)。
     *
     * @internal
     */
    public static function enterComponent(string $instanceId): void
    {
        self::$componentStack[] = $instanceId;
    }

    /** @internal */
    public static function endComponent(): void
    {
        array_pop(self::$componentStack);
    }

    /** @internal */
    public static function currentComponentId(): ?string
    {
        return self::$componentStack === [] ? null : self::$componentStack[array_key_last(self::$componentStack)];
    }

    /** @internal */
    public static function stackDepth(): int
    {
        return count(self::$componentStack);
    }

    /** @internal RequestLifecycle::reset() のため */
    public static function clear(): void
    {
        self::$instanceCounts = [];
        self::$componentStack = [];
    }
}
