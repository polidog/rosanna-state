<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Protocol;

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Http\Response;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RenderScope;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Runtime\StateDiff;
use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Snapshot\SnapshotUrl;
use Polidog\Rosanna\Snapshot\SnapshotVerificationException;
use Polidog\Rosanna\Storage\NativeSession;
use Polidog\Rosanna\Storage\StorageType;

/**
 * action の POST を受け、状態を更新して応答を決める。応答の判断はここの 1 か所に置く。
 * リクエストの情報をインスタンスに持たない (RQ-3)。送信は host か `Response::send()` が行う (RQ-2)。
 */
final class ActionHandler
{
    private const FIELD_ACTION = '_rosanna_action';
    private const FIELD_COMPONENT = '_rosanna_component';
    private const FIELD_SNAPSHOT = '_rosanna_snapshot';
    private const FIELD_SNAPSHOTS = '_rosanna_snapshots';
    private const FIELD_BASE = '_rosanna_base';
    private const FIELD_DEFER_QUERY = '_rosanna_defer_query';

    private const MAX_CARRIED_SNAPSHOTS = 256;
    private const MAX_FIELD_BYTES = 65_535;

    /** 本文は一定にする (HD-5)。理由はログにだけ出す */
    private const INVALID_REQUEST = 'Invalid action request';
    private const INVALID_DATA = 'Invalid action data';
    private const INVALID_SIGNATURE = 'Invalid action signature';
    private const INVALID_SNAPSHOT = 'Invalid snapshot';
    private const INVALID_FORM = 'Invalid form data';

    public function __construct(private readonly Rosanna $app) {}

    /**
     * Rosanna の action でなければ null を返す。host はこれより前にページの権限を確かめる。
     *
     * @param null|\Closure(bool $partial): (Element|string) $replay ページを描き直す。例外は host に伝える (HD-8)
     */
    public function handle(
        Request $request,
        ?\Closure $replay = null,
        SnapshotBehavior $behavior = SnapshotBehavior::Isolated,
    ): ?Response {
        // 段 1、2。ここまではセッションに触らない (HD-2)。Relayer のトークンは素通りする (HD-1)
        if ($request->method !== 'POST') {
            return null;
        }
        $rawEnvelope = $request->post[self::FIELD_ACTION] ?? null;
        if (!is_string($rawEnvelope)) {
            return null;
        }
        try {
            $envelope = ActionEnvelope::decode($rawEnvelope);
            if ($envelope === null) {
                return null;
            }
        } catch (\InvalidArgumentException) {
            // オブジェクトで形が不正なものは CSRF の後の段 4 で断る
            $envelope = false;
        }

        // 段 3。CS-1 Cookie からセッションを再開してから判定する
        $csrf = $this->app->csrf();
        $csrf->resumeSession($request);
        $reason = $csrf->verify($request);
        if ($reason !== null) {
            self::log($reason);

            return self::error(403, 'Forbidden');
        }

        if ($envelope === false) {
            return self::badRequest(self::INVALID_REQUEST);
        }

        $parentState = ComponentState::current();
        $outerPass = RenderContext::currentPass();
        try {
            return $this->process($request, $envelope, $replay, $behavior);
        } finally {
            ComponentState::setCurrent($parentState);
            RenderContext::resumePass($outerPass);
        }
    }

