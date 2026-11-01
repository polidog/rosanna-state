<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

final class CsrfGuardTest extends TestCase
{
    use SessionTestHelper;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     */
    private static function request(array $headers, array $post = [], array $cookies = [], bool $https = false): Request
    {
        return new Request('POST', '/', '/', '', post: $post, cookies: $cookies, headers: $headers + ['host' => 'app.example'], https: $https);
    }

    /** @return iterable<string, array{array<string, string>, bool, bool}> */
    public static function origins(): iterable
    {
        $o = 'http://app.example';

        yield 'same origin' => [['origin' => $o], false, true];
        yield 'no origin or referer' => [[], false, false];
        yield 'other origin' => [['origin' => 'http://evil.example'], false, false];
        yield 'other scheme' => [['origin' => 'https://app.example'], false, false];
        yield 'https origin on https' => [['origin' => 'https://app.example'], true, true];
        yield 'http origin on https' => [['origin' => 'http://app.example'], true, false];
        yield 'other port' => [['origin' => 'http://app.example:8080'], false, false];
        yield 'suffix host' => [['origin' => 'http://app.example.evil.example'], false, false];
        yield 'prefix host' => [['origin' => 'http://evilapp.example'], false, false];
        yield 'origin null with no referer' => [['origin' => 'null'], false, false];
        yield 'empty origin with no referer' => [['origin' => ''], false, false];
        yield 'origin null falls to matching referer' => [['origin' => 'null', 'referer' => $o . '/page'], false, true];
        yield 'origin null falls to other referer' => [['origin' => 'null', 'referer' => 'http://evil.example/'], false, false];
        yield 'referer same' => [['referer' => $o . '/page?x=1'], false, true];
        yield 'referer other' => [['referer' => 'http://evil.example/app.example'], false, false];
        yield 'referer other scheme' => [['referer' => 'https://app.example/'], false, false];
        yield 'referer other port' => [['referer' => 'http://app.example:81/'], false, false];
        yield 'referer default port' => [['referer' => 'http://app.example:80/'], false, true];
        yield 'referer with userinfo' => [['referer' => 'http://app.example@evil.example/'], false, false];
        yield 'referer with backslash' => [['referer' => 'http://evil.example\\@app.example/'], false, false];
        yield 'referer relative' => [['referer' => '/page'], false, false];
        yield 'origin wins over referer' => [['origin' => 'http://evil.example', 'referer' => $o . '/'], false, false];
        yield 'host case is the same origin' => [['origin' => 'http://APP.example'], false, true];
        yield 'origin with a path is not an origin' => [['origin' => $o . '/x'], false, false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('origins')]
    #[RunInSeparateProcess]
    public function testCS2FirstLayer(array $headers, bool $https, bool $accepted): void
    {
        self::isolateSessions();

        $reason = new CsrfGuard()->verify(self::request($headers, https: $https));

        if ($accepted) {
            self::assertNull($reason);
        } else {
            self::assertIsString($reason);
        }
    }

    public function testCS2MissingHostIsRejected(): void
    {
        self::assertIsString(new CsrfGuard()->verify(new Request('POST', '/', '/', '', headers: ['origin' => 'http://app.example'])));
    }

    public function testCS2HostPortIsCompared(): void
    {
        $request = new Request('POST', '/', '/', '', headers: ['host' => 'app.example:8080', 'origin' => 'http://app.example:8080']);

        self::assertNull(new CsrfGuard()->verify($request));
    }

    #[RunInSeparateProcess]
    public function testCS3SecondLayer(): void
    {
        self::isolateSessions();
        session_id('sessiona');
        session_start();

        $guard = new CsrfGuard();
        $token = $guard->token();
        $origin = ['origin' => 'http://app.example'];

        self::assertNull($guard->verify(self::request($origin, ['_rosanna_csrf' => $token])));
        self::assertIsString($guard->verify(self::request($origin)), 'no token');
        self::assertIsString($guard->verify(self::request($origin, ['_rosanna_csrf' => 'wrong'])), 'wrong token');
        self::assertIsString($guard->verify(self::request($origin, ['_rosanna_csrf' => ''])), 'empty token');
        self::assertIsString($guard->verify(self::request($origin, ['_rosanna_csrf' => [$token]])), 'array token');
    }

    #[RunInSeparateProcess]
    public function testCS3TokenOfAnotherSessionIsRejected(): void
    {
        self::isolateSessions();
        $guard = new CsrfGuard();

        session_id('sessiona');
        session_start();
        $tokenA = $guard->token();
        session_write_close();

        session_id('sessionb');
        session_start();
        $_SESSION = [];
        $tokenB = $guard->token();

        self::assertNotSame($tokenA, $tokenB);
        self::assertIsString($guard->verify(self::request(['origin' => 'http://app.example'], ['_rosanna_csrf' => $tokenA])));
    }

    #[RunInSeparateProcess]
    public function testCS3SessionWithoutATokenRejects(): void
    {
        self::isolateSessions();
        session_start();

        self::assertIsString(new CsrfGuard()->verify(self::request(['origin' => 'http://app.example'], ['_rosanna_csrf' => ''])));
    }

    #[RunInSeparateProcess]
    public function testCS4MalformedCookieFailsClosed(): void
    {
        self::isolateSessions();
        $name = (string) session_name();
        $_COOKIE[$name] = 'bad!id';
        session_id('bad!id');

        $reason = new CsrfGuard()->verify(self::request(['origin' => 'http://app.example'], cookies: [$name => 'bad!id']));

        self::assertIsString($reason);
        self::assertStringContainsString('could not be started', $reason);
        self::assertStringNotContainsString('bad!id', $reason);
    }

    #[RunInSeparateProcess]
    public function testCS4CookieWithUnusableSessionStoreFailsClosed(): void
    {
        self::isolateSessions();
        session_save_path('/nonexistent/rosanna');
        $name = (string) session_name();
        $_COOKIE[$name] = 'abc123';

        $reason = new CsrfGuard()->verify(self::request(['origin' => 'http://app.example'], cookies: [$name => 'abc123']));

        self::assertIsString($reason);
    }

    #[RunInSeparateProcess]
    public function testCS5CookielessPostWithTheRightOriginPassesAndStaysSessionless(): void
    {
        self::isolateSessions();

        self::assertNull(new CsrfGuard()->verify(self::request(['origin' => 'http://app.example'])));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testCS6TokenIsStableHexAndEmptyWithoutASession(): void
    {
        self::isolateSessions();
        $guard = new CsrfGuard();

        self::assertSame('', $guard->token());
        self::assertSame(PHP_SESSION_NONE, session_status());

        session_start();
        $token = $guard->token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, $guard->token());
        self::assertSame($token, new CsrfGuard()->token());
        self::assertSame($token, $_SESSION['_rosanna_csrf']);
    }

    /** @return iterable<string, array{array<string, string>, string, bool}> */
    public static function proxied(): iterable
    {
        yield 'host and proto' => [['x-forwarded-host' => 'pub.example', 'x-forwarded-proto' => 'https'], 'https://pub.example', false];
        yield 'first of the list' => [['x-forwarded-host' => 'pub.example, inner.example', 'x-forwarded-proto' => 'https,http'], 'https://pub.example', false];
        yield 'port appended' => [['x-forwarded-host' => 'pub.example', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '8443'], 'https://pub.example:8443', false];
        yield 'default port not appended' => [['x-forwarded-host' => 'pub.example', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '443'], 'https://pub.example', false];
        yield 'http default port' => [['x-forwarded-host' => 'pub.example', 'x-forwarded-proto' => 'http', 'x-forwarded-port' => '80'], 'http://pub.example', false];
        yield 'port in host wins' => [['x-forwarded-host' => 'pub.example:9000', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '8443'], 'https://pub.example:9000', false];
        yield 'bracketed ipv6 has no port' => [['x-forwarded-host' => '[::1]', 'x-forwarded-proto' => 'http', 'x-forwarded-port' => '8000'], 'http://[::1]:8000', false];
        yield 'non numeric port ignored' => [['x-forwarded-host' => 'pub.example', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => 'x'], 'https://pub.example', false];
        yield 'no forwarded headers falls back' => [[], 'https://app.example', true];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('proxied')]
    #[RunInSeparateProcess]
    public function testCS7TrustedProxyHeaders(array $headers, string $origin, bool $https): void
    {
        self::isolateSessions();

        $request = self::request($headers + ['origin' => $origin], https: $https);

        self::assertNull(new CsrfGuard(trustProxyHeaders: true)->verify($request));
    }

    #[RunInSeparateProcess]
    public function testCS7ForwardedHeadersAreIgnoredByDefault(): void
    {
        self::isolateSessions();
        $forwarded = ['x-forwarded-host' => 'evil.example', 'x-forwarded-proto' => 'https', 'x-forwarded-port' => '8443'];

        self::assertIsString(new CsrfGuard()->verify(self::request($forwarded + ['origin' => 'https://evil.example:8443'])));
        self::assertNull(new CsrfGuard()->verify(self::request($forwarded + ['origin' => 'http://app.example'])));
    }

    #[RunInSeparateProcess]
    public function testCS8ReasonDoesNotEchoClientInput(): void
    {
        self::isolateSessions();

        $reason = new CsrfGuard()->verify(self::request(['origin' => 'http://evil.example/<script>']));

        self::assertIsString($reason);
        self::assertStringNotContainsString('evil', $reason);
    }

    #[RunInSeparateProcess]
    public function testDisabledAlwaysPassesAndHasNoToken(): void
    {
        self::isolateSessions();
        session_start();

        $guard = new CsrfGuard(enabled: false);

        self::assertNull($guard->verify(new Request('POST', '/', '/', '')));
        self::assertSame('', $guard->token());
        self::assertArrayNotHasKey('_rosanna_csrf', $_SESSION);
    }

    #[RunInSeparateProcess]
    public function testCS1VerifyResumesBeforeJudgingSoTheTokenLayerRuns(): void
    {
        self::isolateSessions();
        self::seedSession('abc123', ['_rosanna_csrf' => 'token']);
        self::visitorWithCookie('abc123');
        $cookies = [(string) session_name() => 'abc123'];
        $origin = ['origin' => 'http://app.example'];

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertIsString(new CsrfGuard()->verify(self::request($origin, cookies: $cookies)), 'the token layer must not be skipped');
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertNull(new CsrfGuard()->verify(self::request($origin, ['_rosanna_csrf' => 'token'], $cookies)));
    }

    #[RunInSeparateProcess]
    public function testCS1ResumeSessionIsCallableEvenWhenDisabled(): void
    {
        self::isolateSessions();
        self::seedSession('abc123', []);
        self::visitorWithCookie('abc123');

        new CsrfGuard(enabled: false)->resumeSession(self::request([], cookies: [(string) session_name() => 'abc123']));

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[RunInSeparateProcess]
    public function testCS1ResumeWithoutACookieDoesNothing(): void
    {
        self::isolateSessions();

        new CsrfGuard()->resumeSession(self::request([]));

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testRejectedOriginDoesNotTouchTheSession(): void
    {
        self::isolateSessions();
        self::visitorWithCookie('abc123');

        new CsrfGuard()->verify(self::request(['origin' => 'http://evil.example'], cookies: [(string) session_name() => 'abc123']));

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testRQ3GuardHasNoPerRequestState(): void
    {
        $guard = new CsrfGuard();
        $properties = array_map(static fn(\ReflectionProperty $p) => $p->name, new \ReflectionClass($guard)->getProperties());

        self::assertSame(['enabled', 'trustProxyHeaders'], $properties);
    }
}
