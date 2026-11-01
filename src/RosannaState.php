<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderExtension;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\ComponentInterface;
use Polidog\Rosanna\State\Component\ComponentRegistry;
use Polidog\Rosanna\State\Component\DeferredRegistry;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\Http\Response;
use Polidog\Rosanna\State\Protocol\ActionHandler;
use Polidog\Rosanna\State\Protocol\ActionSigner;
use Polidog\Rosanna\State\Protocol\CsrfGuard;
use Polidog\Rosanna\State\Protocol\DeferredEndpoint;
use Polidog\Rosanna\State\Protocol\SessionSnapshots;
use Polidog\Rosanna\State\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\State\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\State\Snapshot\SnapshotUrl;
use Polidog\Rosanna\State\Snapshot\SnapshotVerificationException;
use Polidog\Rosanna\State\Storage\NativeSession;

/**
 * 状態層の入口。利用者はこれ 1 つを持ち、描画の API は `Rosanna` に委譲する。
 * 登録と設定だけを持ち、リクエストごとの情報は残さない (RQ-3)。URL からページを選ぶのは host の役割で、`run()` は持たない。
 */
final class RosannaState
{
    /**
     * 外部の rosanna.js が読めないときだけ defer を 1 回取得する。フォームは通常の POST に落ちる。
     * CSP の nonce で動くよう、インラインのイベントハンドラを使わない。defer の script は DOMContentLoaded の前に動くので、
     * その時点で reloadDefer がなければ読み込みに失敗している。
     */
    private const DEFER_FALLBACK_SCRIPT = <<<'JS'
        (function(){
        window.Rosanna=window.Rosanna||{};
        var B='data-rosanna-defer-fallback-loading';
        function warn(m,u){if(window.console&&console.warn)console.warn('[Rosanna] '+m,u);}
        function prefix(){var r=window.Rosanna,p=r&&typeof r.deferPrefix==='string'&&r.deferPrefix!==''?r.deferPrefix:'/_defer';return p.replace(/\/+$/,'');}
        function allowed(url){try{var u=new URL(url,location.href);return u.origin===location.origin&&u.pathname.indexOf(prefix()+'/')===0;}catch(e){return false;}}
        function scan(root){root.querySelectorAll('[data-rosanna-defer-url]:not([data-rosanna-defer-loaded]):not(['+B+'])').forEach(load);}
        function place(el,html){var t=document.createElement('template');t.innerHTML=html;scan(t.content);if(el.hasAttribute('data-rosanna-defer-name')){el.replaceChildren(t.content);el.setAttribute('data-rosanna-defer-loaded','');}else{el.replaceWith(t.content);}}
        function load(el){var url=el.getAttribute('data-rosanna-defer-url');if(!allowed(url)){warn('refusing defer fetch outside the same-origin defer prefix',url);return;}el.setAttribute(B,'');el.setAttribute('aria-busy','true');fetch(url,{method:'GET',headers:{'X-Rosanna-Defer':'1'},credentials:'same-origin'}).then(function(r){var type=(r.headers.get('content-type')||'').trim().toLowerCase();if(!r.ok||type.indexOf('text/html')!==0||(r.url&&!allowed(r.url)))return null;return r.text();}).then(function(html){if(html!==null)place(el,html);}).catch(function(e){warn('defer fallback fetch failed',e);}).finally(function(){el.removeAttribute(B);el.removeAttribute('aria-busy');});}
        function check(){setTimeout(function(){if(!(window.Rosanna&&window.Rosanna.reloadDefer))scan(document);},0);}
        if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',check,{once:true});else check();
        })();
        JS;

    private ComponentRegistry $registry;

    private Rosanna $rosanna;

    private ?string $snapshotSecret = null;

    private ?SnapshotSerializer $snapshotSerializer = null;

    private bool $csrfEnabled = true;

    private bool $trustProxyHeaders = false;

    private string $deferPrefix = RendererFactory::DEFAULT_DEFER_PREFIX;

    private DeferredRegistry $deferred;

    /**
     * replay の間だけ、描画に渡された Element を集める。finally で必ず戻すのでリクエストをまたがない (HD-6)。
     *
     * @var list<Element>|null
     */
    private ?array $collected = null;

