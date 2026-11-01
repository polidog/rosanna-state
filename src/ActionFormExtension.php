<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State;

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderExtension;
use Polidog\Rosanna\State\Component\Defer;
use Polidog\Rosanna\State\Protocol\ActionEnvelope;
use Polidog\Rosanna\State\Protocol\ActionSigner;
use Polidog\Rosanna\State\Protocol\CsrfGuard;
use Polidog\Rosanna\State\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\State\Storage\StorageType;

/**
 * `Renderer` に差し込む状態層の描画。フォームの束縛と配置 (RT-4)、defer のプレースホルダーを担う。
 * 描画ごとの可変状態を持つので、`Renderer` と一緒に描画ごとに作る。
 *
 * @internal RendererFactory のため
 */
final class ActionFormExtension implements RenderExtension
{
    private int $openFormDepth = 0;

    /**
     * まだ書き出していないフォーム。要素の隣に出すと form、p、tbody の中でマークアップが崩れるので溜める (RT-4.5)。
     *
     * @var list<ActionForm>
     */
    private array $pendingForms = [];

    /**
     * いまのスコープの共有フォーム。スコープは最も内側の `data-rosanna` 要素か、最も外側の renderElement() (RT-4.7)。
     * 書き出した後も使い回すので、スコープの間は消さない (RT-4.8)。
     *
     * @var array<string, ActionForm> action の対象のインスタンス ID => 共有フォーム
     */
    private array $sharedForms = [];

    public function __construct(
        private readonly string $rootId,
        private readonly ?string $deferQuery,
        private readonly ?string $deferName,
        private readonly ?SnapshotSerializer $snapshotSerializer,
        private readonly ActionSigner $signer,
        private readonly CsrfGuard $csrf,
        private readonly string $deferPrefix,
    ) {}

    public function beginRender(): void
    {
        $this->sharedForms = [];
        $this->pendingForms = [];
    }

    public function finishRender(): string
    {
        return $this->flushForms();
    }

    public function endRender(): void
    {
        $this->sharedForms = [];
        $this->pendingForms = [];
    }

