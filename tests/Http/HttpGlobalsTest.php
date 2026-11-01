<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Http;

use Polidog\Rosanna\Tests\Storage\SessionServerTestCase;

final class HttpGlobalsTest extends SessionServerTestCase
{
    public function testResponseSendEmitsStatusHeadersAndBody(): void
    {
        $response = self::http('/?op=response');

        self::assertSame(418, $response['status']);
        self::assertContains('X-Rosanna-Test: yes', $response['headers']);
        self::assertSame('teapot', $response['body']);
    }

    public function testFromGlobalsReadsARealRequest(): void
    {
        $response = self::http('/a/b/?op=request&x=1', 'POST', ['Cookie' => 'c=d', 'X-Custom-Header' => 'v'], 'field=value');
        $decoded = json_decode($response['body'], true);

        self::assertSame('POST', $decoded['method']);
        self::assertSame('/a/b/?op=request&x=1', $decoded['uri']);
        self::assertSame('/a/b/', $decoded['path']);
        self::assertSame('op=request&x=1', $decoded['queryString']);
        self::assertSame(['op' => 'request', 'x' => '1'], $decoded['query']);
        self::assertSame(['field' => 'value'], $decoded['post']);
        self::assertSame(['c' => 'd'], $decoded['cookies']);
        self::assertSame('v', $decoded['custom']);
        self::assertSame('application/x-www-form-urlencoded', $decoded['contentType']);
        self::assertFalse($decoded['https']);
    }
}