    public function __construct()
    {
        $this->registry = new ComponentRegistry();
        $this->rosanna = new Rosanna(
            static fn(RenderScope $scope): RenderExtension => StateScope::of($scope)->renderers->extension('_root_'),
            $this->scopeFor(...),
        );
        $this->deferred = new DeferredRegistry();
    }

    /**
     * #[Defer] があれば、エンドポイントと、PSX のページ側で使うプレースホルダーも登録する (RT-12.5)。
     *
     * @param class-string<ComponentInterface> $className
     * @throws \InvalidArgumentException
     */
    public function register(string $className): self
    {
        $this->registry->register($className);

        $defer = $this->registry->getDefer($className::getComponentName());
        if ($defer !== null) {
            $this->rosanna->registerComponent($className, static fn(array $props): Element => $defer->buildPlaceholder($props));
            $this->deferred->registerIfNew($defer->name, $className, $defer->cacheControl);
        }

        return $this;
    }

    public function registerComponent(string $fqcn, callable $component): self
    {
        $this->rosanna->registerComponent($fqcn, $component);

        return $this;
    }

    /**
     * RT-11.5、DF-13。`rosanna compile` の成果物は defer を調べていないので受けない。
     *
     * @throws \RuntimeException ファイルがない、配列を返さない、項目の形が違うとき、deferred manifest がないか build ID が合わないとき
     */
    public function loadComponentManifest(string $path): self
    {
        $this->rosanna->loadComponentManifest($path);
        foreach (DeferredManifestCompiler::read($path) as $name => $entry) {
            $this->deferred->registerIfNew((string) $name, $entry['component'], $entry['cacheControl']);
        }

        return $this;
    }

    public function setArgumentResolver(PsxArgumentResolver $resolver): self
    {
        $this->rosanna->setArgumentResolver($resolver);

        return $this;
    }

