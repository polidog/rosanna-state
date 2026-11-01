<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Protocol\Fixtures\DeferredPanel;
use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/** 02 の 8 節。DF-8 の Set-Cookie とセッションは tests/Integration/DeferEndpointFlowTest */
final class DeferredEndpointTest extends TestCase
{
    use ActionRequests;

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

    private static function echoProps(): FunctionComponent
    {
        return fc(static fn(array $props): Element => H::p(children: (string) json_encode($props)), key: 'echo', storageType: StorageType::Memory, defer: new Defer('echo'));
    }

    private static function app(): Rosanna
    {
        return new Rosanna()->setSnapshotSecret('defer-secret')
            ->registerComponent('App\\Echo', self::echoProps())
            ->registerDeferred('echo', 'App\\Echo');
    }

    public function testDF1OtherRequestsAreLeftToTheHost(): void
    {
        $app = self::app();

        self::assertNull($app->handleDeferred(self::get('/page')));
        self::assertNull($app->handleDeferred(self::get('/_deferred/echo')));
        self::assertNull($app->handleDeferred(self::post([], uri: '/_defer/echo')));
    }

    /** @return iterable<string, array{string}> */
    public static function missingEndpoints(): iterable
    {
        yield 'the prefix' => ['/_defer'];
        yield 'empty name' => ['/_defer/'];
        yield 'not registered' => ['/_defer/nope'];
        yield 'encoded slash' => ['/_defer/a%2Fb'];
        yield 'dot' => ['/_defer/a.b'];
        yield 'deeper' => ['/_defer/echo/x'];
        yield 'markup' => ['/_defer/%3Cscript%3E'];
        yield 'trailing newline' => ['/_defer/echo%0A'];
    }

    #[DataProvider('missingEndpoints')]
    public function testDF2InvalidOrUnregisteredNameIsANotFound(string $path): void
    {
        $response = self::app()->handleDeferred(self::get($path));

        self::assertNotNull($response);
        self::assertSame(404, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringNotContainsString('<script', $response->body);
    }

    public function testDF3SameNameRegisteredTwiceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::app()->registerDeferred('echo', 'App\\Echo');
    }

