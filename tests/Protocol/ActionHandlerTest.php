<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Protocol\ActionEnvelope;
use Polidog\Rosanna\Protocol\RedirectTarget;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Runtime\StateDiff;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Protocol\Fixtures\DeferredPanel;
use Polidog\Rosanna\Tests\Protocol\Fixtures\MemoryCounter;
use Polidog\Rosanna\Tests\Protocol\Fixtures\SnapshotCounter;
use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/**
 * 02 の 7 節。セッションを使わない Memory と Snapshot で、判定の順番と応答の判断の表を確かめる。
 * Session は tests/Integration の実際の HTTP で確かめる。
 */
final class ActionHandlerTest extends TestCase
{
    use ActionRequests;

    private const SECRET = 'handler-test-secret';

    protected function setUp(): void
    {
        $this->captureLog();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
        $this->releaseLog();
        self::assertSame(PHP_SESSION_NONE, session_status(), 'A test in this process must not start a session');
    }

    private static function app(): Rosanna
    {
        return new Rosanna()->setSnapshotSecret(self::SECRET);
    }

    private static function counter(StorageType $storage, string $key = 'a'): FunctionComponent
    {
        return fc(static function (array $props): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::span(children: 'count=' . $count), H::button(onClick: static fn() => $set($count + 1), children: 'inc')]);
        }, key: $key, storageType: $storage);
    }

    private static function rendered(Rosanna $app, string $class, string $uri = '/page'): string
    {
        return RenderContext::run($app->scope(self::get($uri)), static fn(): string => $app->render($class));
    }

    private static function idOf(string $html): string
    {
        $wrapper = self::dom($html)->querySelector('[data-rosanna]');
        self::assertNotNull($wrapper);

        return (string) $wrapper->getAttribute('data-rosanna');
    }

    // --- 判定の順番 ---

    /** @return iterable<string, array{string}> */
    public static function notActions(): iterable
    {
        yield 'relayer token' => ['rosanna-action:abc'];
        yield 'json list' => ['[1]'];
        yield 'not json' => ['abc'];
        yield 'json scalar' => ['1'];
        yield 'empty' => [''];
    }

    #[DataProvider('notActions')]
    public function testHD1ValuesThatAreNotJsonObjectsAreLeftToTheHost(string $value): void
    {
        self::assertNull(self::app()->actions()->handle(self::post(['_rosanna_action' => $value])));
        // HD-2 CSRF もセッションも動かない
        self::assertNull(self::app()->actions()->handle(self::post(['_rosanna_action' => $value], headers: ['origin' => 'http://evil.test'])));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testHD1OrdinaryRequestsAreLeftToTheHost(): void
    {
        $handler = self::app()->actions();

        self::assertNull($handler->handle(self::get()));
        self::assertNull($handler->handle(self::post(['name' => 'x'])));
        self::assertNull($handler->handle(self::post(['_rosanna_action' => ['x']])));
    }

    public function testStep3CsrfRunsBeforeTheFieldsAreLookedAt(): void
    {
        $response = self::app()->actions()->handle(self::post(['_rosanna_action' => '{"bad":1}'], headers: ['origin' => 'http://evil.test']));

        self::assertNotNull($response);
        self::assertSame(403, $response->status);
        self::assertSame('Forbidden', $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        // CS-8 拒否の理由はログにだけ出す
        self::assertStringContainsString('[Rosanna] CSRF check failed', $this->log());
    }

    public function testStep3ValidActionFromAnotherOriginDoesNotChangeTheState(): void
    {
        $app = self::app();
        $html = self::page($app, static fn() => self::counter(StorageType::Memory)());
        $fields = self::press($html);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, headers: ['origin' => 'http://evil.test']));

        self::assertNotNull($response);
        self::assertSame(403, $response->status);
        self::assertFalse(ComponentState::getInstance(self::idOf($html), StorageType::Memory)->hasState(0));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badFields(): iterable
    {
        $envelope = new ActionEnvelope('{"type":"restore"}', null)->toJson();

        yield 'envelope without sig' => [['_rosanna_action' => '{"action":"{}"}', '_rosanna_component' => 'A#0'], 'Invalid action request'];
        yield 'envelope with numeric action' => [['_rosanna_action' => '{"action":1,"sig":null}', '_rosanna_component' => 'A#0'], 'Invalid action request'];
        yield 'no component' => [['_rosanna_action' => $envelope], 'Invalid action request'];
        yield 'array component' => [['_rosanna_action' => $envelope, '_rosanna_component' => ['A#0']], 'Invalid action request'];
        yield 'AC-6 id without hash' => [['_rosanna_action' => $envelope, '_rosanna_component' => 'A0'], 'Invalid action request'];
        yield 'AC-6 long id' => [['_rosanna_action' => $envelope, '_rosanna_component' => 'A#' . str_repeat('x', 300)], 'Invalid action request'];
        yield 'AC-6 control character' => [['_rosanna_action' => $envelope, '_rosanna_component' => "A#\n0"], 'Invalid action request'];
        yield 'array snapshot' => [['_rosanna_action' => $envelope, '_rosanna_component' => 'A#0', '_rosanna_snapshot' => ['x']], 'Invalid action request'];
        yield 'scalar snapshots' => [['_rosanna_action' => $envelope, '_rosanna_component' => 'A#0', '_rosanna_snapshots' => 'x'], 'Invalid action request'];
        yield 'array defer query' => [['_rosanna_action' => $envelope, '_rosanna_component' => 'A#0', '_rosanna_defer_query' => ['x']], 'Invalid action request'];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('badFields')]
    public function testStep4FieldShapesAreBadRequests(array $fields, string $body): void
    {
        $response = self::app()->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame($body, $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedActions(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'json scalar' => ['1'];
        yield 'AC-1 unknown type' => ['{"type":"deleteAll","payload":{}}'];
        yield 'AC-3 type not a string' => ['{"type":1}'];
        yield 'AC-3 payload not an array' => ['{"type":"setState","payload":1}'];
        yield 'AC-3 version not an int' => ['{"type":"setState","payload":{"index":0,"value":1},"version":"1"}'];
        yield 'AC-3 unknown storage' => ['{"type":"setState","payload":{"index":0,"value":1},"storageType":"redis"}'];
        yield 'AC-3 value and patch' => ['{"type":"setState","payload":{"index":0,"value":1,"patch":[],"base":"x"}}'];
        yield 'AC-5 index above the range' => ['{"type":"setState","payload":{"index":256,"value":1}}'];
        yield 'AC-5 negative index' => ['{"type":"setState","payload":{"index":-1,"value":1}}'];
        yield 'AC-6 bad component id' => ['{"type":"setState","payload":{"index":0,"value":1},"componentId":"x"}'];
        yield 'restore with a payload' => ['{"type":"restore","payload":{"a":1}}'];
    }

    /** AC-1、AC-3、AC-5: 署名が通っても形が不正なら 400。段 4 なので状態は変わらない */
    #[DataProvider('malformedActions')]
    public function testStep4MalformedActionsAreBadRequestsNotTypeErrors(string $json): void
    {
        $app = self::app();
        $response = $app->actions()->handle(self::post(self::signed($app, 'A#0', $json)));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action data', $response->body);
    }

    public function testStep5UnsignedActionIsRejectedHD3(): void
    {
        $json = '{"type":"setState","payload":{"index":0,"value":1},"componentId":"A#0","storageType":"memory"}';
        $response = self::app()->actions()->handle(self::post([
            '_rosanna_component' => 'A#0',
            '_rosanna_action' => new ActionEnvelope($json, null)->toJson(),
        ]));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action signature', $response->body);
        self::assertSame('none', ComponentState::getInstance('A#0', StorageType::Memory)->getState(0, 'none'));
    }

    public function testStep5TamperedActionIsRejectedAC20(): void
    {
        $app = self::app();
        $html = self::page($app, static fn() => self::counter(StorageType::Memory)());
        $fields = self::press($html);
        $id = self::idOf($html);
        RequestLifecycle::reset();

        // actionJson を 1 バイト変える
        $envelope = json_decode((string) $fields['_rosanna_action'], true);
        self::assertIsArray($envelope);
        $fields['_rosanna_action'] = json_encode(['action' => str_replace('"value":1', '"value":9', (string) $envelope['action']), 'sig' => $envelope['sig']]);

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action signature', $response->body);
        self::assertSame('none', ComponentState::getInstance($id, StorageType::Memory)->getState(0, 'none'));
    }

    public function testStep5SignatureForAnotherInstanceIsRejected(): void
    {
        $app = self::app();
        $json = '{"type":"setState","payload":{"index":0,"value":1},"storageType":"memory"}';
        $fields = self::signed($app, 'A#0', $json);
        $fields['_rosanna_component'] = 'B#0';

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame('Invalid action signature', $response->body);
    }

    /** VR-1 JSON の version は署名の対象 */
    public function testVR1ChangingTheVersionFailsTheSignature(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)()));
        $envelope = json_decode((string) $fields['_rosanna_action'], true);
        self::assertIsArray($envelope);
        self::assertStringContainsString('"version":0', (string) $envelope['action']);
        $fields['_rosanna_action'] = json_encode(['action' => str_replace('"version":0', '"version":7', (string) $envelope['action']), 'sig' => $envelope['sig']]);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame('Invalid action signature', $response->body);
    }

    public function testAC10SignedComponentIdMustMatchTheField(): void
    {
        $app = self::app();
        $json = '{"type":"setState","payload":{"index":0,"value":1},"componentId":"B#0","storageType":"memory"}';

        $response = $app->actions()->handle(self::post(self::signed($app, 'A#0', $json)));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action request', $response->body);
        self::assertSame('none', ComponentState::getInstance('A#0', StorageType::Memory)->getState(0, 'none'));
    }

    public function testSG5AFormCannotBePostedToAnotherPage(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)(), '/guarded'));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: '/open'));

        self::assertNotNull($response);
        self::assertSame('Invalid action signature', $response->body);
    }

    public function testSG5TrailingSlashIsTheSamePage(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)(), '/page'));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: '/page/'));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
    }

    /** SG-7、SG-8 検証は host が渡した Request のパスで行い、リクエストのフィールドは範囲を選ばない */
    public function testSG7NoClientFieldChoosesTheBinding(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)(), '/guarded'));
        RequestLifecycle::reset();
        $fields += ['scope' => 'page:/guarded', 'path' => '/guarded', '_rosanna_page' => '/guarded', '_rosanna_scope' => 'page:/guarded'];

        $rejected = $app->actions()->handle(self::post($fields + ['x' => 1], uri: '/open?path=/guarded'));
        self::assertNotNull($rejected);
        self::assertSame('Invalid action signature', $rejected->body);

        // 書き換えた後のパスを渡す host (Relayer のロケール) ではそのパスで検証する
        $rewritten = new \Polidog\Rosanna\Http\Request('POST', '/ja/guarded', '/guarded', '', [], $fields, [], ['host' => 'app.test', 'origin' => 'http://app.test']);
        $accepted = $app->actions()->handle($rewritten);
        self::assertNotNull($accepted);
        self::assertSame(303, $accepted->status);
    }

    // --- 段 6: スナップショット ---

    /** @return array{string, string, array<string, mixed>} HTML、ID、ボタンの送信 */
    private static function snapshotPage(Rosanna $app): array
    {
        $html = self::page($app, static fn() => self::counter(StorageType::Snapshot)());
        $id = self::idOf($html);
        $fields = self::press($html);
        RequestLifecycle::reset();

        return [$html, $id, $fields];
    }

    public function testStep6TamperedOwnSnapshotIsRejectedAndNothingIsApplied(): void
    {
        $app = self::app();
        [, $id, $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshot'] = str_replace('"state":[0]', '"state":[5]', (string) $fields['_rosanna_snapshot']);

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid snapshot', $response->body);
        self::assertSame('none', ComponentState::getInstance($id, StorageType::Snapshot)->getState(0, 'none'));
    }

    public function testVR5TamperedMemoVersionFailsVerification(): void
    {
        $app = self::app();
        [, , $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshot'] = str_replace('"version":0', '"version":3', (string) $fields['_rosanna_snapshot']);

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame('Invalid snapshot', $response->body);
    }

    public function testStep6SnapshotOfAnotherInstanceIsRejected(): void
    {
        $app = self::app();
        $other = self::page($app, static fn() => self::counter(StorageType::Snapshot, 'other')());
        $otherSnapshot = self::wrapperAttribute($other, 'data-rosanna-snapshot', self::idOf($other));
        RequestLifecycle::reset();
        [, , $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshot'] = $otherSnapshot;

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame('Invalid snapshot', $response->body);
    }

    private static function memorySnapshotField(Rosanna $app, string $key): string
    {
        $html = self::page($app, static fn() => self::counter(StorageType::Snapshot, $key)());
        $snapshot = self::wrapperAttribute($html, 'data-rosanna-snapshot', self::idOf($html));
        RequestLifecycle::reset();

        return $snapshot;
    }

    public function testSN13OneBadCarriedSnapshotRejectsTheWholeRequestBeforeAnyRestore(): void
    {
        $app = self::app();
        $good = self::memorySnapshotField($app, 'good');
        [, $id, $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshots'] = [$good, '{"memo":{"name":"X","key":"0","version":0},"state":[],"effectDeps":[],"checksum":"bad"}'];

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid snapshot', $response->body);

        // 良いものも復元されず、対象の action も適用されない
        $goodId = (string) json_decode($good, true)['memo']['name'] . '#good';
        self::assertSame('none', ComponentState::getInstance($goodId, StorageType::Snapshot)->getState(0, 'none'));
        self::assertSame('none', ComponentState::getInstance($id, StorageType::Snapshot)->getState(0, 'none'));
    }

    public function testSN13NonStringCarriedSnapshotIsRejected(): void
    {
        $app = self::app();
        [, , $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshots'] = [['nested']];

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame('Invalid snapshot', $response->body);
    }

    public function testSN14SnapshotsOverTheLimitAreNotReadAndTheActionStillRuns(): void
    {
        $app = self::app();
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        $carried = [];
        for ($i = 0; $i < 257; $i++) {
            $carried[] = $serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot('Row', (string) $i, ['v' . $i]));
        }
        [, $id, $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshots'] = $carried;

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertSame(1, ComponentState::getInstance($id)->getState(0, 0));
        self::assertSame('v255', ComponentState::getInstance('Row#255')->getState(0, 'none'));
        self::assertSame('none', ComponentState::getInstance('Row#256', StorageType::Snapshot)->getState(0, 'none'));
        self::assertStringContainsString('carried 257 snapshots', $this->log());
    }

    public function testSN15ACarriedSnapshotOnlyLandsOnTheInstanceItsMemoNames(): void
    {
        $app = self::app();
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        [, , $fields] = self::snapshotPage($app);
        $fields['_rosanna_snapshots'] = ['Row#7' => $serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot('Row', '1', ['one']))];

        $app->actions()->handle(self::post($fields));

        self::assertSame('one', ComponentState::getInstance('Row#1')->getState(0, 'none'));
        self::assertSame('none', ComponentState::getInstance('Row#7', StorageType::Snapshot)->getState(0, 'none'));
    }

    public function testSN16ACarriedSnapshotOfTheTargetIsIgnored(): void
    {
        $app = self::app();
        [, $id, $fields] = self::snapshotPage($app);
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        [$name, $key] = explode('#', $id, 2);
        $fields['_rosanna_snapshots'] = [$serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot($name, $key, [41], version: 9))];

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertSame(1, ComponentState::getInstance($id)->getState(0, 0));
    }

    // --- 応答の判断の表 ---

    public function testRow1PartialRegisteredMemoryAnswersItsFragment(): void
    {
        $app = self::app()->register(MemoryCounter::class);
        $fields = self::press(self::rendered($app, MemoryCounter::class));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static function (): never {
            self::fail('A registered class is answered without a replay');
        });

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        self::assertStringNotContainsString('data-rosanna="MemoryCounter', $response->body);
        self::assertStringStartsWith('<div class="counter">', $response->body);
        self::assertStringContainsString('text/html', $response->headers['Content-Type']);
        self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers);
    }

    public function testRow1PartialRegisteredSnapshotIsolatedAnswersItsFragmentWithTheSnapshotUpdate(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $fields = self::press(self::rendered($app, SnapshotCounter::class));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        self::assertStringNotContainsString('data-rosanna="SnapshotCounter', $response->body);

        // SN-12、VR-5 新しいスナップショットは末尾の hidden で渡し、版が増える
        $update = self::dom($response->body)->querySelector('input[data-rosanna-snapshot-update]');
        self::assertNotNull($update);
        self::assertSame('_rosanna_snapshot', $update->getAttribute('name'));
        $snapshot = json_decode((string) $update->getAttribute('value'), true);
        self::assertSame(1, $snapshot['memo']['version']);
        self::assertSame([1], $snapshot['state']);
        self::assertStringEndsWith('data-rosanna-snapshot-update />', $response->body);
    }

    /** @return iterable<string, array{SnapshotBehavior}> */
    public static function behaviors(): iterable
    {
        yield 'isolated' => [SnapshotBehavior::Isolated];
        yield 'persistent' => [SnapshotBehavior::Persistent];
        yield 'session' => [SnapshotBehavior::Session];
    }

    /** VR-2 restore はバージョンを変えない */
    #[DataProvider('behaviors')]
    public function testRow2PartialRestoreOfARegisteredClassAnswersItsFragmentOnEveryBehavior(SnapshotBehavior $behavior): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class);
        $fields = self::restoreFields('SnapshotCounter#0', self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0'), self::wrapperAttribute($html, 'data-rosanna-snapshot', 'SnapshotCounter#0'));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), behavior: $behavior);

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=0', $response->body);
        self::assertSame(0, ComponentState::getInstance('SnapshotCounter#0')->version());
        $update = self::dom($response->body)->querySelector('input[data-rosanna-snapshot-update]');
        self::assertNotNull($update);
    }

    /** @return iterable<string, array{StorageType}> */
    public static function replayedStorages(): iterable
    {
        yield 'memory' => [StorageType::Memory];
        yield 'snapshot' => [StorageType::Snapshot];
    }

    #[DataProvider('replayedStorages')]
    public function testRow3PartialFcAnswersFromTheReplay(StorageType $storage): void
    {
        $app = self::app();
        $counter = self::counter($storage);
        $page = static fn(): Element => H::section(children: [H::h1(children: 'title'), $counter()]);
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();
        $seen = [];

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static function (bool $partial) use ($page, &$seen): Element {
            $seen[] = $partial;

            return $page();
        });

        self::assertNotNull($response);
        self::assertSame([true], $seen);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        // ラッパーの中身だけ
        self::assertStringNotContainsString('title', $response->body);
        self::assertStringNotContainsString('data-rosanna="', $response->body);
        self::assertStringStartsWith('<div><span>count=1</span>', $response->body);
        // 新しいフォームと新しい action がある
        self::assertStringContainsString('data-rosanna-form', $response->body);
        self::assertStringContainsString('\\"value\\":2', html_entity_decode($response->body, ENT_QUOTES));
    }

    public function testRow3PartialFcWithoutTheWrapperInTheReplayRedirects(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)()));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => H::p(children: 'other page'));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertSame('/page', $response->headers['Location']);
    }

    public function testRow4PartialSnapshotPersistentRedirectsWithTheSnapshotInTheUrl(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $fields = self::press(self::rendered($app, SnapshotCounter::class));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true, uri: '/page?a=1'), behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        $location = $response->headers['Location'];
        self::assertStringStartsWith('/page?a=1&_rosanna_snapshot=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        self::assertSame([1], $serializer->deserialize((string) $query['_rosanna_snapshot'])->state);
    }

    public function testRow5PlainSnapshotIsolatedAnswersTheFullPage(): void
    {
        $app = self::app();
        $counter = self::counter(StorageType::Snapshot);
        $page = static fn(): Element => H::section(children: [H::h1(children: 'title'), $counter()]);
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();
        $seen = [];

        $response = $app->actions()->handle(self::post($fields), replay: static function (bool $partial) use ($page, &$seen): Element {
            $seen[] = $partial;

            return $page();
        });

        self::assertNotNull($response);
        self::assertSame([false], $seen);
        self::assertSame(200, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('title', $response->body);
        self::assertStringContainsString('count=1', $response->body);
        self::assertStringContainsString('data-rosanna="', $response->body);
    }

    public function testRow5PlainMemoryWithACarriedSnapshotAnswersTheFullPage(): void
    {
        $app = self::app();
        $carried = self::memorySnapshotField($app, 'other');
        $page = static fn(): Element => self::counter(StorageType::Memory)();
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();
        $fields['_rosanna_snapshots'] = [$carried];

        $response = $app->actions()->handle(self::post($fields), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
    }

    public function testRow6PlainMemoryRedirects(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)()));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: '/page?q=1#f'), replay: static function (): never {
            self::fail('Memory without carried snapshots does not replay on a plain POST');
        });

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertSame('/page?q=1', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    public function testRow6PlainSnapshotPersistentRedirects(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $fields = self::press(self::rendered($app, SnapshotCounter::class));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields), replay: static function (): never {
            self::fail('Persistent answers with a redirect');
        }, behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertStringContainsString('_rosanna_snapshot=', $response->headers['Location']);
    }

    /** @return iterable<string, array{bool}> */
    public static function partialOrNot(): iterable
    {
        yield 'partial' => [true];
        yield 'plain' => [false];
    }

    #[DataProvider('partialOrNot')]
    public function testRow7SnapshotIsolatedWithoutAReplayRedirectsAndWarns(bool $partial): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Snapshot)()));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: $partial));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertStringContainsString('[Rosanna] Snapshot state lost', $this->log());
    }

    // --- HD-6〜HD-9 ---

    public function testHD6AStringReplayFindsTheWrapperThroughRenderElement(): void
    {
        $app = self::app();
        $page = static fn(): string => '<html>' . $app->renderElement(H::div(children: [self::counter(StorageType::Memory)()])) . '</html>';
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): string => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        self::assertStringNotContainsString('<html>', $response->body);
    }

    public function testHD6AReplayThatPrintsDoesNotCorruptThePartial(): void
    {
        $app = self::app();
        $page = static fn(): Element => self::counter(StorageType::Memory)();
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();
        $level = ob_get_level();

        ob_start();
        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static function () use ($page): Element {
            echo '<!doctype html>printed';

            return $page();
        });
        $leaked = (string) ob_get_clean();

        self::assertNotNull($response);
        self::assertSame('', $leaked);
        self::assertSame($level, ob_get_level());
        self::assertStringNotContainsString('printed', $response->body);
        self::assertStringContainsString('count=1', $response->body);
    }

    public function testHD6AFullPageIncludesWhatTheReplayPrinted(): void
    {
        $app = self::app();
        $page = static fn(): Element => self::counter(StorageType::Snapshot)();
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();

        ob_start();
        $response = $app->actions()->handle(self::post($fields), replay: static function () use ($page): Element {
            echo '<header>printed</header>';

            return $page();
        });
        self::assertSame('', (string) ob_get_clean());

        self::assertNotNull($response);
        self::assertStringStartsWith('<header>printed</header>', $response->body);
        self::assertStringContainsString('count=1', $response->body);
    }

    public function testHD7AutomaticKeysLineUpWithTheGet(): void
    {
        $app = self::app();
        $make = static fn(): FunctionComponent => fc(static function (array $props): Element {
            [$count, $set] = useState(0);

            return H::button(onClick: static fn() => $set($count + 1), children: "n={$count}");
        }, storageType: StorageType::Memory);
        $first = $make();
        $page = static fn(): Element => H::div(children: [$first(), $first()]);
        $html = self::page($app, $page);
        $fields = self::press($html, 1);
        self::assertStringEndsWith('#1', (string) $fields['_rosanna_component']);
        RequestLifecycle::reset();

        // パスが前に進んでいても、replay は新しいパスで揃う
        RenderContext::nextInstanceId('junk');
        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('n=1', $response->body);
    }

    public function testHD8ReplayExceptionsReachTheHostAndLeaveNoBuffers(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)()));
        RequestLifecycle::reset();
        $level = ob_get_level();
        $parent = ComponentState::current();

        try {
            $app->actions()->handle(self::post($fields, partial: true), replay: static function (): never {
                ob_start();
                throw new \DomainException('host failure');
            });
            self::fail('The exception should reach the host');
        } catch (\DomainException $e) {
            self::assertSame('host failure', $e->getMessage());
        }

        self::assertSame($level, ob_get_level());
        self::assertSame($parent, ComponentState::current());
        self::assertNull(RenderContext::currentScope());
    }

    /** RT-12.9、SG-6 defer で描画されたフォームは、それを含むページへの POST で通り、部分更新はページで署名し直す */
    public function testHD9ADeferredComponentAnswersAPartialFromThePageItsFormPostsTo(): void
    {
        $app = self::app();
        $target = self::deferredCounter('slow');
        $app->registerComponent('App\\Slow', $target);
        $app->registerDeferred('slow', 'App\\Slow');
        $page = static fn(): Element => H::section(children: [H::defer('slow', ['topic' => 'a'])]);

        $endpoint = $app->handleDeferred(self::get('/_defer/slow?topic=a'));
        self::assertNotNull($endpoint);
        self::assertSame(200, $endpoint->status, $endpoint->body);
        $fields = self::press($endpoint->body);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('topic=a count=1', $response->body);
        self::assertStringNotContainsString('data-rosanna-defer-url', $response->body);

        // 出したフォームはページの署名なので、defer を消しても通る
        $next = new Rosanna()->setSnapshotSecret(self::SECRET);
        RequestLifecycle::reset();
        $again = $next->actions()->handle(self::post(self::press($response->body)));
        self::assertNotNull($again);
        self::assertSame(303, $again->status);
    }

    private static function deferredCounter(string $name): FunctionComponent
    {
        return fc(static function (array $props): Element {
            [$count, $set] = useState(0);
            $topic = $props['topic'] ?? 'none';

            return H::div(children: [H::span(children: "topic={$topic} count={$count}"), H::button(onClick: static fn() => $set($count + 1), children: 'inc')]);
        }, key: $name, storageType: StorageType::Memory, defer: new Defer($name));
    }

    public function testHD9AnActionInsideANestedDeferFindsItThroughTheOuterPlaceholder(): void
    {
        $app = self::app();
        $renders = 0;
        $inner = self::deferredCounter('inner');
        $outer = fc(static function () use (&$renders): Element {
            $renders++;

            return H::div(children: [H::defer('inner')]);
        }, key: 'outer', storageType: StorageType::Memory, defer: new Defer('outer'));
        $app->registerComponent('App\\Inner', $inner)->registerComponent('App\\Outer', $outer);
        $app->registerDeferred('inner', 'App\\Inner')->registerDeferred('outer', 'App\\Outer');

        $endpoint = $app->handleDeferred(self::get('/_defer/inner'));
        self::assertNotNull($endpoint);
        $fields = self::press($endpoint->body);
        RequestLifecycle::reset();
        $renders = 0;

        // 同じ名前とパラメーターの対象は 1 回だけ描画する
        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => H::div(children: [H::defer('outer'), H::defer('outer')]));

        self::assertNotNull($response);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('count=1', $response->body);
        self::assertSame(1, $renders);
    }

    public function testHD9AFailingTargetIsSkippedAndLogged(): void
    {
        $app = self::app();
        $app->registerComponent('App\\Broken', static function (): never {
            throw new \RuntimeException('secret detail');
        });
        $app->registerDeferred('broken', 'App\\Broken');
        $page = static fn(): Element => H::div(children: [H::defer('broken'), self::counter(StorageType::Memory)()]);
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => H::div(children: [H::defer('broken')]));

        self::assertNotNull($response);
        // 見つからなければ redirect に落ちる
        self::assertSame(303, $response->status);
        self::assertStringContainsString("defer render failed for name 'broken' during a replay", $this->log());
    }

    /** HD-9、DF-6 replay の中でも、ネストした defer はプレースホルダーのまま */
    public function testHD9AReplayKeepsANestedDeferAsAPlaceholder(): void
    {
        $app = self::app();
        $nested = self::deferredCounter('nested');
        $host = fc(static function (): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::button(onClick: static fn() => $set($count + 1), children: "host={$count}"), H::defer('nested')]);
        }, key: 'host', storageType: StorageType::Memory, defer: new Defer('host'));
        $app->registerComponent('App\\Nested', $nested)->registerComponent('App\\Host', $host);
        $app->registerDeferred('nested', 'App\\Nested')->registerDeferred('host', 'App\\Host');

        $endpoint = $app->handleDeferred(self::get('/_defer/host'));
        self::assertNotNull($endpoint);
        $fields = self::press($endpoint->body);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => H::defer('host'));

        self::assertNotNull($response);
        self::assertStringContainsString('host=1', $response->body);
        self::assertStringContainsString('data-rosanna-defer-url="/_defer/nested"', $response->body);
        self::assertStringNotContainsString('topic=', $response->body);
    }

    public function testHD11ARegisteredClassGetsTheDeferQueryBackAndGetIsRestored(): void
    {
        $app = self::app()->register(DeferredPanel::class);
        $endpoint = $app->handleDeferred(self::get('/_defer/panel?topic=news'));
        self::assertNotNull($endpoint);
        self::assertStringContainsString('topic=news count=0', $endpoint->body);
        $fields = self::press($endpoint->body);
        self::assertSame('topic=news', $fields['_rosanna_defer_query']);
        RequestLifecycle::reset();
        $outerGet = $_GET;

        $response = $app->actions()->handle(self::post($fields, partial: true));

        self::assertNotNull($response);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('topic=news count=1', $response->body);
        // 新しいフォームが同じ値を出し直す
        self::assertSame('topic=news', self::press($response->body)['_rosanna_defer_query']);
        self::assertSame($outerGet, $_GET);
    }

    // --- SN-18、SN-19 ---

    /** @return array{Rosanna, \Closure(): Element, string} */
    private static function nestedSnapshotPage(): array
    {
        $app = self::app();
        $child = self::counter(StorageType::Snapshot, 'child');
        $sibling = self::counter(StorageType::Snapshot, 'sibling');
        $parent = fc(static function () use ($child): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::button(onClick: static fn() => $set($count + 1), children: "parent={$count}"), $child()]);
        }, key: 'parent', storageType: StorageType::Snapshot);
        $page = static fn(): Element => H::div(children: [$parent(), $sibling()]);

        return [$app, $page, self::page($app, $page)];
    }

    /** @param list<string> $carriedKeys */
    private static function partialOnParent(string $html, array $carriedKeys): array
    {
        $fields = self::press($html, 0);
        $carried = [];
        foreach (self::dom($html)->querySelectorAll('[data-rosanna-snapshot]') as $wrapper) {
            $id = (string) $wrapper->getAttribute('data-rosanna');
            foreach ($carriedKeys as $key) {
                if (str_ends_with($id, '#' . $key)) {
                    $carried[] = (string) $wrapper->getAttribute('data-rosanna-snapshot');
                }
            }
        }
        $fields['_rosanna_snapshots'] = $carried;

        return $fields;
    }

    public function testSN18ADescendantSnapshotIsAcceptedOnAPartial(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $fields = self::partialOnParent($html, ['child']);
        self::assertStringEndsWith('#parent', (string) $fields['_rosanna_component']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status, $response->body);
        self::assertStringContainsString('parent=1', $response->body);
    }

    public function testSN18ASnapshotOfAnInstanceOutsideTheTargetIsRejectedOnAPartial(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $fields = self::partialOnParent($html, ['sibling']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid snapshot', $response->body);
        self::assertStringContainsString('not its descendant', $this->log());
    }

    public function testSN18ASnapshotOfAnAncestorIsRejectedOnAPartial(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $fields = self::press($html, 1);
        self::assertStringEndsWith('#child', (string) $fields['_rosanna_component']);
        $fields['_rosanna_snapshots'] = [self::wrapperAttribute($html, 'data-rosanna-snapshot', self::idWithKey($html, 'parent'))];
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
    }

    private static function idWithKey(string $html, string $key): string
    {
        foreach (self::dom($html)->querySelectorAll('[data-rosanna]') as $wrapper) {
            $id = (string) $wrapper->getAttribute('data-rosanna');
            if (str_ends_with($id, '#' . $key)) {
                return $id;
            }
        }
        self::fail("No instance with key {$key}");
    }

    public function testSN18AnIdThatIsNotInTheTreeIsFine(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        $fields = self::press($html, 0);
        $fields['_rosanna_snapshots'] = [$serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot('Hidden', 'row', [1]))];
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
    }

    public function testSN19AFullPageCarriesThePageSnapshotsInEveryForm(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $fields = self::press($html, 0);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        $forms = self::dom($response->body)->querySelectorAll('form[data-rosanna-form]');
        self::assertGreaterThan(1, $forms->length);
        foreach ($forms as $form) {
            $carried = $form->querySelectorAll('input[name="_rosanna_snapshots[]"]');
            self::assertSame(3, $carried->length);
            $serializer = $app->snapshotSerializer();
            self::assertNotNull($serializer);
            foreach ($carried as $input) {
                $serializer->deserialize((string) $input->getAttribute('value'));
            }
        }

        // JavaScript なしで次の POST をしても他のインスタンスの状態が残る
        RequestLifecycle::reset();
        $next = $app->actions()->handle(self::post(self::press($response->body, 1)), replay: static fn(): Element => $page());
        self::assertNotNull($next);
        self::assertSame(200, $next->status);
        self::assertStringContainsString('parent=1', $next->body);
    }

    public function testSN19ASingleWrapperAddsNothing(): void
    {
        $app = self::app();
        $page = static fn(): Element => self::counter(StorageType::Snapshot)();
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields), replay: static fn(): Element => $page());

        self::assertNotNull($response);
        self::assertStringNotContainsString('_rosanna_snapshots', $response->body);
    }

    public function testSN19TextThatLooksLikeRendererOutputIsNotCarried(): void
    {
        [$app, $page, $html] = self::nestedSnapshotPage();
        $fields = self::press($html, 0);
        RequestLifecycle::reset();
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        $planted = htmlspecialchars($serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot('Planted', '0', ['x'])), ENT_QUOTES);
        $unverified = htmlspecialchars('{"memo":{"name":"Bad","key":"0","version":0},"state":[],"effectDeps":[],"checksum":"bad"}', ENT_QUOTES);
        $extras = '<script>var a = \'<div data-rosanna="Planted#0" data-rosanna-snapshot="' . $planted . '"></div><form data-rosanna-form>\';</script>'
            . '<style>/* <div data-rosanna="P#1" data-rosanna-snapshot="' . $planted . '"> */</style>'
            . '<!-- <div data-rosanna="P#2" data-rosanna-snapshot="' . $planted . '"> -->'
            . '<textarea><div data-rosanna-snapshot="' . $planted . '"></textarea>'
            . '<div title=\' data-rosanna-snapshot="' . $planted . '"\'></div>'
            . '<div data-rosanna="Bad#0" data-rosanna-snapshot="' . $unverified . '"></div>';

        $response = $app->actions()->handle(self::post($fields), replay: static fn(): string => $extras . $app->renderElement($page()));

        self::assertNotNull($response);
        self::assertStringNotContainsString('name="_rosanna_snapshots[]" value="' . $planted, $response->body);
        foreach (self::dom($response->body)->querySelectorAll('input[name="_rosanna_snapshots[]"]') as $input) {
            $serializer->deserialize((string) $input->getAttribute('value'));
            self::assertStringNotContainsString('Planted', (string) $input->getAttribute('value'));
        }
        // script の中の文字列の form の後にも足さない
        self::assertStringContainsString("<form data-rosanna-form>';</script>", $response->body);
    }

    // --- AC ---

    public function testAC7OneInvalidFieldWritesNothing(): void
    {
        $app = self::app();
        $page = static fn(): Element => self::profileForm()();
        $html = self::page($app, $page);
        RequestLifecycle::reset();

        foreach ([['title' => 'ok', 'body' => ['array']], ['title' => 'ok', 'body' => str_repeat('x', 65_536)], ['title' => 'ok', 'body' => "\xFF"]] as $values) {
            $response = $app->actions()->handle(self::post(self::submit($html, $values)));

            self::assertNotNull($response);
            self::assertSame(400, $response->status);
            self::assertSame('Invalid form data', $response->body);
            $state = ComponentState::getInstance(self::idOf($html), StorageType::Memory);
            self::assertFalse($state->hasState(0));
            self::assertFalse($state->hasState(1));
            self::assertSame(0, $state->version());
            RequestLifecycle::reset();
        }
    }

    private static function profileForm(): FunctionComponent
    {
        return fc(static function (): Element {
            [$title, $setTitle] = useState('t');
            [$body, $setBody] = useState('b');

            return H::form(onSubmit: useFormAction(['title' => $setTitle, 'body' => $setBody]), children: [
                H::input(name: 'title', value: $title),
                H::input(name: 'body', value: $body),
                H::p(children: "title={$title} body={$body}"),
            ]);
        }, key: 'profile', storageType: StorageType::Memory);
    }

    public function testAC7MissingFieldsBecomeEmptyStringsAndTheVersionGoesUpOnce(): void
    {
        $app = self::app();
        $html = self::page($app, static fn() => self::profileForm()());
        $fields = self::submit($html, ['title' => 'new']);
        unset($fields['body']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        $state = ComponentState::getInstance(self::idOf($html));
        self::assertSame('new', $state->getState(0, null));
        self::assertSame('', $state->getState(1, null));
        self::assertSame(1, $state->version());
    }

    public function testAC8RestoreRequiresASnapshot(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class);
        $fields = self::restoreFields('SnapshotCounter#0', self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0'), '');
        unset($fields['_rosanna_snapshot']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action request', $response->body);
    }

    public function testAC8RestoreForAnotherInstanceIsRejected(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class);
        $fields = self::restoreFields('SnapshotCounter#0', self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0'), self::wrapperAttribute($html, 'data-rosanna-snapshot', 'SnapshotCounter#0'));
        $fields['_rosanna_component'] = 'SnapshotCounter#1';
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true));

        self::assertNotNull($response);
        self::assertSame('Invalid action request', $response->body);
    }

    public function testAC8RestoreWithoutItsSignatureIsRejected(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class);
        $snapshot = self::wrapperAttribute($html, 'data-rosanna-snapshot', 'SnapshotCounter#0');
        RequestLifecycle::reset();

        foreach ([null, 'bad', str_repeat('0', 64)] as $signature) {
            $response = $app->actions()->handle(self::post(self::restoreFields('SnapshotCounter#0', $signature, $snapshot), partial: true));

            self::assertNotNull($response);
            self::assertSame(400, $response->status);
            self::assertSame('Invalid action signature', $response->body);
        }
    }

    public function testAC8RestoreOfAGuardedComponentIsRejectedOnAnOpenPage(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class, '/guarded');
        $fields = self::restoreFields('SnapshotCounter#0', self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0'), self::wrapperAttribute($html, 'data-rosanna-snapshot', 'SnapshotCounter#0'));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true, uri: '/open'));
        self::assertNotNull($response);
        self::assertSame('Invalid action signature', $response->body);

        RequestLifecycle::reset();
        $response = $app->actions()->handle(self::post($fields, partial: true, uri: '/guarded'));
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
    }

    /** AC-9 restore の署名と action の署名は入れ替えられない */
    public function testAC9RestoreSignatureDoesNotAuthorizeAnAction(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class);
        $sig = self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0');
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post([
            '_rosanna_component' => 'SnapshotCounter#0',
            '_rosanna_action' => new ActionEnvelope('{"type":"setState","payload":{"index":0,"value":99},"storageType":"snapshot"}', $sig)->toJson(),
        ]));

        self::assertNotNull($response);
        self::assertSame('Invalid action signature', $response->body);
    }

    public function testAC11SignedStorageTypeWinsOverTheRegisteredClass(): void
    {
        $app = self::app()->register(MemoryCounter::class);
        $json = '{"type":"setState","payload":{"index":0,"value":3},"storageType":"memory"}';

        $app->actions()->handle(self::post(self::signed($app, 'MemoryCounter#9', $json)));

        self::assertSame(StorageType::Memory, ComponentState::getInstance('MemoryCounter#9')->getStorageType());
    }

    public function testAC11NullStorageTypeUsesTheRegisteredClass(): void
    {
        $app = self::app()->register(MemoryCounter::class);
        $json = '{"type":"setState","payload":{"index":0,"value":3}}';

        $app->actions()->handle(self::post(self::signed($app, 'MemoryCounter#9', $json)));

        self::assertSame(StorageType::Memory, ComponentState::getInstance('MemoryCounter#9')->getStorageType());
        self::assertSame(3, ComponentState::getInstance('MemoryCounter#9')->getState(0, null));
    }

    /** @return list<array{id: int, on: bool, label: string}> */
    private static function rows(bool $firstOn = false): array
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['id' => $i, 'on' => $i === 1 && $firstOn, 'label' => 'row number ' . $i];
        }

        return $rows;
    }

    private static function listComponent(StorageType $storage): FunctionComponent
    {
        return fc(static function (): Element {
            [$rows, $set] = useState(self::rows());
            $buttons = [];
            foreach ($rows as $i => $row) {
                $buttons[] = H::button(onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    $next[$i]['on'] = !$next[$i]['on'];

                    return $set($next);
                }, children: $row['id'] . ':' . ($row['on'] ? 'on' : 'off'));
            }

            return H::div(children: $buttons);
        }, key: 'list', storageType: $storage);
    }

    private static function diffFields(string $html): array
    {
        $fields = self::press($html);
        $envelope = json_decode((string) $fields['_rosanna_action'], true);
        self::assertIsArray($envelope);
        self::assertStringContainsString('"patch"', (string) $envelope['action'], 'The action should carry a diff');

        return $fields;
    }

    public function testAC16DiffAppliesOnMemoryFromTheBaseField(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        self::assertArrayHasKey('_rosanna_base', $fields);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('1:on', $response->body);
        self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers);

        // 2 回目は断片のフォームから送る
        $second = self::press($response->body, 1);
        self::assertArrayHasKey('_rosanna_base', $second);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($second, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers);
        self::assertStringContainsString('1:on', $response->body);
        self::assertStringContainsString('2:on', $response->body);
    }

    public function testAC17TamperedBaseOnMemoryIsNotAppliedAndIsNotAnError(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        $fields['_rosanna_base'] = [0 => (string) json_encode(self::rows(true))];
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('1', $response->headers['X-Rosanna-Stale'] ?? null);
        self::assertStringContainsString('1:off', $response->body);
        self::assertSame(0, ComponentState::getInstance(self::idOf($html))->version());
    }

    public function testAC17BaseThatCannotBeHashedIsNotApplied(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        $fields['_rosanna_base'] = [0 => '1e999'];
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('1', $response->headers['X-Rosanna-Stale'] ?? null);
    }

    public function testAC17MissingBaseOnMemoryIsNotApplied(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        unset($fields['_rosanna_base']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame('1', $response->headers['X-Rosanna-Stale'] ?? null);
    }

    public function testAC17DiffOnASnapshotThatDoesNotMatchIsRedrawn(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Snapshot);
        $html = self::page($app, static fn() => $component());
        $id = self::idOf($html);
        $fields = self::diffFields($html);
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        // 別のタブが同じ版から分岐した状態
        [$name, $key] = explode('#', $id, 2);
        $fields['_rosanna_snapshot'] = $serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot($name, $key, [self::rows(true)], version: 1));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('1', $response->headers['X-Rosanna-Stale'] ?? null);
        self::assertStringContainsString('1:on', $response->body);
        self::assertSame(1, ComponentState::getInstance($id)->version());
    }

    public function testAC17StaleDiffOnAPlainSnapshotPostRedrawsTheFullPageWithoutApplying(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Snapshot);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        unset($fields['_rosanna_snapshot']);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('1:off', $response->body);
    }

    public function testAC17SnapshotDiffWithItsOwnSnapshotApplies(): void
    {
        $app = self::app();
        $component = self::listComponent(StorageType::Snapshot);
        $html = self::page($app, static fn() => $component());
        $fields = self::diffFields($html);
        self::assertArrayNotHasKey('_rosanna_base', $fields);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers);
        self::assertStringContainsString('1:on', $response->body);
    }

    public function testStep8AnInvalidPatchIsABadRequest(): void
    {
        $app = self::app();
        $base = [1, 2];
        $json = (string) json_encode(['type' => 'setState', 'payload' => ['index' => 0, 'patch' => ['nope'], 'base' => StateDiff::hash($base)], 'storageType' => 'memory']);
        $fields = self::signed($app, 'A#0', $json);
        $fields['_rosanna_base'] = [0 => '[1,2]'];

        $response = $app->actions()->handle(self::post($fields));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('Invalid action data', $response->body);
        self::assertSame(0, ComponentState::getInstance('A#0')->version());
    }

    public function testVR8MemoryAppliesTheSameFormTwice(): void
    {
        $app = self::app();
        $component = self::counter(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::press($html);
        $handler = $app->actions();

        // リクエストごとに状態は消える。同じフォームを何度送っても同じ結果になり、古いと扱わない
        foreach ([1, 2] as $attempt) {
            RequestLifecycle::reset();
            $response = $handler->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());
            self::assertNotNull($response);
            self::assertSame(200, $response->status);
            self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers, "attempt {$attempt}");
            self::assertStringContainsString('count=1', $response->body, "attempt {$attempt}");
            self::assertSame(1, ComponentState::getInstance(self::idOf($html))->version());
        }
    }

    public function testVR5TheServerDoesNotCompareSnapshotVersions(): void
    {
        $app = self::app();
        $component = self::counter(StorageType::Snapshot);
        $html = self::page($app, static fn() => $component());
        $fields = self::press($html);
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        [$name, $key] = explode('#', self::idOf($html), 2);
        $fields['_rosanna_snapshot'] = $serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot($name, $key, [10], version: 5));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        self::assertNotNull($response);
        self::assertArrayNotHasKey('X-Rosanna-Stale', $response->headers);
        $update = self::dom($response->body)->querySelector('input[data-rosanna-snapshot-update]');
        self::assertNotNull($update);
        self::assertSame(6, json_decode((string) $update->getAttribute('value'), true)['memo']['version']);
    }

    // --- HD-12、HD-13、HD-14、RQ ---

    public function testSN26ReplacingTheOnlyCarriedInstanceWarns(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $serializer = $app->snapshotSerializer();
        self::assertNotNull($serializer);
        $fields = self::press(self::rendered($app, SnapshotCounter::class));
        RequestLifecycle::reset();
        $otherUrl = rawurlencode($serializer->serialize(new \Polidog\Rosanna\Runtime\Snapshot('Other', '0', [1])));

        $response = $app->actions()->handle(self::post($fields, uri: '/page?_rosanna_snapshot=' . $otherUrl), behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertSame(1, substr_count($response->headers['Location'], '_rosanna_snapshot='));
        self::assertStringContainsString('Snapshot replaced (behavior=Persistent)', $this->log());
    }

    public function testHD12ABrokenCarriedSnapshotOnlySkipsTheWarning(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $fields = self::press(self::rendered($app, SnapshotCounter::class));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: '/page?_rosanna_snapshot=broken'), behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertStringNotContainsString('Snapshot replaced', $this->log());
    }

    /** @return iterable<string, array{string, string}> */
    public static function redirectTargets(): iterable
    {
        yield 'plain' => ['/page', '/page'];
        yield 'query' => ['/page?a=1&b=2', '/page?a=1&b=2'];
        yield 'fragment' => ['/page?a=1#f', '/page?a=1'];
        yield 'protocol relative' => ['//evil.example/page', '/evil.example/page'];
        yield 'many slashes' => ['////evil.example', '/evil.example'];
        yield 'backslash' => ['/\\evil.example', '/evil.example'];
        yield 'backslash start' => ['\\\\evil.example/x', '/evil.example/x'];
        yield 'inner backslash' => ['/a\\b', '/a/b'];
        yield 'control' => ["/a\r\nSet-Cookie: x=1", '/'];
        yield 'tab' => ["/\t/evil.example", '/'];
        yield 'nul' => ["/a\0b", '/'];
        yield 'del' => ["/a\x7Fb", '/'];
        yield 'absolute form' => ['http://evil.example/page?a=1', '/page?a=1'];
        yield 'absolute without a path' => ['http://evil.example', '/'];
        yield 'javascript' => ['javascript:alert(1)', '/alert(1)'];
        yield 'empty' => ['', '/'];
        yield 'port like path' => ['/:80', '/:80'];
        yield 'snapshot removed' => ['/page?a=1&_rosanna_snapshot=x&b=2', '/page?a=1&b=2'];
        yield 'encoded snapshot removed' => ['/page?%5Frosanna_snapshot=x', '/page'];
        yield 'snapshot array removed' => ['/page?_rosanna_snapshot[]=x&c=3', '/page?c=3'];
        yield 'query bytes kept' => ['/page?a=%7E+b&c', '/page?a=%7E+b&c'];
    }

    #[DataProvider('redirectTargets')]
    public function testHD13RedirectTargetStaysOnTheOrigin(string $uri, string $expected): void
    {
        self::assertSame($expected, RedirectTarget::fromUri($uri));
    }

    public function testHD13TheResponseUsesTheNormalizedTarget(): void
    {
        $app = self::app();
        $fields = self::press(self::page($app, static fn() => self::counter(StorageType::Memory)()));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(new \Polidog\Rosanna\Http\Request(
            'POST',
            '//evil.example/page',
            '/page',
            '',
            [],
            $fields,
            [],
            ['host' => 'app.test', 'origin' => 'http://app.test'],
        ));

        self::assertNotNull($response);
        self::assertSame('/evil.example/page', $response->headers['Location']);
    }

    /** @return iterable<string, array{string}> */
    public static function unparsablePaths(): iterable
    {
        yield 'port' => ['/:80'];
        yield 'large port' => ['/x:65536'];
        yield 'colon in a segment' => ['/item:42'];
        yield 'colon in a nested segment' => ['/snap/persistent/a:80'];
    }

    /** HD-13、HD-14 parse_url() が読めないパスでも、Persistent の redirect は同じパスに戻る */
    #[DataProvider('unparsablePaths')]
    public function testHD14PersistentRedirectKeepsAPathParseUrlCannotRead(string $path): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $html = self::rendered($app, SnapshotCounter::class, $path);
        $fields = self::press($html);
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: $path), behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        self::assertStringStartsWith($path . '?_rosanna_snapshot=', $response->headers['Location']);
    }

    public function testHD14PersistentRedirectKeepsTheQueryOfAPathParseUrlCannotRead(): void
    {
        $app = self::app()->register(SnapshotCounter::class);
        $fields = self::press(self::rendered($app, SnapshotCounter::class, '/item:42'));
        RequestLifecycle::reset();

        $response = $app->actions()->handle(self::post($fields, uri: '/item:42?keep=1'), behavior: SnapshotBehavior::Persistent);

        self::assertNotNull($response);
        self::assertStringStartsWith('/item:42?keep=1&_rosanna_snapshot=', $response->headers['Location']);
    }

    public function testHD15ThereIsNoPublicRedirectMethod(): void
    {
        self::assertFalse(method_exists(Rosanna::class, 'redirect'));
        self::assertFalse(new \ReflectionClass(\Polidog\Rosanna\Protocol\ActionHandler::class)->hasMethod('redirectTo'));
    }

    public function testHD4OneRequestProcessesOneAction(): void
    {
        $app = self::app();
        $component = self::counter(StorageType::Memory);
        $html = self::page($app, static fn() => $component());
        $fields = self::press($html);
        $id = self::idOf($html);

        // 配列にしても 2 つの action にならず、Rosanna の action として扱わない
        RequestLifecycle::reset();
        $listed = $fields;
        $listed['_rosanna_action'] = [$fields['_rosanna_action'], $fields['_rosanna_action']];
        self::assertNull($app->actions()->handle(self::post($listed)));
        self::assertSame(0, ComponentState::getInstance($id)->version());

        // PHP は同じ名前の最後の値だけを読む。本文に 2 つ書いても適用は 1 回だけ
        RequestLifecycle::reset();
        parse_str(http_build_query($fields) . '&_rosanna_action=' . rawurlencode((string) $fields['_rosanna_action']), $repeated);
        /** @var array<string, mixed> $repeated */
        $response = $app->actions()->handle(self::post($repeated, partial: true), replay: static fn(): Element => $component());
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString('count=1', $response->body);
        self::assertSame(1, ComponentState::getInstance($id)->version());
    }

    public function testRQ2NoHeadersOrOutputAroundHandle(): void
    {
        $app = self::app();
        $page = static fn(): Element => self::counter(StorageType::Snapshot)();
        $fields = self::press(self::page($app, $page));
        RequestLifecycle::reset();
        $before = headers_list();
        $code = http_response_code();

        ob_start();
        $responses = [
            $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $page()),
            $app->actions()->handle(self::post($fields), replay: static fn(): Element => $page()),
            $app->actions()->handle(self::post($fields, headers: ['origin' => 'http://evil.test'])),
            $app->actions()->handle(self::post($fields, uri: '/other')),
        ];
        $printed = (string) ob_get_clean();

        self::assertSame('', $printed);
        self::assertSame($before, headers_list());
        self::assertSame($code, http_response_code());
        self::assertSame([200, 200, 403, 400], array_map(static fn($r) => $r?->status, $responses));
    }

    public function testRQ3TheSameInstanceDoesNotReturnThePreviousPage(): void
    {
        $app = self::app();
        $first = self::counter(StorageType::Snapshot, 'first');
        $second = self::counter(StorageType::Snapshot, 'second');
        $firstFields = self::press(self::page($app, static fn() => H::section(children: ['page-one', $first()]), '/one'));
        RequestLifecycle::reset();
        $secondFields = self::press(self::page($app, static fn() => H::section(children: ['page-two', $second()]), '/two'));
        RequestLifecycle::reset();
        $handler = $app->actions();

        $one = $handler->handle(self::post($firstFields, uri: '/one'), replay: static fn(): Element => H::section(children: ['page-one', $first()]));
        RequestLifecycle::reset();
        $two = $handler->handle(self::post($secondFields, uri: '/two'), replay: static fn(): Element => H::section(children: ['page-two', $second()]));
        RequestLifecycle::reset();
        $three = $handler->handle(self::post($secondFields, uri: '/two'));

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotNull($three);
        self::assertStringContainsString('page-one', $one->body);
        self::assertStringContainsString('page-two', $two->body);
        self::assertStringNotContainsString('page-one', $two->body);
        // 前の呼び出しの replay は残らない
        self::assertSame(303, $three->status);
        self::assertSame('/two', $three->headers['Location']);
    }

    public function testRQ3HandlerDoesNotLeaveTheReplayCollectionOnTheApp(): void
    {
        $app = self::app();
        $component = self::counter(StorageType::Memory);
        $fields = self::press(self::page($app, static fn() => $component()));
        RequestLifecycle::reset();

        $app->actions()->handle(self::post($fields, partial: true), replay: static fn(): Element => $component());

        $collected = new \ReflectionProperty(Rosanna::class, 'collected');
        self::assertNull($collected->getValue($app));
        self::assertNull(RenderContext::currentScope());
    }
}