    /** @throws \InvalidArgumentException 鍵が短いとき (SN-4) */
    public function setSnapshotSecret(#[\SensitiveParameter] string $secretKey): self
    {
        $this->snapshotSerializer = new SnapshotSerializer($secretKey);
        $this->snapshotSecret = $secretKey;

        return $this;
    }

    /** 秘密鍵がなければ null。例外を投げない (SN-9) */
    public function snapshotSerializer(): ?SnapshotSerializer
    {
        return $this->snapshotSerializer;
    }

    /** setSnapshotSecret や registerDeferred が後から呼ばれるのでキャッシュしない */
    public function signer(): ActionSigner
    {
        return new ActionSigner($this->snapshotSecret, $this->deferred->names());
    }

    public function csrf(): CsrfGuard
    {
        return new CsrfGuard($this->csrfEnabled, $this->trustProxyHeaders);
    }

    public function disableCsrfProtection(): self
    {
        $this->csrfEnabled = false;

        return $this;
    }

    public function trustProxyHeaders(bool $trust = true): self
    {
        $this->trustProxyHeaders = $trust;

        return $this;
    }

    public function actions(): ActionHandler
    {
        return new ActionHandler($this);
    }

    /**
     * 同じ名前の登録は例外 (DF-3)。名前は URL になるので、別の内容を黙って上書きしない。
     *
     * @throws \InvalidArgumentException 名前が不正か、登録済みのとき
     */
    public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self
    {
        $this->deferred->register($name, $component, $cacheControl);

        return $this;
    }

    /** @throws \InvalidArgumentException `/` で始まらないか、ルートそのものを指すとき */
    public function setDeferPrefix(string $prefix): self
    {
        $normalized = rtrim($prefix, '/');
        if ($normalized === '' || !str_starts_with($normalized, '/') || str_starts_with($normalized, '//')) {
            throw new \InvalidArgumentException("Defer prefix must be a path like '/_defer', got: '{$prefix}'");
        }
        $this->deferPrefix = $normalized;

        return $this;
    }

    /**
     * RT-12.8。$deferName があれば defer エンドポイントの描画とし、署名の scope を defer:{名前} にする。
     */
    public function scope(Request $request, ?string $deferName = null): RenderScope
    {
        $signer = $this->signer()->forRequest($request, $deferName);

        return $this->newScope(
            $signer,
            $signer->signingScope() ?? throw new \LogicException('A signer bound to a request always has a signing scope.'),
            $deferName,
            $deferName === null ? null : ($this->deferred->get($deferName)['component'] ?? null),
        );
    }

    /**
     * ラッパーの div 付きの HTML。新しいパスを始め、終わったら戻す (RT-12.3)。
     *
     * @throws \LogicException 登録されていないとき (RT-12.2)、秘密鍵なしの Snapshot (RT-12.4)
     */
    public function render(string $componentName, ?string $key = null): string
    {
        $name = $this->requireComponentName($componentName);

        return $this->inScope(function (RenderScope $scope) use ($name, $key): string {
            $outerPass = RenderPass::currentPass();
            try {
                RenderPass::beginRender();
                $instanceId = RenderPass::nextInstanceId($name, $key);
                $component = $this->newComponent($name);
                $renderer = StateScope::of($scope)->renderers->create($instanceId, $this->registry->getStorageType($name), $this->registry->getPersist($name));

                return $renderer->render(function () use ($component, $instanceId): Element {
                    $state = ComponentState::current();
                    if ($component instanceof BaseComponent && $state !== null) {
                        $component->setComponentState($state);
                    }
                    $inner = $component->render();
                    if ($this->collected !== null) {
                        $this->collected[] = new Element('div', ['data-rosanna' => $instanceId], [$inner]);
                    }

                    return $inner;
                });
            } finally {
                RenderPass::resumePass($outerPass);
            }
        });
    }

    /**
     * ラッパー付きの Element。現在のパスを続ける (RT-12.3)。
     *
     * @throws \LogicException 登録されていないとき (RT-12.2)、秘密鍵なしの Snapshot (RT-12.4)
     */
    public function createElement(string $componentName, ?string $key = null): Element
    {
        $name = $this->requireComponentName($componentName);

        return $this->inScope(function () use ($name, $key): Element {
            $component = $this->newComponent($name);
            $state = ComponentState::getInstance(RenderPass::nextInstanceId($name, $key), $this->registry->getStorageType($name));
            ComponentState::reset();
            if ($component instanceof BaseComponent) {
                $component->setComponentState($state);
            }
            $inner = $component->render();

            // スナップショットには描画で使われたフックの値が要るので、render() の後に作る
            return new Element('div', ComponentWrapper::propsInScope($state, $this->registry->getPersist($name)), [$inner]);
        });
    }

    /** ルート ID は `_root_`。現在のパスを続ける (RT-12.3) */
    public function renderElement(Element $element): string
    {
        if ($this->collected !== null) {
            $this->collected[] = $element;
        }

        return $this->inScope(fn(): string => $this->rosanna->renderElement($element));
    }

    /**
     * GET の描画の前に呼ぶ。Isolated では何もしない (SN-28)。失敗はログに出して状態なしで描画させる (SN-24、SN-27)。
     */
    public function restoreSnapshots(Request $request, SnapshotBehavior $behavior = SnapshotBehavior::Isolated): void
    {
        if ($behavior === SnapshotBehavior::Isolated) {
            return;
        }

        $carried = SnapshotUrl::extract($request);
        $parent = ComponentState::current();
        try {
            if ($behavior === SnapshotBehavior::Persistent) {
                if ($carried !== null) {
                    $this->restorePersistentSnapshot($carried);
                }

                return;
            }

            // SN-23 別のセッションで得た署名済みのスナップショットをリンクで植え付けられるのを防ぐ
            if ($carried !== null) {
                error_log('[Rosanna] Snapshot ignored (behavior=Session): request-carried snapshots are not accepted');
            }
            NativeSession::resumeFromCookie($request->cookies);
            if ($this->snapshotSerializer === null || !NativeSession::isActive()) {
                return;
            }
            foreach (SessionSnapshots::load($this->snapshotSerializer) as $snapshot) {
                ComponentState::fromSnapshot($snapshot);
            }
        } finally {
            ComponentState::setCurrent($parent);
        }
    }

    /**
     * DF-1〜DF-12。host が `{prefix}/{name}` へのリクエストをページの処理より前に渡す。
     */
    public function handleDeferred(Request $request): ?Response
    {
        return $this->deferredEndpoint()->handle($request);
    }

    /**
     * RT-12.6。$src はエスケープし、$deferPrefix は JS の文字列として `</` で script を閉じられないように出す。
     * setDeferPrefix() を使うアプリは同じ値を渡す。
     */
    public static function renderClientScript(string $src = '/rosanna.js', ?string $deferPrefix = null, ?string $nonce = null): string
    {
        $nonceAttribute = $nonce === null ? '' : ' nonce="' . self::escape($nonce) . '"';

        $prefixScript = '';
        if ($deferPrefix !== null) {
            $json = json_encode($deferPrefix, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $prefixScript = "<script{$nonceAttribute}>window.Rosanna=window.Rosanna||{};window.Rosanna.deferPrefix={$json};</script>\n";
        }

        return $prefixScript
            . "<script{$nonceAttribute}>" . self::DEFER_FALLBACK_SCRIPT . "</script>\n"
            . '<script src="' . self::escape($src) . '" defer' . $nonceAttribute . '></script>';
    }

    /** @internal ActionHandler のため */
    public function components(): ComponentRegistry
    {
        return $this->registry;
    }

    /** @internal ActionHandler のため */
    public function deferredEndpoint(): DeferredEndpoint
    {
        return new DeferredEndpoint($this, $this->deferred, $this->deferPrefix);
    }

    /**
     * $fn の間に描画に渡された Element を集める (HD-6)。
     *
     * @internal ActionHandler のため
     * @template T
     * @param callable(): T $fn
     * @return array{T, list<Element>}
     */
    public function collectRendered(callable $fn): array
    {
        $outer = $this->collected;
        $this->collected = [];
        try {
            $result = $fn();

            return [$result, $this->collected];
        } finally {
            $this->collected = $outer;
        }
    }

    /**
     * 同じアプリのスコープならそのまま使い、エンドポイントの判定も引き継ぐ (RT-12.9)。
     *
     * @template T
     * @param callable(RenderScope): T $fn
     * @return T
     */
    private function inScope(callable $fn): mixed
    {
        $parent = ComponentState::current();
        try {
            return $this->rosanna->inScope($fn);
        } finally {
            ComponentState::setCurrent($parent);
        }
    }

    /**
     * 別のアプリのスコープの中では、外側の署名の scope を使い、鍵と serializer は自分のものを使う (RT-12.9)。
     * 束縛しない signer は描画中の StateScope::$signingScope を使う。
     */
    private function scopeFor(PsxRenderer $psx, ?RenderScope $outer): RenderScope
    {
        return $outer?->state instanceof StateScope
            ? $this->newScope($this->signer(), $outer->state->signingScope, null, null)
            : $this->scope(Request::fromGlobals());
    }

    private function newScope(ActionSigner $signer, string $signingScope, ?string $deferName, ?string $deferComponent): RenderScope
    {
        $psx = $this->rosanna->psx();

        return new RenderScope($psx, new StateScope(
            new RendererFactory($this->snapshotSerializer, $signer, $this->csrf(), $this->deferPrefix),
            $psx,
            $this->snapshotSerializer,
            $signer,
            $signingScope,
            $deferName,
            $deferComponent,
        ));
    }

    /** RT-12.1 登録名とクラス名のどちらでも同じインスタンスになる */
    private function requireComponentName(string $componentName): string
    {
        if ($this->registry->has($componentName)) {
            return $componentName;
        }

        return $this->registry->nameOf($componentName) ?? throw new \LogicException(sprintf(
            'Component %s is not registered. Call register() with its class before rendering it.',
            $componentName,
        ));
    }

    private function newComponent(string $name): ComponentInterface
    {
        return $this->registry->create($name) ?? throw new \LogicException("Component {$name} is not registered.");
    }

    private function restorePersistentSnapshot(string $json): void
    {
        if ($this->snapshotSerializer === null) {
            error_log('[Rosanna] Snapshot ignored (behavior=Persistent): no secret key is set');

            return;
        }

        try {
            ComponentState::fromSnapshot($this->snapshotSerializer->deserialize($json));
        } catch (SnapshotVerificationException $e) {
            error_log('[Rosanna] Snapshot rejected (behavior=Persistent): ' . $e->getMessage());
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
