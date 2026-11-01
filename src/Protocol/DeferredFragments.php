<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Protocol;

use Polidog\Rosanna\Defer\DeferredRegistry;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Defer\FragmentRenderer;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Component\ComponentRegistry;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RenderPass;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\StateScope;

/**
 * defer の対象を状態層の中で描く。エンドポイントと、ActionHandler の replay が使う。
 *
 * @internal `RosannaState` と ActionHandler のため
 */
final readonly class DeferredFragments implements FragmentRenderer
{
    public function __construct(
        private RosannaState $app,
        private ComponentRegistry $components,
        private DeferredRegistry $registry,
        private Request $request,
    ) {}

    public function render(string $name, string $component, array $props, DeferRequest $request): string
    {
        $outerPass = RenderPass::currentPass();
        try {
            $scope = $this->app->scope($this->request, $name);

            return RenderContext::run($scope, function () use ($scope, $component, $request, $name, $props): string {
                RenderPass::beginRender();
                $element = $this->target($component, $props);

                // HD-9、HD-11 フォームが部分更新のために送信元の名前とクエリを運ぶ
                return StateScope::of($scope)->renderers
                    ->create('_root_', deferQuery: $request->queryString === '' ? null : $request->queryString, deferName: $name)
                    ->renderElement($element);
            });
        } finally {
            RenderPass::resumePass($outerPass);
        }
    }

    /**
     * replay の木で見つけたプレースホルダーの対象を、エンドポイントと同じ形で描画する (HD-9)。
     * 失敗は探しているインスタンスの有無を何も言わないので、ログに出して飛ばす。
     *
     * @param array<string, string> $props
     */
    public function renderForReplay(string $name, array $props): ?Element
    {
        $component = $this->registry->get($name)['component'] ?? null;
        if ($component === null) {
            return null;
        }

        $outerGet = $_GET;
        $_GET = $props;
        $level = ob_get_level();
        ob_start();
        try {
            return RenderContext::run($this->app->scope($this->request, $name), function () use ($component, $props): Element {
                RenderPass::beginRender();

                return $this->target($component, $props);
            });
        } catch (\Throwable $e) {
            error_log(sprintf("[Rosanna] defer render failed for name '%s' during a replay: %s", $name, $e->getMessage()));

            return null;
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            $_GET = $outerGet;
        }
    }

    /** @param array<string, mixed> $props */
    private function target(string $component, array $props): Element
    {
        // クラスコンポーネントは props を受けず、$_GET か state から読む
        $registered = $this->components->has($component) ? $component : $this->components->nameOf($component);
        if ($registered !== null) {
            return $this->app->createElement($registered);
        }

        return RenderContext::scope()->psx->render($component, $props);
    }
}
