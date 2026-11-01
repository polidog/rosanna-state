<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Protocol\Fixtures\DeferredPanel;
use Polidog\Rosanna\Tests\Protocol\Fixtures\MemoryCounter;
use Polidog\Rosanna\Tests\Protocol\Fixtures\SnapshotCounter;
use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/** RT-12、SN-9、SN-10、RT-9.6、RT-11.5 */
final class RosannaTest extends TestCase
{
    use ActionRequests;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->captureLog();
    }

    protected function tearDown(): void
    {
        RequestLifecycle::reset();
        $this->releaseLog();
        self::assertSame(PHP_SESSION_NONE, session_status(), 'A test in this process must not start a session');
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testRT121ClassNameAndRegisteredNameAreTheSameInstance(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);

        self::assertStringContainsString('data-rosanna="MemoryCounter#a"', $app->render(MemoryCounter::class, 'a'));
        self::assertStringContainsString('data-rosanna="MemoryCounter#a"', $app->render('MemoryCounter', 'a'));
        self::assertSame(
            $app->createElement(MemoryCounter::class, 'b')->props['data-rosanna'],
            $app->createElement('MemoryCounter', 'b')->props['data-rosanna'],
        );
    }

    public function testRT122UnregisteredIsALogicExceptionAndDoesNotRegister(): void
    {
        $app = new Rosanna();
        $pass = RenderContext::currentPass();

        foreach (['Nope', MemoryCounter::class] as $name) {
            foreach (['render', 'createElement'] as $method) {
                try {
                    $app->{$method}($name);
                    self::fail();
                } catch (\LogicException $e) {
                    self::assertStringContainsString('not registered', $e->getMessage());
                }
            }
        }

        self::assertSame($pass, RenderContext::currentPass());
        self::assertNull(RenderContext::currentScope());
        self::assertFalse($app->components()->has('MemoryCounter'));
    }

    public function testRT123RenderStartsAPassAndGivesItBack(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);

        // 新しいパスなので何度描画しても #0 になる
        self::assertStringContainsString('MemoryCounter#0', $app->render(MemoryCounter::class));
        RenderContext::beginRender();
        self::assertStringContainsString('MemoryCounter#0', $app->render(MemoryCounter::class));
        // 入れ子のパスが使った番号を外側が再利用しない (RT-8.5)
        self::assertSame(1, RenderContext::currentPass()['instanceCounts']['MemoryCounter']);
    }

    public function testRT123CreateElementContinuesThePass(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);

        RenderContext::beginRender();
        self::assertSame('MemoryCounter#0', $app->createElement(MemoryCounter::class)->props['data-rosanna']);
        self::assertSame('MemoryCounter#1', $app->createElement(MemoryCounter::class)->props['data-rosanna']);
    }

    public function testRT123RenderElementUsesTheRootId(): void
    {
        $app = new Rosanna();

        self::assertSame('<p>x</p>', $app->renderElement(H::p(children: 'x')));
    }

    public function testRT123EveryEntryRestoresTheParentStateAndScope(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);
        $parent = ComponentState::getInstance('Parent#0', StorageType::Memory);

        foreach ([static fn() => $app->render(MemoryCounter::class), static fn() => $app->createElement(MemoryCounter::class), static fn() => $app->renderElement(H::p(children: 'x'))] as $entry) {
            $entry();
            self::assertSame($parent, ComponentState::current());
            self::assertNull(RenderContext::currentScope());
        }
    }

    public function testRT123AThrowingComponentRestoresEverything(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(SnapshotCounter::class);
        $parent = ComponentState::getInstance('Parent#0', StorageType::Memory);
        $outerScope = $app->scope(self::get());

        RenderContext::run($outerScope, static function () use ($app, $outerScope, $parent): void {
            $bad = new Element('b"ad');
            try {
                $app->renderElement($bad);
                self::fail();
            } catch (\RuntimeException) {
            }
            self::assertSame($outerScope, RenderContext::currentScope());
            self::assertSame($parent, ComponentState::current());
        });
        self::assertNull(RenderContext::currentScope());
    }

    /** RT-12.3 コンポーネントの中の入れ子の render の後で、親の hooks、スコープ、パスが戻る */
    public function testRT123NestedRenderInsideAComponent(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);
        $scope = $app->scope(self::get());
        $seen = [];
        $parent = fc(static function () use ($app, $scope, &$seen): Element {
            [$first] = useState('a');
            $before = ComponentState::current();
            $html = $app->render(MemoryCounter::class);
            [$second] = useState('b');
            $seen = [$before === ComponentState::current(), $scope === RenderContext::currentScope(), RenderContext::stackDepth(), $first . $second];

            return H::div(children: $html);
        }, key: 'parent', storageType: StorageType::Memory);

        RenderContext::run($scope, static fn(): Element => $parent());

        self::assertSame([true, true, 1, 'ab'], $seen);
    }

    public function testRT124SnapshotWithoutASecretNamesTheFix(): void
    {
        $app = new Rosanna()->register(SnapshotCounter::class);

        foreach (['render', 'createElement'] as $method) {
            try {
                $app->{$method}(SnapshotCounter::class);
                self::fail();
            } catch (\LogicException $e) {
                self::assertStringContainsString('setSnapshotSecret', $e->getMessage());
            }
            self::assertNull(RenderContext::currentScope());
        }
    }

    public function testRT124WrapperAttributesMatchOnEveryEntry(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(SnapshotCounter::class);
        $scope = $app->scope(self::get());

        [$html, $fromElement] = RenderContext::run($scope, static function () use ($app): array {
            $element = $app->createElement(SnapshotCounter::class, 'k');

            return [$app->render(SnapshotCounter::class, 'k'), $app->renderElement($element)];
        });

        foreach (['data-rosanna-snapshot', 'data-rosanna-restore-sig', 'data-rosanna-persist'] as $attribute) {
            self::assertNotSame('', self::wrapperAttribute($html, $attribute, 'SnapshotCounter#k'));
            self::assertSame(self::wrapperAttribute($html, $attribute, 'SnapshotCounter#k'), self::wrapperAttribute($fromElement, $attribute, 'SnapshotCounter#k'));
        }
    }

    public function testRT125RegisterAddsTheEndpointAndThePsxPlaceholder(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(DeferredPanel::class);

        $response = $app->handleDeferred(self::get('/_defer/panel'));
        self::assertNotNull($response);
        self::assertSame(200, $response->status);

        $html = RenderContext::run($app->scope(self::get()), static fn(): string => $app->renderElement(
            RenderContext::scope()->psx->render(DeferredPanel::class, ['topic' => 'x']),
        ));
        self::assertStringContainsString('data-rosanna-defer-url="/_defer/panel?topic=x"', $html);
    }

    /** RT-12.8 host が自分の Request とレイアウトで描画する */
    public function testRT128HostOwnedScope(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s');
        $counter = fc(static fn(): Element => H::p(children: 'x'), key: 'k', storageType: StorageType::Snapshot);
        $scope = $app->scope(self::get('/rewritten'));

        $html = RenderContext::run($scope, static fn(): string => $scope->renderers->create('_root_')->renderElement($counter()));

        self::assertStringContainsString('data-rosanna-restore-sig', $html);
        self::assertSame('page:/rewritten', $scope->signingScope);
    }

    /** RT-12.9 外側のスコープの署名の scope を使う。別のアプリの中でも鍵は自分のもの */
    public function testRT129OuterScopeDecidesTheSigningScope(): void
    {
        $inner = new Rosanna()->setSnapshotSecret('inner-key')->register(SnapshotCounter::class);
        $outer = new Rosanna()->setSnapshotSecret('outer-key');
        $outerScope = $outer->scope(self::get('/outer-page'));

        $html = RenderContext::run($outerScope, static function () use ($inner, $outerScope): string {
            $html = $inner->render(SnapshotCounter::class);
            self::assertSame($outerScope, RenderContext::currentScope());

            return $html;
        });

        $signature = self::wrapperAttribute($html, 'data-rosanna-restore-sig', 'SnapshotCounter#0');
        self::assertTrue($inner->signer()->verify('SnapshotCounter#0', 'restore', $signature, '/outer-page'));
        self::assertFalse($inner->signer()->verify('SnapshotCounter#0', 'restore', $signature, '/other'));
        self::assertFalse($outer->signer()->verify('SnapshotCounter#0', 'restore', $signature, '/outer-page'));
    }

    public function testRT129WithoutAScopeTheGlobalsDecide(): void
    {
        $outer = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/from-globals?x=1';
        try {
            $app = new Rosanna()->setSnapshotSecret('k')->register(SnapshotCounter::class);
            $signature = self::wrapperAttribute($app->render(SnapshotCounter::class), 'data-rosanna-restore-sig', 'SnapshotCounter#0');

            self::assertTrue($app->signer()->verify('SnapshotCounter#0', 'restore', $signature, '/from-globals'));
        } finally {
            if ($outer === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $outer;
            }
        }
    }

    public function testSN9SerializerIsNullWithoutASecret(): void
    {
        self::assertNull(new Rosanna()->snapshotSerializer());
        self::assertNotNull(new Rosanna()->setSnapshotSecret('s')->snapshotSerializer());
    }

    public function testSN4EmptySecretIsRefusedAndLeavesTheAppAlone(): void
    {
        $app = new Rosanna();
        try {
            $app->setSnapshotSecret('');
            self::fail();
        } catch (\InvalidArgumentException) {
        }
        self::assertNull($app->snapshotSerializer());
    }

    public function testSignerAndScopeFollowLaterConfiguration(): void
    {
        $app = new Rosanna();
        $app->setSnapshotSecret('later');
        $signature = $app->signer()->sign('A#0', '{}', '/p');
        self::assertNotNull($signature);
        self::assertTrue($app->signer()->verify('A#0', '{}', $signature, '/p'));

        $app->registerDeferred('123', 'App\\Numeric');
        self::assertTrue($app->signer()->verify('A#0', '{}', $signature, '/p'));
    }

    public function testCsrfConfiguration(): void
    {
        $app = new Rosanna();
        self::assertNotNull($app->csrf()->verify(self::post([], headers: ['origin' => 'http://evil.test'])));

        $app->trustProxyHeaders();
        self::assertNull($app->csrf()->verify(self::post([], headers: ['origin' => 'https://public.test', 'x-forwarded-host' => 'public.test', 'x-forwarded-proto' => 'https'])));

        $app->disableCsrfProtection();
        self::assertNull($app->csrf()->verify(self::post([], headers: ['origin' => 'http://evil.test'])));
        self::assertSame('', $app->csrf()->token());
    }

    private function manifestDir(): string
    {
        $this->dir = sys_get_temp_dir() . '/rosanna-manifest-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        return $this->dir;
    }

    public function testRT115ManifestRegistersDeferredEntries(): void
    {
        $dir = $this->manifestDir();
        file_put_contents("{$dir}/a.php", '<?php return fn (array $props) => \\Polidog\\Rosanna\\Html\\createElement("b", ["children" => ["ok"]]);');
        file_put_contents("{$dir}/manifest.php", '<?php return ' . var_export(['App\\A' => ['file' => "{$dir}/a.php", 'parameters' => [['kind' => 'props', 'name' => 'props']]]], true) . ';');
        file_put_contents("{$dir}/deferred-manifest.php", '<?php return ' . var_export(['a' => ['component' => 'App\\A', 'cacheControl' => 'private, max-age=9']], true) . ';');

        // 同じ内容の読み込みは何もしない (DF-3)
        $app = new Rosanna()->loadComponentManifest("{$dir}/manifest.php")->loadComponentManifest("{$dir}/manifest.php");

        $response = $app->handleDeferred(self::get('/_defer/a'));
        self::assertNotNull($response);
        self::assertSame('<b>ok</b>', $response->body);
        self::assertSame('private, max-age=9', $response->headers['Cache-Control']);
    }

    public function testRT115ConflictingDeferredEntryThrows(): void
    {
        $dir = $this->manifestDir();
        file_put_contents("{$dir}/manifest.php", '<?php return [];');
        file_put_contents("{$dir}/deferred-manifest.php", '<?php return ' . var_export(['a' => ['component' => 'App\\Other', 'cacheControl' => null]], true) . ';');

        $this->expectException(\InvalidArgumentException::class);
        new Rosanna()->registerDeferred('a', 'App\\A')->loadComponentManifest("{$dir}/manifest.php");
    }

    public function testRT115MissingManifestThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        new Rosanna()->loadComponentManifest('/nonexistent/manifest.php');
    }

    public function testRT115NumericDeferredNameIsAcceptedFromTheManifest(): void
    {
        $dir = $this->manifestDir();
        file_put_contents("{$dir}/manifest.php", '<?php return [];');
        file_put_contents("{$dir}/deferred-manifest.php", '<?php return ' . var_export(['404' => ['component' => 'App\\NotFound', 'cacheControl' => null]], true) . ';');

        $app = new Rosanna()->setSnapshotSecret('s')->loadComponentManifest("{$dir}/manifest.php");

        // ActionSigner は文字列の名前の一覧を受ける
        self::assertNotNull($app->signer()->sign('A#0', '{}', '/p'));
        self::assertTrue(RenderContext::run($app->scope(self::get(), '404'), static fn(): bool => RenderContext::scope()->signingScope === 'defer:404'));
    }

    /** RT-9.6 */
    public function testRT96ResetClearsStateScopeAndPass(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(MemoryCounter::class);
        RenderContext::beginRender();
        $app->createElement(MemoryCounter::class);
        ComponentState::getInstance('MemoryCounter#0')->setState(0, 5);
        $scope = $app->scope(self::get());

        RenderContext::run($scope, static function (): void {
            RequestLifecycle::reset();
            self::assertNull(RenderContext::currentScope());
        });

        self::assertSame(['instanceCounts' => [], 'componentStack' => []], RenderContext::currentPass());
        self::assertNull(ComponentState::current());
        self::assertSame('gone', ComponentState::getInstance('MemoryCounter#0', StorageType::Memory)->getState(0, 'gone'));
    }

    public function testRQ3RenderDoesNotKeepTheRequestOnTheApp(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(SnapshotCounter::class);

        $one = RenderContext::run($app->scope(self::get('/one')), static fn(): string => $app->render(SnapshotCounter::class));
        RequestLifecycle::reset();
        $two = RenderContext::run($app->scope(self::get('/two')), static fn(): string => $app->render(SnapshotCounter::class));

        self::assertNotSame(self::wrapperAttribute($one, 'data-rosanna-restore-sig', 'SnapshotCounter#0'), self::wrapperAttribute($two, 'data-rosanna-restore-sig', 'SnapshotCounter#0'));
    }

    /** CP-2 01 の 11 節 本体からコンパイラを参照しない */
    public function testTheRuntimeDoesNotMentionTheCompiler(): void
    {
        self::assertStringNotContainsString('Psx\\', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Rosanna.php'));
    }
}
