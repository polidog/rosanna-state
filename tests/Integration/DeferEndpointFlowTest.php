<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Integration;

use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/**
 * DF-8 `DeferSharedCacheTest` の項目。session_start() と Set-Cookie は実際の HTTP でないと確かめられない。
 */
final class DeferEndpointFlowTest extends AppServerTestCase
{
    use ActionRequests;

    public function testDF8AResponseWithoutSessionKeepsItsPolicyAndDoesNotWarn(): void
    {
        $response = self::http('/_defer/public-plain');

        self::assertSame(200, $response['status']);
        self::assertSame('public, max-age=60', self::header($response, 'Cache-Control'));
        self::assertNull(self::cookieOf($response));
        self::assertSame('', self::serverLog());
    }

    public function testDF8ACookieInTheRequestAloneDoesNotCount(): void
    {
        $cookie = self::cookieOf(self::http('/plant?value=x'));
        self::assertNotNull($cookie);

        $response = self::http('/_defer/public-plain', headers: ['Cookie' => $cookie]);

        self::assertSame('public, max-age=60', self::header($response, 'Cache-Control'));
    }

    public function testDF8UsingTheSessionReplacesASharedPolicy(): void
    {
        $response = self::http('/_defer/public-session');

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('private, no-store', self::header($response, 'Cache-Control'));
        self::assertNotNull(self::cookieOf($response));
        self::assertStringContainsString("[Rosanna] defer endpoint 'public-session'", self::serverLog());
        self::assertStringContainsString('uses the session', self::serverLog());
    }

    public function testDF8ReadingAnExistingSessionReplacesASharedPolicy(): void
    {
        $cookie = self::cookieOf(self::http('/plant?value=x'));
        self::assertNotNull($cookie);

        $response = self::http('/_defer/public-read', headers: ['Cookie' => $cookie]);

        self::assertSame('private, no-store', self::header($response, 'Cache-Control'));
    }

    public function testDF8SettingACookieReplacesASharedPolicy(): void
    {
        $response = self::http('/_defer/public-cookie');

        self::assertSame('private, no-store', self::header($response, 'Cache-Control'));
        self::assertStringContainsString('sets a cookie', self::serverLog());
    }

    public function testDF8CacheLimiterPublicCannotOverrideAPrivatePolicy(): void
    {
        $response = self::http('/_defer/private-session?limiter=public');

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('private, no-store', self::header($response, 'Cache-Control'));
        self::assertStringContainsString('uses the session', self::serverLog());
    }

    public function testDF8APrivatePolicyDoesNotWarn(): void
    {
        $response = self::http('/_defer/private-session');

        self::assertSame('private, max-age=0', self::header($response, 'Cache-Control'));
        self::assertStringNotContainsString('[Rosanna]', self::serverLog());
    }

    public function testDF8ASignedFormWithoutASessionIsTheSameForEveryVisitor(): void
    {
        $strip = static fn(string $html): string => (string) preg_replace('/rosanna-f-[0-9a-f]{16}/', 'F', $html);

        $one = self::http('/_defer/public-form');
        $two = self::http('/_defer/public-form');

        self::assertSame('public, max-age=60', self::header($one, 'Cache-Control'));
        self::assertNull(self::cookieOf($one));
        self::assertStringContainsString('_rosanna_action', $one['body']);
        self::assertSame($strip($one['body']), $strip($two['body']));
    }

    /** DF-9 置き換えられないことをログに出す */
    public function testDF9HeadersAlreadySentAreLogged(): void
    {
        $response = self::http('/defer-after-output');

        self::assertStringEndsWith('|private, no-store', $response['body']);
        self::assertStringContainsString('headers were already sent', self::serverLog());
    }

    public function testDF5DefaultCacheControl(): void
    {
        $response = self::http('/_defer/counter');

        self::assertSame('private, max-age=0', self::header($response, 'Cache-Control'));
        self::assertStringContainsString('text/html', (string) self::header($response, 'Content-Type'));
    }

    public function testDF4KeyInTheQueryCannotChooseAnotherInstance(): void
    {
        $first = self::postForm('/open', self::press(self::http('/_defer/counter')['body']));
        $cookie = self::cookieOf($first);
        self::assertNotNull($cookie);

        $same = self::http('/_defer/counter?key=other', headers: ['Cookie' => $cookie]);

        self::assertStringContainsString('deferred=1', $same['body']);
    }

    public function testDF2NotFoundIsNotStored(): void
    {
        foreach (['/_defer', '/_defer/', '/_defer/nope', '/_defer/a%2Fb', '/_defer/%3Cscript%3E'] as $path) {
            $response = self::http($path);

            self::assertSame(404, $response['status'], $path);
            self::assertSame('no-store', self::header($response, 'Cache-Control'), $path);
            self::assertStringNotContainsString('<script', $response['body']);
        }
    }

    /** #[Defer] のクラスのエンドポイント (RT-12.5) */
    public function testRT125ClassEndpointIsRegistered(): void
    {
        $response = self::http('/_defer/class-panel');

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringContainsString('class=0', $response['body']);
        self::assertStringContainsString('data-rosanna="Deferred#0"', $response['body']);
    }
}
