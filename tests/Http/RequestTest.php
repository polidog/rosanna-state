<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\ActionSigner;

#[RunTestsInSeparateProcesses]
final class RequestTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function uris(): iterable
    {
        yield 'plain' => ['/a?x=1', '/a', 'x=1'];
        yield 'no query' => ['/a/', '/a/', ''];
        yield 'fragment' => ['/a?x=1#f', '/a', 'x=1'];
        yield 'double slash is a path, not an authority' => ['//evil.example/admin?x=1', '/evil.example/admin', 'x=1'];
        yield 'absolute form' => ['http://host.example/a?x=1', '/a', 'x=1'];
        yield 'colon in the query' => ['/a?u=http://x:y', '/a', 'u=http://x:y'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function pagePaths(): iterable
    {
        yield 'plain' => ['/admin', '/admin'];
        yield 'trailing slash' => ['/admin/', '/admin'];
        yield 'many trailing slashes' => ['/admin//', '/admin'];
        yield 'leading slashes' => ['//admin', '/admin'];
        yield 'root' => ['/', '/'];
        yield 'empty' => ['', '/'];
        yield 'inner slashes stay' => ['/a//b/', '/a//b'];
        yield 'percent encoding stays' => ['/%61dmin/', '/%61dmin'];
    }

    /** SG-5 host の保護は署名と同じ単位で比べる */
    #[DataProvider('pagePaths')]
    public function testSG5PagePathIsTheNormalizationTheSignatureUses(string $path, string $expected): void
    {
        $page = new Request('GET', $path, $path, '')->pagePath();

        self::assertSame($expected, $page);
        self::assertSame('page:' . $page, ActionSigner::pageScope($path));
    }

    #[DataProvider('uris')]
    public function testPathAndQueryAreSplit(string $uri, string $path, string $query): void
    {
        $_SERVER['REQUEST_URI'] = $uri;

        $request = Request::fromGlobals();

        self::assertSame($uri, $request->uri);
        self::assertSame($path, $request->path);
        self::assertSame($query, $request->queryString);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedUris(): iterable
    {
        yield 'parse_url fails' => ['http://:80'];
        yield 'bracket' => ['///\\\\http://[::1'];
        yield 'bad scheme form' => ['http:///x'];
        yield 'empty' => [''];
        yield 'only query' => ['?x=1'];
    }

    #[DataProvider('malformedUris')]
    public function testRQ1MalformedRequestUriDoesNotThrow(string $uri): void
    {
        $_SERVER['REQUEST_URI'] = $uri;

        $request = Request::fromGlobals();

        self::assertStringStartsWith('/', $request->path);
        self::assertSame($uri, $request->uri);
    }

    public function testRQ1MissingServerValuesHaveDefaults(): void
    {
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_SERVER['HTTPS']);

        $request = Request::fromGlobals();

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->path);
        self::assertFalse($request->https);
    }

    public function testHttpsMarker(): void
    {
        $_SERVER['HTTPS'] = 'on';
        self::assertTrue(Request::fromGlobals()->https);

        $_SERVER['HTTPS'] = 'off';
        self::assertFalse(Request::fromGlobals()->https);
    }

    public function testArrayCookiesAreDropped(): void
    {
        $_COOKIE = ['PHPSESSID' => ['x'], 'ok' => 'v'];

        self::assertSame(['ok' => 'v'], Request::fromGlobals()->cookies);
    }
}
