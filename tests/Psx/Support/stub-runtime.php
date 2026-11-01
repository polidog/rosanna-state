<?php

/**
 * 生成コードの評価を、ランタイムなしで確かめるための子プロセス。
 * Composer の autoload を読まないので、本物のランタイムと衝突しない。
 *
 * 使い方: php stub-runtime.php <コンパイル済みのファイル> <props の JSON>
 */

declare(strict_types=1);

namespace Polidog\Rosanna\Html {
    function createElement(string $type, array $params): array
    {
        return ['type' => $type, 'params' => $params];
    }

    final class H
    {
        public static function defer(mixed ...$args): array
        {
            return ['defer' => $args];
        }
    }
}

namespace Polidog\Rosanna\Runtime {
    final class StubPsx
    {
        public function render(string $fqcn, array $props = []): array
        {
            return ['component' => $fqcn, 'props' => $props];
        }
    }

    final class StubScope
    {
        public StubPsx $psx;

        public function __construct()
        {
            $this->psx = new StubPsx();
        }
    }

    final class RenderContext
    {
        public static function scope(): StubScope
        {
            return new StubScope();
        }
    }
}

namespace {
    try {
        $value = require $argv[1];
        if ($value instanceof \Closure) {
            $value = $value(\json_decode($argv[2], true, flags: \JSON_THROW_ON_ERROR));
        }
        echo \json_encode(['value' => $value], \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        echo \json_encode(['thrown' => $e::class, 'message' => $e->getMessage(), 'line' => $e->getLine()], \JSON_THROW_ON_ERROR);
    }
}
