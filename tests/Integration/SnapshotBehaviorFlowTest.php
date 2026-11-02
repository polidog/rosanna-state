<?php

declare(strict_types=1);

namespace Polidog\Rosanna\State\Tests\Integration;

use Polidog\Rosanna\State\Snapshot\SnapshotUrl;
use Polidog\Rosanna\State\Tests\Protocol\Support\ActionRequests;

/**
 * 2.5 節の置き場所を実際の HTTP で確かめる (SN-23、SN-24、SN-26〜SN-28、SN-33)。
 */
final class SnapshotBehaviorFlowTest extends AppServerTestCase
{
    use ActionRequests;

    private const SIGNED_ELSEWHERE = '{"memo":{"name":"SnapshotCounter","key":"0","version":0},"state":[9],"effectDeps":[],"checksum":"0"}';

    /** @return array{status: int, headers: list<string>, body: string} */
    private static function followWithCookie(string $location, ?string $cookie): array
    {
        return self::http($location, headers: $cookie === null ? [] : ['Cookie' => $cookie]);
    }

    public function testPersistentCarriesTheStateInTheRedirect(): void
    {
        $get = self::http('/snapshot/persistent?page=2');
        self::assertStringContainsString('count=0', $get['body']);

        $post = self::postForm('/snapshot/persistent?page=2', self::press($get['body']));
        self::assertSame(303, $post['status']);
        $location = (string) self::header($post, 'Location');
        self::assertStringStartsWith('/snapshot/persistent?page=2&_rosanna_snapshot=', $location);

        $next = self::followWithCookie($location, null);
        self::assertStringContainsString('count=1', $next['body']);

        // 2 回目の redirect で古いパラメーターが残らない
        $second = self::postForm($location, self::press($next['body']));
        $secondLocation = (string) self::header($second, 'Location');
        self::assertSame(1, substr_count($secondLocation, '_rosanna_snapshot='));
        self::assertStringContainsString('count=2', self::followWithCookie($secondLocation, null)['body']);
    }

    /**
     * SN-33 クエリの長さで、redirect の URL がちょうど $bytes になるように作る。
     * スナップショットの長さは行き先に依らないので、1 回目の応答から求める。
     */
    private static function targetFor(int $bytes): string
    {
        $probe = '/snapshot/persistent?pad=';
        $location = (string) self::header(self::postForm($probe, self::press(self::http($probe)['body'])), 'Location');
        $pad = $bytes - strlen($location);
        self::assertGreaterThan(0, $pad);

        return $probe . str_repeat('p', $pad);
    }

    public function testSN33AUrlOfExactlyTheLimitKeepsTheSnapshot(): void
    {
        $target = self::targetFor(SnapshotUrl::MAX_URL_BYTES);

        $post = self::postForm($target, self::press(self::http($target)['body']));
        $location = (string) self::header($post, 'Location');

        self::assertSame(303, $post['status']);
        self::assertSame(SnapshotUrl::MAX_URL_BYTES, strlen($location));
        self::assertStringStartsWith($target . '&_rosanna_snapshot=', $location);
        self::assertStringContainsString('count=1', self::followWithCookie($location, null)['body']);
        self::assertStringNotContainsString('Snapshot dropped', self::serverLog());
    }

    public function testSN33AUrlOneByteOverTheLimitDropsTheSnapshotAndLogs(): void
    {
        $target = self::targetFor(SnapshotUrl::MAX_URL_BYTES + 1);

        $post = self::postForm($target, self::press(self::http($target)['body']));

        self::assertSame(303, $post['status']);
        self::assertSame($target, self::header($post, 'Location'));
        self::assertStringContainsString('[Rosanna] Snapshot dropped (behavior=Persistent)', self::serverLog());
        self::assertStringContainsString('SnapshotCounter#0', self::serverLog());
        self::assertStringContainsString('SnapshotBehavior::Session', self::serverLog());

        // ページは初期状態で開け、次の action も動く
        $next = self::followWithCookie($target, null);
        self::assertSame(200, $next['status']);
        self::assertStringContainsString('count=0', $next['body']);
        $shorter = self::postForm('/snapshot/persistent', self::press($next['body']));
        self::assertStringContainsString('count=1', self::followWithCookie((string) self::header($shorter, 'Location'), null)['body']);
    }

    public function testSN27BrokenPersistentSnapshotRendersThePageWithoutState(): void
    {
        foreach (['broken', '{}', '[]', self::SIGNED_ELSEWHERE] as $value) {
            $get = self::http('/snapshot/persistent?_rosanna_snapshot=' . rawurlencode($value));

            self::assertSame(200, $get['status']);
            self::assertStringContainsString('count=0', $get['body']);
        }
        self::assertStringContainsString('Snapshot rejected (behavior=Persistent)', self::serverLog());
    }

    public function testSN22ArrayParameterIsIgnored(): void
    {
        $get = self::http('/snapshot/persistent?_rosanna_snapshot[]=1');

        self::assertSame(200, $get['status']);
        self::assertStringContainsString('count=0', $get['body']);
    }

