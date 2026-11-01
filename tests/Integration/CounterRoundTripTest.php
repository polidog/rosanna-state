<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Integration;

use Polidog\Rosanna\State\Tests\Protocol\Support\ActionRequests;

/**
 * GET → action を取り出す → POST → 部分更新を、最小構成の host (`examples/01-counter` 相当) で 1 本通す。
 */
final class CounterRoundTripTest extends AppServerTestCase
{
    use ActionRequests;

    private const int MINUS = 0;
    private const int PLUS = 1;

    protected static function fixture(): string
    {
        return __DIR__ . '/fixtures/counter.php';
    }

    public function testGetActionPostPartialUpdate(): void
    {
        // GET: ラッパーと、ボタンごとの署名付きの action
        $get = self::http('/');
        self::assertSame(200, $get['status'], $get['body']);
        self::assertStringContainsString('Count: 0', $get['body']);
        self::assertStringContainsString('data-rosanna="', $get['body']);
        $fields = self::press($get['body'], self::PLUS);
        self::assertArrayHasKey('_rosanna_action', $fields);
        self::assertNull(self::cookieOf($get), 'the anonymous GET stays sessionless');

        // POST (JavaScript あり): 部分更新はラッパーなしの断片を返す
        $partial = self::postForm('/', $fields, partial: true);
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('Count: 1', $partial['body']);
        self::assertStringNotContainsString('data-rosanna="', $partial['body']);
        $cookie = self::cookieOf($partial);
        self::assertNotNull($cookie);

        // 返ってきた断片の action をそのまま送れる
        $next = self::postForm('/', self::press($partial['body'], self::MINUS), $cookie, partial: true);
        self::assertSame(200, $next['status'], $next['body']);
        self::assertStringContainsString('Count: 0', $next['body']);

        $again = self::postForm('/', self::press($next['body'], self::PLUS), $cookie, partial: true);
        self::assertStringContainsString('Count: 1', $again['body']);

        // 次の GET にも残る
        self::assertStringContainsString('Count: 1', self::http('/', headers: ['Cookie' => $cookie])['body']);
        self::assertSame('', self::serverLog());
    }

    /** JavaScript なし: 303 で戻り、次の GET に反映される */
    public function testPostWithoutJavaScriptRedirects(): void
    {
        $get = self::http('/');

        $post = self::postForm('/', self::press($get['body'], self::PLUS));
        self::assertSame(303, $post['status'], $post['body']);
        self::assertSame('/', self::header($post, 'Location'));
        $cookie = self::cookieOf($post);
        self::assertNotNull($cookie);

        self::assertStringContainsString('Count: 1', self::http('/', headers: ['Cookie' => $cookie])['body']);
    }

    /** 改ざんした action は適用しない */
    public function testTamperedActionIsRefused(): void
    {
        $get = self::http('/');
        $fields = self::press($get['body'], self::PLUS);
        \assert(\is_string($fields['_rosanna_action']));
        $fields['_rosanna_action'] = \str_replace('1', '9', $fields['_rosanna_action']);

        $post = self::postForm('/', $fields, partial: true);

        self::assertGreaterThanOrEqual(400, $post['status'], $post['body']);
        self::assertStringNotContainsString('Count: 9', self::http('/')['body']);
    }
}