    /** @param null|\Closure(bool): (Element|string) $replay */
    private function process(Request $request, ActionEnvelope $envelope, ?\Closure $replay, SnapshotBehavior $behavior): Response
    {
        // 段 4
        $instanceId = $request->post[self::FIELD_COMPONENT] ?? null;
        $snapshotJson = $request->post[self::FIELD_SNAPSHOT] ?? null;
        $carried = $request->post[self::FIELD_SNAPSHOTS] ?? [];
        $deferQuery = $request->post[self::FIELD_DEFER_QUERY] ?? null;
        if (
            !is_string($instanceId) || !Action::isValidInstanceId($instanceId)
            || ($snapshotJson !== null && !is_string($snapshotJson))
            || !is_array($carried)
            || ($deferQuery !== null && !is_string($deferQuery))
        ) {
            return self::badRequest(self::INVALID_REQUEST);
        }

        $action = self::parseAction($envelope->actionJson);
        if ($action === null) {
            return self::badRequest(self::INVALID_DATA);
        }

        // 段 5
        $scope = $this->app->scope($request);
        $rejection = self::verifySignature($scope, $action, $envelope, $instanceId, $snapshotJson);
        if ($rejection !== null) {
            return self::badRequest($rejection);
        }

        $componentName = explode('#', $instanceId, 2)[0];
        $registered = $this->app->components()->has($componentName);
        // AC-11
        $storage = $action->type === Action::RESTORE
            ? StorageType::Snapshot
            : ($action->storageType ?? ($registered ? $this->app->components()->getStorageType($componentName) : StorageType::Session));

        // 段 6。SN-13 全部を検証してから 1 つでも復元する
        $own = null;
        $others = [];
        try {
            if ($storage === StorageType::Snapshot) {
                $own = $this->verifyOwnSnapshot($instanceId, $snapshotJson);
            }
            $others = $this->verifyCarriedSnapshots($carried, $instanceId);
        } catch (SnapshotVerificationException $e) {
            self::log('Snapshot rejected: ' . $e->getMessage());

            return self::badRequest(self::INVALID_SNAPSHOT);
        }

        foreach ($others as $snapshot) {
            ComponentState::fromSnapshot($snapshot);
        }
        $state = $own !== null ? ComponentState::fromSnapshot($own) : ComponentState::getInstance($instanceId, $storage);

        // 段 7、8。不一致はエラーではない。普通の操作で起きるのでログにも出さない
        $base = self::baseValue($action, $state, $storage, $request);
        $stale = self::isStale($action, $state, $storage, $base);
        if (!$stale) {
            $rejection = self::apply($action, $state, $request, $base);
            if ($rejection !== null) {
                return self::badRequest($rejection);
            }
        }

        return $this->respond(new ActionContext(
            $request,
            $scope,
            $action,
            $state,
            $storage,
            $behavior,
            $instanceId,
            $componentName,
            $registered,
            array_keys($others),
            $stale,
            $deferQuery === '' ? null : $deferQuery,
        ), $replay);
    }

    private static function parseAction(string $json): ?Action
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