    public function testDF3InvalidNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rosanna()->registerDeferred('a/b', 'App\\X');
    }

    public function testDF3RegisteringTheSameClassAgainDoesNothing(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(DeferredPanel::class)->register(DeferredPanel::class);

        $response = $app->handleDeferred(self::get('/_defer/panel'));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
    }

    public function testDF4QueryBecomesPropsWithoutKeyAndFallback(): void
    {
        $response = self::app()->handleDeferred(self::get('/_defer/echo?a=1&key=stolen&fallback=x&b=two'));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertStringContainsString(htmlspecialchars('{"a":"1","b":"two"}', ENT_QUOTES), $response->body);
    }

    public function testDF4ArrayParameterIsABadRequest(): void
    {
        $response = self::app()->handleDeferred(self::get('/_defer/echo?a[]=1'));

        self::assertNotNull($response);
        self::assertSame(400, $response->status);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('"a"', html_entity_decode($response->body, ENT_QUOTES));
    }

    public function testDF4ParameterNameInTheBodyIsEscaped(): void
    {
        $response = self::app()->handleDeferred(self::get('/_defer/echo?%3Cb%3E[]=1'));

        self::assertNotNull($response);
        self::assertStringNotContainsString('<b>', $response->body);
    }

    public function testDF5DefaultAndRegisteredCacheControl(): void
    {
        $app = self::app();
        $default = $app->handleDeferred(self::get('/_defer/echo'));
        self::assertNotNull($default);
        self::assertSame('private, max-age=0', $default->headers['Cache-Control']);
        self::assertSame('text/html; charset=UTF-8', $default->headers['Content-Type']);

        $withPanel = new Rosanna()->setSnapshotSecret('s')->register(DeferredPanel::class)->handleDeferred(self::get('/_defer/panel'));
        self::assertNotNull($withPanel);
        self::assertSame('private, max-age=30', $withPanel->headers['Cache-Control']);
    }

    public function testDF6NestedDeferStaysAPlaceholder(): void
    {
        $inner = fc(static fn(): Element => H::p(children: 'inner body'), key: 'inner', storageType: StorageType::Memory, defer: new Defer('inner'));
        $outer = fc(static fn(): Element => H::div(children: ['outer body', $inner()]), key: 'outer', storageType: StorageType::Memory, defer: new Defer('outer'));
        $app = new Rosanna()->registerComponent('App\\Outer', $outer)->registerDeferred('outer', 'App\\Outer');

        $response = $app->handleDeferred(self::get('/_defer/outer'));

        self::assertNotNull($response);
        self::assertStringContainsString('outer body', $response->body);
        self::assertStringNotContainsString('inner body', $response->body);
        self::assertStringContainsString('data-rosanna-defer-url="/_defer/inner"', $response->body);
    }

    public function testDF6AnEndpointRendersInANewPass(): void
    {
        $app = self::app();
        RenderContext::nextInstanceId('X');
        $pass = RenderContext::currentPass();

        $app->handleDeferred(self::get('/_defer/echo'));

        self::assertSame($pass['instanceCounts']['X'], RenderContext::currentPass()['instanceCounts']['X']);
    }

    public function testDF7ExceptionIsHiddenAndLogged(): void
    {
        $app = new Rosanna()->registerComponent('App\\Boom', static function (): never {
            throw new \RuntimeException('/srv/app/secret/path.php');
        })->registerDeferred('boom', 'App\\Boom');
        $level = ob_get_level();

        $response = $app->handleDeferred(self::get('/_defer/boom'));

        self::assertNotNull($response);
        self::assertSame(500, $response->status);
        self::assertSame('Failed to render deferred component.', $response->body);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString('/srv/app/secret/path.php', $this->log());
        self::assertSame($level, ob_get_level());
        self::assertNull(RenderContext::currentScope());
    }

    public function testDF7ARenderFailureIsHiddenToo(): void
    {
        $app = new Rosanna()->registerComponent('App\\Bad', static fn(): Element => new Element('b"ad'))->registerDeferred('bad', 'App\\Bad');

        $response = $app->handleDeferred(self::get('/_defer/bad'));

        self::assertNotNull($response);
        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('tag name', $response->body);
    }

    public function testDF7UnknownTargetIsA500(): void
    {
        $response = new Rosanna()->registerDeferred('x', 'App\\Missing')->handleDeferred(self::get('/_defer/x'));

        self::assertNotNull($response);
        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('App\\Missing', $response->body);
    }

    /** DF-10 エンドポイントの描画の間の署名は defer:{name} で、どのページへの POST でも通る (SG-6) */
    public function testDF10EndpointFormsAreSignedForTheDeferScope(): void
    {
        $app = new Rosanna()->setSnapshotSecret('defer-secret')
            ->registerComponent('App\\C', fc(static function (): Element {
                [$n, $set] = useState(0);

                return H::button(onClick: static fn() => $set($n + 1), children: 'n=' . $n);
            }, key: 'c', storageType: StorageType::Memory, defer: new Defer('c')))
            ->registerDeferred('c', 'App\\C');
        $response = $app->handleDeferred(self::get('/_defer/c'));
        self::assertNotNull($response);
        $fields = self::press($response->body);
        RequestLifecycle::reset();

        foreach (['/one', '/two/three'] as $page) {
            $post = $app->actions()->handle(self::post($fields, uri: $page));
            self::assertNotNull($post);
            self::assertSame(303, $post->status, $page);
            RequestLifecycle::reset();
        }

        // 登録がなければ通らない
        $unregistered = new Rosanna()->setSnapshotSecret('defer-secret');
        $post = $unregistered->actions()->handle(self::post($fields, uri: '/one'));
        self::assertNotNull($post);
        self::assertSame('Invalid action signature', $post->body);
    }

    public function testDF11PrefixIsConfigurableAndUrlsUseIt(): void
    {
        $app = self::app()->setDeferPrefix('/api/_d/');
        $html = RenderContext::run($app->scope(self::get('/ja/page')), static fn(): string => $app->renderElement(H::defer('echo', ['a' => 1])));

        self::assertStringContainsString('data-rosanna-defer-url="/api/_d/echo?a=1"', $html);
        $response = $app->handleDeferred(self::get('/api/_d/echo'));
        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertNull($app->handleDeferred(self::get('/_defer/echo')));
    }

    /** @return iterable<string, array{string}> */
    public static function badPrefixes(): iterable
    {
        yield 'empty' => [''];
        yield 'root' => ['/'];
        yield 'relative' => ['_defer'];
        yield 'protocol relative' => ['//evil.example'];
    }

    #[DataProvider('badPrefixes')]
    public function testDF11BadPrefixThrows(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Rosanna()->setDeferPrefix($prefix);
    }

    public function testClassEndpointSeesTheQueryInGetAndRestoresIt(): void
    {
        $app = new Rosanna()->setSnapshotSecret('s')->register(DeferredPanel::class);
        $outer = $_GET;

        $response = $app->handleDeferred(self::get('/_defer/panel?topic=a'));

        self::assertNotNull($response);
        self::assertStringContainsString('topic=a', $response->body);
        self::assertStringContainsString('name="_rosanna_defer_query" value="topic=a"', $response->body);
        self::assertSame($outer, $_GET);
    }

    public function testNoStateLeaksBetweenEndpointsAtTheSameAppInstance(): void
    {
        $app = self::app();
        $app->handleDeferred(self::get('/_defer/echo?a=1'));
        $second = $app->handleDeferred(self::get('/_defer/echo?b=2'));

        self::assertNotNull($second);
        self::assertStringNotContainsString('&quot;a&quot;', $second->body);
        self::assertNull(ComponentState::current());
    }
}