    public function testSN28IsolatedDoesNotReadTheQuery(): void
    {
        // Persistent の URL の値は、この秘密鍵で署名されている
        $persistent = self::postForm('/snapshot/persistent', self::press(self::http('/snapshot/persistent')['body']));
        $query = (string) parse_url((string) self::header($persistent, 'Location'), PHP_URL_QUERY);

        $get = self::http('/snapshot/isolated?' . $query);

        self::assertStringContainsString('count=0', $get['body']);
        self::assertStringNotContainsString('rejected', self::serverLog());
    }

    public function testRow5PlainIsolatedAnswersWithThePageSoTheStateSurvives(): void
    {
        $get = self::http('/snapshot/isolated');
        $first = self::postForm('/snapshot/isolated', self::press($get['body']));
        self::assertSame(200, $first['status']);
        self::assertStringContainsString('count=1', $first['body']);

        $second = self::postForm('/snapshot/isolated', self::press($first['body']));
        self::assertStringContainsString('count=2', $second['body']);
    }

    public function testSessionBehaviorWritesToTheSessionAndRestoresOnGet(): void
    {
        $get = self::http('/snapshot/session');
        self::assertNull(self::cookieOf($get));

        $post = self::postForm('/snapshot/session', self::press($get['body']));
        self::assertSame(303, $post['status']);
        self::assertSame('/snapshot/session', self::header($post, 'Location'));
        $cookie = self::cookieOf($post);
        self::assertNotNull($cookie);

        self::assertStringContainsString('count=1', self::followWithCookie('/snapshot/session', $cookie)['body']);
        // Cookie のない別の訪問者には見えない
        self::assertStringContainsString('count=0', self::followWithCookie('/snapshot/session', null)['body']);

        $partial = self::postForm('/snapshot/session', self::press(self::followWithCookie('/snapshot/session', $cookie)['body']), $cookie, partial: true);
        self::assertSame(303, $partial['status']);
        self::assertStringContainsString('count=2', self::followWithCookie('/snapshot/session', $cookie)['body']);
    }

    /** HD-14 書けないときは書かず、ログに出す */
    public function testHD14SessionThatCannotStartIsNotWritten(): void
    {
        $get = self::http('/snapshot/session');

        $post = self::postForm('/snapshot/session?early=1', self::press($get['body']));

        self::assertStringContainsString('Snapshot not stored (behavior=Session)', self::serverLog());
        self::assertSame([], self::sessionFiles());
        self::assertSame(200, $post['status']);
    }

    /** SN-23 同じセッションで署名したものでも URL からは読まない */
    public function testSN23SessionBehaviorIgnoresEvenASessionBoundSnapshotFromTheQueryString(): void
    {
        $signed = self::http('/sign-for-session');
        $cookie = self::cookieOf($signed);
        self::assertNotNull($cookie);

        $get = self::http('/snapshot/session?_rosanna_snapshot=' . rawurlencode($signed['body']), headers: ['Cookie' => $cookie]);

        self::assertStringContainsString('count=0', $get['body']);
        self::assertStringContainsString('Snapshot ignored (behavior=Session)', self::serverLog());
    }

    /** SN-24 別のセッションの署名がページを壊さず、捨てられる */
    public function testSN24SnapshotSignedForAnotherSessionIsRejected(): void
    {
        $signed = self::http('/sign-for-session');
        $planted = self::http('/plant?value=' . rawurlencode($signed['body']));
        $cookie = self::cookieOf($planted);
        self::assertNotNull($cookie);

        $get = self::http('/snapshot/session', headers: ['Cookie' => $cookie]);

        self::assertSame(200, $get['status']);
        self::assertStringContainsString('count=0', $get['body']);
        self::assertStringContainsString('Snapshot rejected (behavior=Session)', self::serverLog());
    }

    public function testSN24CorruptSnapshotInTheSessionIsIgnored(): void
    {
        foreach (['not json', '{}', self::SIGNED_ELSEWHERE] as $value) {
            $cookie = self::cookieOf(self::http('/plant?value=' . rawurlencode($value)));
            self::assertNotNull($cookie);

            $get = self::http('/snapshot/session', headers: ['Cookie' => $cookie]);

            self::assertSame(200, $get['status']);
            self::assertStringContainsString('count=0', $get['body']);
        }
    }

    /** このセッションで正しく署名したものは読める。上の 2 つが署名の失敗で落ちていることの確認 */
    public function testSN24AValidEntryIsRestored(): void
    {
        $signed = self::http('/sign-for-session');
        $cookie = self::cookieOf($signed);
        self::assertNotNull($cookie);
        self::http('/plant?value=' . rawurlencode($signed['body']), headers: ['Cookie' => $cookie]);

        self::assertStringContainsString('count=50', self::http('/snapshot/session', headers: ['Cookie' => $cookie])['body']);
    }

    /** ST-2、SN-29 defer のエンドポイントはスロットを読まない */
    public function testSN29TheDeferEndpointDoesNotReadTheSlot(): void
    {
        $signed = self::http('/sign-for-session');
        $cookie = self::cookieOf($signed);
        self::assertNotNull($cookie);
        self::http('/plant?value=' . rawurlencode($signed['body']), headers: ['Cookie' => $cookie]);

        $deferred = self::http('/_defer/public-plain', headers: ['Cookie' => $cookie]);

        self::assertSame(200, $deferred['status']);
        self::assertSame('', self::serverLog());
    }
}