            return is_array($data) ? Action::fromArray($data) : null;
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }

    /** @return string|null 拒否の本文 */
    private static function verifySignature(RenderScope $scope, Action $action, ActionEnvelope $envelope, string $instanceId, ?string $snapshotJson): ?string
    {
        if ($action->type === Action::RESTORE) {
            // AC-8。restore は rosanna.js が組み立てるので JSON を署名できない
            if ($snapshotJson === null || ($action->componentId !== null && $action->componentId !== $instanceId)) {
                return self::INVALID_REQUEST;
            }
            $signed = Action::RESTORE;
        } else {
            $signed = $envelope->actionJson;
        }

        // HD-3、AC-9
        if ($envelope->signature === null || !$scope->signer->verify($instanceId, $signed, $envelope->signature)) {
            self::log('Action signature missing or invalid for ' . $instanceId);

            return self::INVALID_SIGNATURE;
        }

        // AC-10
        if ($action->componentId !== null && $action->componentId !== $instanceId) {
            return self::INVALID_REQUEST;
        }

        return null;
    }

    /** @throws SnapshotVerificationException */
    private function verifyOwnSnapshot(string $instanceId, ?string $json): ?Snapshot
    {
        $serializer = $this->app->snapshotSerializer() ?? throw new SnapshotVerificationException('No secret key is set');
        if ($json === null) {
            return null;
        }

        // memo は HMAC の対象。リクエストが操作すると言うインスタンスを指すこと
        $snapshot = $serializer->deserialize($json);
        if ($snapshot->getInstanceId() !== $instanceId) {
            throw new SnapshotVerificationException('Snapshot names another instance');
        }

        return $snapshot;
    }

    /**
     * 署名された memo の指すインスタンスにだけ復元する (SN-15)。対象自身を指すものは無視する (SN-16)。
     *
     * @param array<mixed> $carried
     * @return array<string, Snapshot>
     * @throws SnapshotVerificationException
     */
    private function verifyCarriedSnapshots(array $carried, string $instanceId): array
    {
        if ($carried === []) {
            return [];
        }

        // SN-17
        $serializer = $this->app->snapshotSerializer() ?? throw new SnapshotVerificationException('Carried snapshots without a secret key');

        // SN-14
        if (count($carried) > self::MAX_CARRIED_SNAPSHOTS) {
            self::log(sprintf('An action carried %d snapshots; only the first %d are read', count($carried), self::MAX_CARRIED_SNAPSHOTS));
            $carried = array_slice($carried, 0, self::MAX_CARRIED_SNAPSHOTS);
        }

        $snapshots = [];
        foreach ($carried as $json) {
            if (!is_string($json)) {
                throw new SnapshotVerificationException('Carried snapshot is not a string');
            }
            $snapshot = $serializer->deserialize($json);
            if ($snapshot->getInstanceId() !== $instanceId) {
                $snapshots[$snapshot->getInstanceId()] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * AC-16。差分でなければ null。
     *
     * @return array{found: bool, value: mixed}|null
     */
    private static function baseValue(Action $action, ComponentState $state, StorageType $storage, Request $request): ?array
    {
        if (!array_key_exists('patch', $action->payload)) {
            return null;
        }

        /** @var int $index */
        $index = $action->payload['index'];
        if ($state->hasState($index)) {
            return ['found' => true, 'value' => $state->getState($index, null)];
        }
        if ($storage === StorageType::Snapshot) {
            return ['found' => false, 'value' => null];
        }

        // AC-18 署名しない。署名済みの base と比べるので、改ざんは適用されない
        $bases = $request->post[self::FIELD_BASE] ?? null;
        $json = is_array($bases) ? ($bases[$index] ?? null) : null;
        if (!is_string($json)) {
            return ['found' => false, 'value' => null];
        }

        try {
            return ['found' => true, 'value' => json_decode($json, true, flags: JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return ['found' => false, 'value' => null];
        }
    }

    /**
     * VR-4、VR-5、VR-8、VR-10、AC-17。比べるのは操作対象だけ。Session はセッションがアクティブなまま比べて書くので、
     * PHP の既定のファイルセッションでは同じセッションの他のリクエストと重ならない。
     *
     * @param array{found: bool, value: mixed}|null $base
     */
    private static function isStale(Action $action, ComponentState $state, StorageType $storage, ?array $base): bool
    {
        if ($storage === StorageType::Session && $action->version !== null && $action->version !== $state->version()) {
            return true;
        }
        if ($base === null) {
            return false;
        }

        $expected = $action->payload['base'] ?? null;
        if (!$base['found'] || !is_string($expected)) {
            return true;
        }

        try {
            return !hash_equals(StateDiff::hash($base['value']), $expected);
        } catch (\InvalidArgumentException) {
            return true; // `_rosanna_base` はクライアント入力で、`1e999` は JSON に戻せない
        }
    }

    /**
     * @param array{found: bool, value: mixed}|null $base
     * @return string|null 拒否の本文
     */
    private static function apply(Action $action, ComponentState $state, Request $request, ?array $base): ?string
    {
        if ($action->type === Action::RESTORE) {
            return null; // VR-2
        }

        if ($action->type === Action::SET_FIELDS) {
            $values = self::formValues($action, $request);
            if ($values === null) {
                return self::INVALID_FORM;
            }
            foreach ($values as $index => $value) {
                $state->setState($index, $value);
            }
            $state->incrementVersion();

            return null;
        }

        /** @var int $index */
        $index = $action->payload['index'];
        try {
            if ($base !== null) {
                /** @var array<mixed> $patch */
                $patch = $action->payload['patch'];
                $value = StateDiff::apply($base['value'], $patch);
            } else {
                $value = $action->payload['value'];
            }
            $state->setState($index, $value);
        } catch (\InvalidArgumentException) {
            return self::INVALID_DATA;
        }
        $state->incrementVersion();

        return null;
    }

    /**
     * AC-7。名前と index は署名済みで、値はクライアント入力。全欄を検証してから書く。
     * 送られなかった欄は空文字で、チェックを外した checkbox がフックを空にする。
     *
     * @return array<int, string>|null
     */
    private static function formValues(Action $action, Request $request): ?array
    {
        /** @var array<string, int> $fields */
        $fields = $action->payload['fields'];

        $values = [];
        foreach ($fields as $name => $index) {
            $value = $request->post[$name] ?? '';
            if (!is_string($value) || strlen($value) > self::MAX_FIELD_BYTES || preg_match('//u', $value) !== 1) {
                return null;
            }
            $values[$index] = $value;
        }

        return $values;
    }

    /**
     * 段 9。応答の判断の表。
     *
     * @param null|\Closure(bool): (Element|string) $replay
     */
    private function respond(ActionContext $context, ?\Closure $replay): Response
    {
        $redirects = $context->storage === StorageType::Snapshot && $context->behavior !== SnapshotBehavior::Isolated;

        if (isset($context->request->headers['x-rosanna-partial'])) {
            if ($context->registered && ($context->action->type === Action::RESTORE || !$redirects)) {
                return self::fragmentResponse($this->renderRegisteredFragment($context), $context);
            }
            if (!$context->registered && !$redirects && $replay !== null) {
                $answer = $this->replayFragment($context, $replay);
                if ($answer !== null) {
                    return $answer;
                }
            }
        } elseif (
            $replay !== null
            && $context->behavior === SnapshotBehavior::Isolated
            && ($context->storage === StorageType::Snapshot || $context->carriedIds !== [])
        ) {
            return $this->replayFullPage($context, $replay);
        }

        return $this->redirect($context);
    }

    /** SN-12。ラッパーの中身だけを返す。Snapshot は末尾に data-rosanna-snapshot-update が付く */
    private function renderRegisteredFragment(ActionContext $context): string
    {
        $name = $context->componentName;
        $component = $this->app->components()->create($name);
        if ($component === null) {
            throw new \LogicException("Component {$name} is not registered.");
        }

        return RenderContext::run($context->scope, function () use ($context, $component, $name): string {
            RenderContext::beginRender();
            self::reserveInstanceNumber($name, $context->instanceId);

            return self::withDeferQuery($context->deferQuery, static fn(): string => $context->scope->renderers
                ->create($context->instanceId, $context->storage, deferQuery: $context->deferQuery)
                ->renderPartial(static function () use ($component): Element {
                    $state = ComponentState::current();
                    if ($component instanceof BaseComponent && $state !== null) {
                        $component->setComponentState($state);
                    }

                    return $component->render();
                }));
        });
    }

    /** GET ではこのインスタンスが番号を使っている。同じ名前の子の自動採番を揃える (HD-7) */
    private static function reserveInstanceNumber(string $name, string $instanceId): void
    {
        $key = substr($instanceId, strlen($name) + 1);
        if (preg_match('/^\d{1,9}$/D', $key) === 1) {
            RenderContext::resumePass(['instanceCounts' => [$name => (int) $key + 1], 'componentStack' => []]);
        }
    }

    /**
     * fc() はレジストリにないので、ページを描き直して木からラッパーを取り出す。見つからなければ null で redirect に落ちる。
     *
     * @param \Closure(bool): (Element|string) $replay
     */
    private function replayFragment(ActionContext $context, \Closure $replay): ?Response
    {
        $scope = $context->scope;

        $found = RenderContext::run($scope, function () use ($context, $replay): ?array {
            // HD-6 部分更新の間に出力したものは断片を壊し、redirect のヘッダーも止めるので捨てる
            $roots = self::buffered(function () use ($context, $replay): array {
                RenderContext::beginRender();
                [$result, $collected] = $this->app->collectRendered(static fn(): mixed => $replay(true));
                if ($result instanceof Element) {
                    return [$result, ...$collected];
                }

                // Session は見つからなくても redirect で状態を失わないので探さない。古い action は通知が要るので探す (VR-4)
                return $context->storage === StorageType::Session && !$context->stale ? [] : $collected;
            })[0];

            return WrapperSearch::find(
                $roots,
                $context->instanceId,
                fn(string $name, array $props): ?Element => $this->app->deferredEndpoint()->renderForReplay($context->request, $name, $props),
            );
        });
        if ($found === null) {
            return null;
        }
        [$wrapper, $searched] = $found;

        // SN-18 祖先の props を通じてクライアントが選んだ状態が出力に届くのを防ぐ。ツリーにない ID は問題にしない
        $outside = [];
        foreach ($searched as $root) {
            $outside = [...$outside, ...WrapperSearch::instanceIdsOutside($root, $context->instanceId)];
        }
        if (array_intersect($context->carriedIds, $outside) !== []) {
            self::log('An action on ' . $context->instanceId . ' carried a snapshot of an instance that is not its descendant');

            return self::badRequest(self::INVALID_SNAPSHOT);
        }

        $html = RenderContext::run($scope, static fn(): string => self::withDeferQuery($context->deferQuery, static fn(): string => $scope->renderers
            ->create($context->instanceId, $context->storage, deferQuery: $context->deferQuery)
            ->renderPartial(static fn(): array => $wrapper->children)));

        return self::fragmentResponse($html, $context);
    }

    /**
     * HD-6 replay が出力したものを含める。SN-19 各 action フォームにページ上のスナップショットを足す。
     *
     * @param \Closure(bool): (Element|string) $replay
     */
    private function replayFullPage(ActionContext $context, \Closure $replay): Response
    {
        $scope = $context->scope;

        [$html, $printed] = RenderContext::run($scope, static fn(): array => self::buffered(static function () use ($replay, $scope): string {
            RenderContext::beginRender();
            $result = $replay(false);

            return $result instanceof Element ? $scope->renderers->create('_root_')->renderElement($result) : $result;
        }));

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
            SnapshotCarrier::carry($printed . $html, $this->app->snapshotSerializer()),
        );
    }

    /**
     * HD-13、HD-14
     */
    private function redirect(ActionContext $context): Response
    {
        $target = RedirectTarget::fromUri($context->request->uri);

        if ($context->storage === StorageType::Snapshot) {
            $target = match ($context->behavior) {
                SnapshotBehavior::Persistent => $this->persistentTarget($context, $target),
                SnapshotBehavior::Session => $this->writeSessionSnapshot($context, $target),
                SnapshotBehavior::Isolated => self::warnStateLost($context, $target),
            };
        }

        return new Response(303, ['Location' => $target, 'Cache-Control' => 'no-store']);
    }

    private function persistentTarget(ActionContext $context, string $target): string
    {
        self::warnWhenPersistentSnapshotIsReplaced($context);

        $serializer = $this->app->snapshotSerializer();
        if ($serializer === null) {
            return $target;
        }

        $snapshot = $context->state->createSnapshot();
        try {
            return SnapshotUrl::with($target, $snapshot, $serializer);
        } catch (\InvalidArgumentException) {
            // 行き先は RedirectTarget が揃えているので通常は届かない。パスはクライアントが選べるので例外にしない
            return SnapshotUrl::with('/', $snapshot, $serializer);
        }
    }

    /** HD-12 比較にしか使わず状態に入れないので、ここだけは検証しない */
    private static function warnWhenPersistentSnapshotIsReplaced(ActionContext $context): void
    {
        $carried = SnapshotUrl::extract($context->request);
        if ($carried === null) {
            return;
        }

        try {
            $carriedId = Snapshot::fromJson($carried)->getInstanceId();
        } catch (\JsonException|\InvalidArgumentException) {
            return;
        }

        if ($carriedId !== $context->instanceId) {
            self::log(sprintf(
                'Snapshot replaced (behavior=Persistent): the URL carries only one instance, so the action on %s drops the state of the other one. '
                . 'Use SnapshotBehavior::Session for a page with several snapshot instances.',
                $context->instanceId,
            ));
        }
    }

    private function writeSessionSnapshot(ActionContext $context, string $target): string
    {
        $serializer = $this->app->snapshotSerializer();
        // 匿名の訪問者の GET はセッションを始めない (ST-2) ので、最初の書き込みで始める
        if ($serializer !== null && NativeSession::start()) {
            SessionSnapshots::store($serializer, $context->state->createSnapshot());
        } else {
            self::log('Snapshot not stored (behavior=Session): the session could not be started for ' . $context->instanceId);
        }

        return $target;
    }

    private static function warnStateLost(ActionContext $context, string $target): string
    {
        self::log(sprintf(
            'Snapshot state lost: %s could not be re-rendered in place, so this action falls back to a redirect that starts from its initial state. '
            . 'Pass a replay that renders the component to handle(), or use SnapshotBehavior::Persistent or Session.',
            $context->instanceId,
        ));

        return $target;
    }

    /**
     * HD-11。署名しない。この POST のクエリは元からクライアントが選べるので、渡すものが増えない。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function withDeferQuery(?string $deferQuery, callable $fn): mixed
    {
        if ($deferQuery === null) {
            return $fn();
        }

        $outerGet = $_GET;
        parse_str($deferQuery, $params);
        $_GET = $params;
        try {
            return $fn();
        } finally {
            $_GET = $outerGet;
        }
    }

    /**
     * 例外でも、replay が閉じ忘れたバッファがあっても元のレベルに戻す (RQ-2)。
     *
     * @template T
     * @param callable(): T $fn
     * @return array{T, string} 戻り値と出力
     */
    private static function buffered(callable $fn): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $result = $fn();
            $printed = '';
            while (ob_get_level() > $level) {
                $printed = (string) ob_get_clean() . $printed;
            }

            return [$result, $printed];
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private static function fragmentResponse(string $html, ActionContext $context): Response
    {
        $headers = ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'];
        if ($context->stale) {
            $headers['X-Rosanna-Stale'] = '1';
        }
        // HD-16 描き直されなかったフォームはセッションの前に描画されたので CS-3 と SG-1 を通らない。この後でしか判定できない
        if (NativeSession::isActive() && !NativeSession::hasCookie($context->request->cookies)) {
            $headers['X-Rosanna-Session-Started'] = '1';
        }

        return new Response(200, $headers, $html);
    }

    private static function badRequest(string $body): Response
    {
        return self::error(400, $body);
    }

    private static function error(int $status, string $body): Response
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store'], $body);
    }

    private static function log(string $message): void
    {
        error_log('[Rosanna] ' . $message);
    }
}