    public function renderTag(Element $element, string $lowerTag, bool $isVoid, \Closure $attributes, \Closure $content): string
    {
        if ($isVoid || !isset($element->props['data-rosanna'])) {
            return $this->renderTagBody($element, $lowerTag, $isVoid, false, $attributes, $content);
        }

        // 部分更新で親のフォームが消えないよう、外で溜まっていたものは持ち込まない (RT-4.5)
        // form 自身がスコープのときも、中に入れると入れ子になるので後ろに出す (RT-4.5)
        $ownsForms = $this->openFormDepth === 0 && $lowerTag !== 'form';
        $outerForms = $this->pendingForms;
        if ($ownsForms) {
            $this->pendingForms = [];
        }
        $outerSharedForms = $this->sharedForms;
        $this->sharedForms = [];

        try {
            return $this->renderTagBody($element, $lowerTag, false, $ownsForms, $attributes, $content);
        } finally {
            $this->sharedForms = $outerSharedForms;
            if ($ownsForms) {
                $this->pendingForms = $outerForms;
            }
        }
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $renderAttributes
     * @param \Closure(string, array<mixed>): string $renderContent
     */
    private function renderTagBody(Element $element, string $lowerTag, bool $isVoid, bool $flushInside, \Closure $renderAttributes, \Closure $renderContent): string
    {
        $tag = $element->type;
        [$props, $submitForm] = $this->bindActions($lowerTag, $element->props);
        $attributes = $renderAttributes($lowerTag, $props);

        if ($isVoid) {
            return "<{$tag}{$attributes} />";
        }

        $isForm = $lowerTag === 'form';
        if ($isForm) {
            $this->openFormDepth++;
        }
        try {
            $children = $renderContent($lowerTag, $element->children);
        } finally {
            if ($isForm) {
                $this->openFormDepth--;
            }
        }

        if ($flushInside) {
            $children .= $this->flushForms();
        }

        // 子の描画でセッションが始まることがあるので、フィールドは子の後で作る (RT-4.6)
        $fields = $submitForm === null ? '' : $this->renderFormFields($submitForm);
        $html = "<{$tag}{$attributes}>{$fields}{$children}</{$tag}>";

        return $isForm && $this->openFormDepth === 0 ? $html . $this->flushForms() : $html;
    }

    private function flushForms(): string
    {
        $html = '';
        foreach ($this->pendingForms as $form) {
            $html .= sprintf(
                '<form method="post" data-rosanna-form id="%s" hidden>%s</form>',
                $form->id,
                $this->renderFormFields($form),
            );
        }
        $this->pendingForms = [];

        return $html;
    }

    /**
     * `wire:*` の prop を、JavaScript なしでも動く HTML にする (RT-4.1)。
     *
     * @param array<string, mixed> $props
     * @return array{array<string, mixed>, ?ActionForm} 出力する props と、onSubmit なら要素自体のフォーム
     */
    private function bindActions(string $lowerTag, array $props): array
    {
        $submitForm = null;

        foreach ($props as $key => $action) {
            if (!str_starts_with((string) $key, 'wire:')) {
                continue;
            }
            unset($props[$key]);

            $event = substr((string) $key, 5);
            if (!$action instanceof Action) {
                throw new \RuntimeException(sprintf('%s must be an Action; got %s.', $key, get_debug_type($action)));
            }

            if ($action->type === Action::SET_FIELDS) {
                if ($event !== 'submit') {
                    self::assertFieldTrigger($event, $lowerTag, $props, $action);
                }
                $props['data-rosanna-with-value'] = true;
            }

            if ($event === 'submit') {
                self::assertSubmitTarget($lowerTag, $props);
                $submitForm = $this->individualForm($action);
                $props = ['method' => 'post', 'data-rosanna-form' => true] + self::withoutProp($props, 'method');
            } elseif ($event === 'click' && $lowerTag === 'button') {
                // パーサーは同じ属性の最初を読むので、大文字の利用者の値を残さない
                $props = self::withoutProp(self::withoutProp($props, 'type'), 'form');
                $props['type'] = 'submit';
                if (self::prop($props, 'name') !== null || self::prop($props, 'value') !== null) {
                    // 利用者の値を上書きしない
                    $props['form'] = $this->queueIndividualForm($action);
                } else {
                    [$props['form'], $props['value']] = $this->bindSharedForm($action);
                    $props['name'] = '_rosanna_action';
                }
            } elseif ($event === 'click' && $lowerTag === 'input' && in_array(self::lowerString(self::prop($props, 'type')), ['submit', 'image'], true)) {
                // submit は value がラベルになり、image は value が送られない
                $props = self::withoutProp($props, 'form');
                $props['form'] = $this->queueIndividualForm($action);
            } elseif (in_array($event, ['click', 'change', 'input'], true)) {
                [$props['data-rosanna-on-' . $event], $props['data-rosanna-action-' . $event]] = $this->bindSharedForm($action);
            } else {
                throw new \RuntimeException(sprintf('Unsupported event binding %s.', $key));
            }
        }

        return [$props, $submitForm];
    }

    /**
     * submit 以外で送られるのは、イベントを起こした欄の値だけ。他の欄は届かずにフックを空にするので拒否する (RT-4.3)。
     *
     * @param array<string, mixed> $props
     */
    private static function assertFieldTrigger(string $event, string $lowerTag, array $props, Action $action): void
    {
        $fields = $action->payload['fields'] ?? null;
        $problem = match (true) {
            $event === 'click' => 'onClick sends no field values; use onSubmit on the form, or onChange/onInput on the control',
            !in_array($lowerTag, ['input', 'select', 'textarea'], true) => sprintf('<%s> has no value to send', $lowerTag),
            !empty(self::prop($props, 'multiple')) || self::lowerString(self::prop($props, 'type')) === 'file' => 'a multiple or file control has no single string value',
            !is_array($fields) || array_keys($fields) !== [self::prop($props, 'name')] => 'the control can only send its own field, named by its name attribute',
            default => null,
        };

        if ($problem !== null) {
            throw new \RuntimeException('useFormAction() cannot be used here: ' . $problem . '.');
        }
    }

    /**
     * JavaScript の有無で送信先が変わるのを防ぐ (RT-4.2)。
     *
     * @param array<string, mixed> $props
     */
    private static function assertSubmitTarget(string $lowerTag, array $props): void
    {
        if ($lowerTag !== 'form') {
            throw new \RuntimeException(sprintf('onSubmit only works on <form>; got <%s>.', $lowerTag));
        }
        if (self::prop($props, 'action') !== null || self::lowerString(self::prop($props, 'method') ?? 'post') !== 'post') {
            throw new \RuntimeException('onSubmit posts the action to the current page; remove action and method from the <form>.');
        }
    }

    /**
     * 属性名は大文字小文字を区別しない。パーサーと同じく最初の値を読む (RT-4.1〜4.3)。
     *
     * @param array<string, mixed> $props
     */
    private static function prop(array $props, string $lowerName): mixed
    {
        foreach ($props as $key => $value) {
            if ($value !== null && strtolower((string) $key) === $lowerName) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private static function withoutProp(array $props, string $lowerName): array
    {
        foreach (array_keys($props) as $key) {
            if (strtolower((string) $key) === $lowerName) {
                unset($props[$key]);
            }
        }

        return $props;
    }

    private static function lowerString(mixed $value): ?string
    {
        return is_string($value) ? strtolower($value) : null;
    }

    /**
     * 共有フォームの ID とエンベロープを返す。action はボタンの value か `data-rosanna-action-*` が持つ (RT-4.7)。
     *
     * @return array{string, string}
     */
    private function bindSharedForm(Action $action): array
    {
        [$targetId, $state, $envelope] = $this->stamp($action);
        $baseIndex = self::baseIndexToCarry($action, $state);

        $form = $this->sharedForms[$targetId] ?? null;
        if ($form === null) {
            $form = $this->newForm($targetId, $action->storageType);
            $this->pendingForms[] = $form;
            $this->sharedForms[$targetId] = $form;
        }

        if ($baseIndex !== null && !isset($form->baseIndexes[$baseIndex])) {
            if ($form->written) {
                // RT-4.8 書き出し済みのフォームには足せない。交互に足りなくならないよう、前の基準も持たせる
                $next = $this->newForm($targetId, $action->storageType);
                $next->baseIndexes = $form->baseIndexes;
                $form = $next;
                $this->pendingForms[] = $form;
                $this->sharedForms[$targetId] = $form;
            }
            $form->baseIndexes[$baseIndex] = true;
        }

        return [$form->id, $envelope];
    }

    private function queueIndividualForm(Action $action): string
    {
        $form = $this->individualForm($action);
        $this->pendingForms[] = $form;

        return $form->id;
    }

    /** RT-4.9 */
    private function individualForm(Action $action): ActionForm
    {
        [$targetId, $state, $envelope] = $this->stamp($action);

        $form = new ActionForm(self::newFormId(), $targetId, $action->storageType, $envelope);
        $baseIndex = self::baseIndexToCarry($action, $state);
        if ($baseIndex !== null) {
            $form->baseIndexes[$baseIndex] = true;
        }

        return $form;
    }

    private function newForm(string $targetId, ?StorageType $storageType): ActionForm
    {
        return new ActionForm(self::newFormId(), $targetId, $storageType);
    }

    /**
     * ページ内で一意にするため乱数から作る。インスタンスから決めると、アプリの form の外に残った古いフォームを
     * 部分更新の後の新しいボタンが指してしまう (RT-4.4)。
     */
    private static function newFormId(): string
    {
        return 'rosanna-f-' . bin2hex(random_bytes(8));
    }

    /**
     * 描画時のバージョンを入れて署名する (VR-1、AC-20)。
     *
     * @return array{string, ComponentState, string} 対象のインスタンス ID、その state、エンベロープ
     */
    private function stamp(Action $action): array
    {
        $targetId = $action->componentId ?? $this->rootId;
        $state = self::stateOf($targetId, $action->storageType);
        $json = $action->withVersion($state->version())->toJson();

        return [$targetId, $state, new ActionEnvelope($json, $this->signer->sign($targetId, $json))->toJson()];
    }

    /** getInstance() は現在の state を変えるので戻す。描画の途中で hooks が別のインスタンスに向かないようにする */
    private static function stateOf(string $componentId, ?StorageType $storageType): ComponentState
    {
        $current = ComponentState::current();
        $state = ComponentState::getInstance($componentId, $storageType);
        ComponentState::setCurrent($current);

        return $state;
    }

    /** AC-18 差分の基準がサーバーにない Session と Memory のときだけ */
    private static function baseIndexToCarry(Action $action, ComponentState $state): ?int
    {
        $index = $action->payload['index'] ?? null;
        if (!is_int($index) || !array_key_exists('patch', $action->payload)) {
            return null;
        }
        if ($state->isSnapshotStorage() || !$state->hasRenderedValue($index)) {
            return null;
        }
        // Memory の保存値はこのリクエストで消えるので、次の POST の基準にならない
        if ($state->getStorageType() === StorageType::Session && $state->hasState($index)) {
            return null;
        }

        return $index;
    }

    /** action を除く hidden フィールド。CSRF トークンはここで読む (RT-4.6) */
    private function renderFormFields(ActionForm $form): string
    {
        $form->written = true;

        $state = self::stateOf($form->componentId, $form->storageType);
        $fields = self::hiddenField('_rosanna_component', $form->componentId);
        if ($form->envelope !== null) {
            $fields .= self::hiddenField('_rosanna_action', $form->envelope);
        }

        $token = $this->csrf->token();
        if ($token !== '') {
            $fields .= self::hiddenField(CsrfGuard::FIELD, $token);
        }
        if ($state->isSnapshotStorage()) {
            $fields .= self::hiddenField('_rosanna_snapshot', ComponentWrapper::serialize($state, $this->snapshotSerializer));
        }
        foreach (array_keys($form->baseIndexes) as $index) {
            $value = json_encode($state->renderedValue($index), Action::JSON_FLAGS);
            $fields .= self::hiddenField("_rosanna_base[{$index}]", $value);
        }
        if ($this->deferQuery !== null) {
            $fields .= self::hiddenField('_rosanna_defer_query', $this->deferQuery);
        }
        if ($this->deferName !== null) {
            $fields .= self::hiddenField('_rosanna_defer', $this->deferName);
        }

        return $fields;
    }

    /** @internal ComponentRenderer のため */
    public static function hiddenField(string $name, string $value, string $extra = ''): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s"%s />', self::escape($name), self::escape($value), $extra);
    }

    /** defer のプレースホルダー。属性名は rosanna.js との契約 */
    public function renderDeferred(Element $element, \Closure $render): string
    {
        $name = $element->props['__name'] ?? null;
        if (!is_string($name) || !Defer::isValidName($name)) {
            throw new \RuntimeException(sprintf('Deferred component name must match %s, got %s.', Defer::NAME_PATTERN, self::quote($name)));
        }

        $params = $element->props['__params'] ?? [];
        $query = [];
        foreach (is_array($params) ? $params : [] as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \RuntimeException("Deferred component '{$name}' params must use non-empty string keys.");
            }
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException("Deferred component '{$name}' param '{$key}' must be scalar; got " . get_debug_type($value));
            }
            $query[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        $url = rtrim($this->deferPrefix, '/') . '/' . rawurlencode($name);
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $attributes = sprintf('data-rosanna-defer-url="%s"', self::escape($url));
        if (($element->props['__localCache'] ?? false) === true) {
            $attributes .= ' data-rosanna-defer-cache';
            $ttl = $element->props['__localCacheTtl'] ?? 0;
            if (is_int($ttl) && $ttl > 0) {
                $attributes .= sprintf(' data-rosanna-defer-cache-ttl="%d"', $ttl);
            }
        }
        if (($element->props['__reloadable'] ?? false) === true) {
            $attributes .= sprintf(' data-rosanna-defer-name="%s"', self::escape($name));
        }

        $fallback = $element->props['__fallback'] ?? null;

        return sprintf('<div %s>%s</div>', $attributes, $fallback instanceof Element ? $render($fallback) : '');
    }

    /** ENT_SUBSTITUTE がないと、不正な UTF-8 の 1 バイトで値全体が空になる (RT-3.1) */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function quote(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
