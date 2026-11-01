<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Runtime\Snapshot;
use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Snapshot\SnapshotUrl;

final class SnapshotUrlTest extends TestCase
{
    private static function serializer(): SnapshotSerializer
    {
        return new SnapshotSerializer('secret-fixture-key-1');
    }

    private static function snapshot(): Snapshot
    {
        return new Snapshot('C', 'k', ['a&b=c#d']);
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'protocol relative' => ['//evil.example/x'];
        yield 'host' => ['https://evil.example/x'];
        yield 'scheme only' => ['javascript:alert(1)'];
        yield 'mixed case scheme' => ['JaVaScRiPt:alert(1)'];
        yield 'data' => ['data:text/html,x'];
        yield 'backslash authority' => ['/\\evil.example/x'];
        yield 'two backslashes' => ['\\\\evil.example/x'];
        yield 'tab before slash' => ["/\t/evil.example/x"];
        yield 'newline' => ["/a\r\nSet-Cookie: x=1"];
        yield 'nul' => ["/a\0"];
        yield 'relative' => ['page'];
        yield 'query only' => ['?a=1'];
        yield 'empty' => [''];
        yield 'triple slash' => ['///x'];
    }

    #[DataProvider('rejected')]
    public function testSN20NonSameOriginPathsAreRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SnapshotUrl::with($url, self::snapshot(), self::serializer());
    }

    /** HD-14 単一の `/` で始まるパスは parse_url() が読めなくても同一オリジン */
    public function testSN20APathParseUrlCannotReadIsStillAccepted(): void
    {
        foreach (['/:99999', '/:80', '/item:42', '/a/b:1?keep=1'] as $url) {
            $with = SnapshotUrl::with($url, new Snapshot('C', '0', [1]), new SnapshotSerializer('k-fixture-secret-key'));

            self::assertStringStartsWith(str_contains($url, '?') ? $url . '&_rosanna_snapshot=' : $url . '?_rosanna_snapshot=', $with);
        }
    }

    public function testSN20AddsTheSignedSnapshot(): void
    {
        $url = SnapshotUrl::with('/page', self::snapshot(), self::serializer());

        self::assertStringStartsWith('/page?_rosanna_snapshot=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertIsString($query['_rosanna_snapshot']);
        self::assertSame(['a&b=c#d'], self::serializer()->deserialize($query['_rosanna_snapshot'])->state);
    }

    public function testSN21OtherQueryIsKeptByteForByte(): void
    {
        $url = SnapshotUrl::with('/page?a.b=1&c%20d=%7E&e[]=2&&f#frag', self::snapshot(), self::serializer());

        self::assertStringStartsWith('/page?a.b=1&c%20d=%7E&e[]=2&&f&_rosanna_snapshot=', $url);
        self::assertStringNotContainsString('frag', $url);
    }

    public function testSN21OldSnapshotIsReplaced(): void
    {
        $url = SnapshotUrl::with('/page?_rosanna_snapshot=old&a=1&%5Frosanna_snapshot=old&_rosanna_snapshot[]=old', self::snapshot(), self::serializer());

        self::assertStringStartsWith('/page?a=1&_rosanna_snapshot=', $url);
        self::assertSame(1, substr_count($url, '_rosanna_snapshot'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function queries(): iterable
    {
        yield 'plain' => ['_rosanna_snapshot=x&a=1', 'a=1'];
        yield 'encoded name' => ['%5Frosanna_snapshot=x&a=1', 'a=1'];
        yield 'encoded all' => ['%5Frosanna_%73napshot=x&a=1', 'a=1'];
        yield 'array' => ['_rosanna_snapshot[]=x&a=1', 'a=1'];
        yield 'keyed array' => ['_rosanna_snapshot[k]=x&a=1', 'a=1'];
        yield 'encoded array' => ['_rosanna_snapshot%5B%5D=x&a=1', 'a=1'];
        yield 'no value' => ['_rosanna_snapshot&a=1', 'a=1'];
        yield 'similar names kept' => ['_rosanna_snapshots=x&my_rosanna_snapshot=y&_snapshot=z', '_rosanna_snapshots=x&my_rosanna_snapshot=y&_snapshot=z'];
        yield 'bytes kept' => ['a.b=1&c%20d=%7E&e+f=g', 'a.b=1&c%20d=%7E&e+f=g'];
        yield 'empty segments kept' => ['a=1&&b=2', 'a=1&&b=2'];
        yield 'empty' => ['', ''];
        yield 'only snapshot' => ['_rosanna_snapshot=x', ''];
    }

    #[DataProvider('queries')]
    public function testSN21RemoveParam(string $query, string $expected): void
    {
        self::assertSame($expected, SnapshotUrl::removeParam($query));
    }

    public function testSN22ExtractReadsOnlyAString(): void
    {
        $request = static fn(array $query) => new Request('GET', '/', '/', '', query: $query);

        self::assertSame('x', SnapshotUrl::extract($request(['_rosanna_snapshot' => 'x'])));
        self::assertNull(SnapshotUrl::extract($request(['_rosanna_snapshot' => ['1']])));
        self::assertNull(SnapshotUrl::extract($request([])));
    }

    public function testParamIsFixed(): void
    {
        self::assertSame('_rosanna_snapshot', SnapshotUrl::PARAM);
    }

    public function testU1BehaviorsAreTheThreeThatRemain(): void
    {
        self::assertSame(['isolated', 'persistent', 'session'], array_map(static fn(SnapshotBehavior $b) => $b->value, SnapshotBehavior::cases()));
    }
}
