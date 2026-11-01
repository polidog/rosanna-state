<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Integration;

use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;

/**
 * Session の action を実際の HTTP で確かめる (RT-12.7、ST-2、SG-2〜4、CS-1〜6、VR-4、VR-10、AC-18)。
 */
final class SessionActionFlowTest extends AppServerTestCase
{
    use ActionRequests;

    /** RT-12.7、02 の 7 節の素の PHP の例 */
    public function testTheEmbeddingExampleWorksEndToEnd(): void
    {
        $get = self::http('/readme');
        self::assertSame(200, $get['status']);
        self::assertStringContainsString('count=0', $get['body']);
        self::assertStringContainsString('data-rosanna="Counter#0"', $get['body']);
        // SG-3 秘密鍵がなければ署名のためにセッションを始める
        $cookie = self::cookieOf($get);
        self::assertNotNull($cookie);

        $post = self::postForm('/readme', self::press($get['body']), $cookie);
        self::assertSame(303, $post['status']);
        self::assertSame('/readme', self::header($post, 'Location'));

        $next = self::http('/readme', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('count=1', $next['body']);

        // JavaScript ありの部分更新
        $partial = self::postForm('/readme', self::press($next['body']), $cookie, partial: true);
        self::assertSame(200, $partial['status']);
        self::assertStringContainsString('count=2', $partial['body']);
        self::assertStringNotContainsString('data-rosanna="', $partial['body']);
    }

    /** ST-2 秘密鍵があれば匿名の GET はセッションを始めず、action の適用で初めて始める */
    public function testST2AnonymousGetStaysSessionlessAndTheFirstWriteStartsTheSession(): void
    {
        $get = self::http('/secret');
        self::assertStringContainsString('count=0', $get['body']);
        self::assertNull(self::cookieOf($get));
        self::assertSame([], self::sessionFiles());
        self::assertStringNotContainsString('_rosanna_csrf', $get['body']);

        $post = self::postForm('/secret', self::press($get['body']));
        self::assertSame(303, $post['status']);
        $cookie = self::cookieOf($post);
        self::assertNotNull($cookie);
        self::assertCount(1, self::sessionFiles());

        $next = self::http('/secret', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('count=1', $next['body']);
        self::assertStringContainsString('_rosanna_csrf', $next['body']);
    }

    /** SessionBootstrapTest::testSessionStateSurvivesTwoFullPagePosts に相当 */
    public function testST2StateSurvivesTwoFullPagePosts(): void
    {
        $get = self::http('/secret');
        $first = self::postForm('/secret', self::press($get['body']));
        $cookie = self::cookieOf($first);
        self::assertNotNull($cookie);

        $page = self::http('/secret', headers: ['Cookie' => $cookie]);
        $second = self::postForm('/secret', self::press($page['body']), $cookie);
        self::assertSame(303, $second['status']);

        self::assertStringContainsString('count=2', self::http('/secret', headers: ['Cookie' => $cookie])['body']);
    }

    /** SG-4 Cookie のない POST が検証用の鍵を作らない */
    public function testSG4PostWithoutCookieOrSecretIsRejectedAndCreatesNoSession(): void
    {
        $get = self::http('/nosecret');
        $fields = self::press($get['body']);
        foreach (self::sessionFiles() as $file) {
            unlink($file);
        }

        $post = self::postForm('/nosecret', $fields);

        self::assertSame(400, $post['status']);
        self::assertSame('Invalid action signature', $post['body']);
        self::assertNull(self::cookieOf($post));
        self::assertSame([], self::sessionFiles());
    }

    /** SG-1 別のセッションの署名は通らない */
    public function testSG1ASignatureFromAnotherSessionIsRejected(): void
    {
        $mine = self::http('/nosecret');
        $theirs = self::http('/nosecret');
        $theirCookie = self::cookieOf($theirs);
        self::assertNotNull($theirCookie);
        $mineFields = self::press($mine['body']);
        $theirToken = self::press($theirs['body'])['_rosanna_csrf'];
        $mineFields['_rosanna_csrf'] = $theirToken;

        $post = self::postForm('/nosecret', $mineFields, $theirCookie);

        self::assertSame(400, $post['status']);
        self::assertSame('Invalid action signature', $post['body']);
    }

    /** SG-2 秘密鍵なしではセッションごとの鍵で動く */
    public function testSG2WithoutASecretAPerSessionKeyWorks(): void
    {
        $get = self::http('/nosecret');
        $cookie = self::cookieOf($get);
        self::assertNotNull($cookie);

        $post = self::postForm('/nosecret', self::press($get['body']), $cookie, partial: true);

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('count=1', $post['body']);
    }

    /** SN-17 秘密鍵がないのに運ばれるスナップショットを受けたら 400。Snapshot の action も同じ */
    public function testSN17CarriedSnapshotsWithoutASecretAreRejected(): void
    {
        $get = self::http('/nosecret');
        $cookie = self::cookieOf($get);
        self::assertNotNull($cookie);
        $fields = self::press($get['body']);
        $fields['_rosanna_snapshots'] = ['{"memo":{"name":"A","key":"0","version":0},"state":[],"effectDeps":[],"checksum":"x"}'];

        $post = self::postForm('/nosecret', $fields, $cookie, partial: true);

        self::assertSame(400, $post['status']);
        self::assertSame('Invalid snapshot', $post['body']);
        self::assertStringContainsString('count=0', self::http('/nosecret', headers: ['Cookie' => $cookie])['body']);
    }

    /** CS-1 Cookie を持つ訪問者の action は再開の後に判定される。秘密鍵の有無によらず通る */
    public function testCS1ActionIsAcceptedInEveryCombination(): void
    {
        foreach (['/readme', '/secret', '/fc', '/nosecret'] as $path) {
            // 訪問者が Cookie を持っている状態を作る
            $cookie = self::cookieOf(self::http('/plant?value=x'));
            self::assertNotNull($cookie);

            $get = self::http($path, headers: ['Cookie' => $cookie]);
            $post = self::postForm($path, self::press($get['body']), $cookie);

            self::assertSame(303, $post['status'], $path . ': ' . $post['body']);
        }
    }

    public function testCS2NoOriginOrRefererIsRejected(): void
    {
        $fields = self::press(self::http('/secret')['body']);
        $post = self::http('/secret', 'POST', [], http_build_query($fields));

        self::assertSame(403, $post['status']);
        self::assertSame('Forbidden', $post['body']);
        self::assertSame('no-store', self::header($post, 'Cache-Control'));
        // CS-8 一律の本文で、理由はログにだけ出す
        self::assertStringContainsString('[Rosanna] CSRF check failed: request has no Origin or Referer', self::serverLog());
        self::assertSame([], self::sessionFiles());
    }

    public function testCS2AnotherOriginIsRejectedAndARefererOnTheSameOriginPasses(): void
    {
        $fields = self::press(self::http('/secret')['body']);

        self::assertSame(403, self::postForm('/secret', $fields, headers: ['Origin' => 'http://evil.example'])['status']);
        self::assertSame(403, self::postForm('/secret', $fields, headers: ['Origin' => 'null'])['status']);
        self::assertSame(303, self::http('/secret', 'POST', ['Referer' => self::origin() . '/secret'], http_build_query($fields))['status']);
    }

    public function testCS3WrongTokenIsRejected(): void
    {
        $get = self::http('/nosecret');
        $cookie = self::cookieOf($get);
        self::assertNotNull($cookie);
        $fields = self::press($get['body']);
        self::assertNotSame('', $fields['_rosanna_csrf']);

        $missing = $fields;
        unset($missing['_rosanna_csrf']);
        self::assertSame(403, self::postForm('/nosecret', $missing, $cookie)['status']);

        $wrong = ['_rosanna_csrf' => str_repeat('0', 64)] + $fields;
        self::assertSame(403, self::postForm('/nosecret', $wrong, $cookie)['status']);

        self::assertStringContainsString('count=0', self::http('/nosecret', headers: ['Cookie' => $cookie])['body']);
    }

    /** CS-4 クライアントが第 2 層を外せない */
    public function testCS4MalformedSessionCookieFailsClosed(): void
    {
        $fields = self::press(self::http('/secret')['body']);

        $post = self::postForm('/secret', $fields, 'PHPSESSID=' . str_repeat('!', 10));

        self::assertSame(403, $post['status']);
        self::assertStringContainsString('session could not be started', self::serverLog());
    }

    /** CS-5 Snapshot だけのページはセッションなしで動く */
    public function testCS5SnapshotPageStaysSessionlessWithoutACookie(): void
    {
        $get = self::http('/snapshot/isolated');
        self::assertNull(self::cookieOf($get));

        $post = self::postForm('/snapshot/isolated', self::press($get['body']));

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('count=1', $post['body']);
        self::assertSame('no-store', self::header($post, 'Cache-Control'));
        self::assertNull(self::cookieOf($post));
        self::assertSame([], self::sessionFiles());
    }

    /** VR-4 2 つのタブでそれぞれ 1 回押すと、2 回目は適用されず、描き直された値が 1 のまま */
    public function testVR4CounterOpenInTwoTabs(): void
    {
        $first = self::http('/secret');
        $cookie = self::cookieOf(self::postForm('/secret', self::press($first['body'])));
        self::assertNotNull($cookie);

        $tabA = self::http('/secret', headers: ['Cookie' => $cookie]);
        $tabB = self::http('/secret', headers: ['Cookie' => $cookie]);

        $a = self::postForm('/secret', self::press($tabA['body']), $cookie, partial: true);
        self::assertSame(200, $a['status']);
        self::assertStringContainsString('count=2', $a['body']);
        self::assertNull(self::header($a, 'X-Rosanna-Stale'));

        $b = self::postForm('/secret', self::press($tabB['body']), $cookie, partial: true);
        self::assertSame(200, $b['status']);
        self::assertSame('1', self::header($b, 'X-Rosanna-Stale'));
        self::assertStringContainsString('count=2', $b['body']);
        self::assertStringNotContainsString('count=3', $b['body']);
    }

    /** VR-4 通常の POST は 303 で、適用しない */
    public function testVR4SameFormTwiceOnAPlainPostIsNotApplied(): void
    {
        $first = self::http('/secret');
        $cookie = self::cookieOf(self::postForm('/secret', self::press($first['body'])));
        self::assertNotNull($cookie);
        $fields = self::press(self::http('/secret', headers: ['Cookie' => $cookie])['body']);

        self::assertSame(303, self::postForm('/secret', $fields, $cookie)['status']);
        $again = self::postForm('/secret', $fields, $cookie);

        self::assertSame(303, $again['status']);
        self::assertNull(self::header($again, 'X-Rosanna-Stale'));
        self::assertStringContainsString('count=2', self::http('/secret', headers: ['Cookie' => $cookie])['body']);
        // VR-4 のエラーの節 ログにも出さない
        self::assertStringNotContainsString('[Rosanna]', self::serverLog());
    }

    /** VR-4 setFields でも同じ。U-3 の Stale を付ける */
    public function testVR4SetFieldsTwiceIsNotApplied(): void
    {
        $first = self::http('/form');
        self::assertNull(self::cookieOf($first));
        $cookie = self::cookieOf(self::postForm('/form', self::submit($first['body'], ['title' => 'one'])));
        self::assertNotNull($cookie);
        $page = self::http('/form', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('title=one', $page['body']);

        $second = self::postForm('/form', self::submit($page['body'], ['title' => 'two']), $cookie, partial: true);
        self::assertStringContainsString('title=two', $second['body']);
        self::assertNull(self::header($second, 'X-Rosanna-Stale'));

        $stale = self::postForm('/form', self::submit($page['body'], ['title' => 'three']), $cookie, partial: true);
        self::assertSame(200, $stale['status']);
        self::assertSame('1', self::header($stale, 'X-Rosanna-Stale'));
        self::assertStringContainsString('title=two', $stale['body']);
    }

    /** VR-4 #80 の例。古い一覧の更新が新しい一覧を巻き戻さない */
    public function testVR4AStaleListDoesNotRollBackANewerList(): void
    {
        $first = self::http('/list');
        $cookie = self::cookieOf(self::postForm('/list', self::press($first['body'], 0)));
        self::assertNotNull($cookie);

        $old = self::http('/list', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('1:on', $old['body']);
        // 別のタブが 2 行目を更新する
        $newer = self::postForm('/list', self::press($old['body'], 1), $cookie, partial: true);
        self::assertStringContainsString('2:on', $newer['body']);

        // 古い一覧の 3 行目のボタン
        $stale = self::postForm('/list', self::press($old['body'], 2), $cookie, partial: true);

        self::assertSame('1', self::header($stale, 'X-Rosanna-Stale'));
        self::assertStringContainsString('1:on', $stale['body']);
        self::assertStringContainsString('2:on', $stale['body']);
        self::assertStringContainsString('3:off', $stale['body']);
    }

    /** VR-10 兄弟のインスタンスには影響しない */
    public function testVR10SiblingsDoNotAffectEachOther(): void
    {
        $first = self::http('/fc');
        $cookie = self::cookieOf(self::postForm('/fc', self::press($first['body'], 0)));
        self::assertNotNull($cookie);

        $page = self::http('/fc', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('count=1', $page['body']);
        self::assertStringContainsString('count=0', $page['body']);

        // 左を更新した後も、同じページの右のフォームは古くならない
        $left = self::postForm('/fc', self::press($page['body'], 0), $cookie, partial: true);
        self::assertStringContainsString('count=2', $left['body']);
        $right = self::postForm('/fc', self::press($page['body'], 1), $cookie, partial: true);

        self::assertSame(200, $right['status']);
        self::assertNull(self::header($right, 'X-Rosanna-Stale'));
        self::assertStringContainsString('count=1', $right['body']);
        // 部分更新はそのラッパーの中身だけ
        self::assertStringNotContainsString('layout', $right['body']);
        self::assertStringNotContainsString('data-rosanna="', $right['body']);
    }

    /** AC-18 Cookie のない GET の差分のフォームに基準が出て、Set-Cookie が出ない。その POST で適用され、次の描画には出ない */
    public function testAC18BaseFieldFlow(): void
    {
        $get = self::http('/list');
        self::assertNull(self::cookieOf($get));
        self::assertStringContainsString('_rosanna_base[0]', $get['body']);
        self::assertStringContainsString('patch', html_entity_decode($get['body'], ENT_QUOTES));

        $post = self::postForm('/list', self::press($get['body'], 0));
        self::assertSame(303, $post['status']);
        $cookie = self::cookieOf($post);
        self::assertNotNull($cookie);

        $next = self::http('/list', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('1:on', $next['body']);
        self::assertStringNotContainsString('_rosanna_base', $next['body']);
    }

    /** AC-18 Memory はリクエストを越えて保存されないので、断片のフォームにも毎回基準を出す。セッションは始まらない */
    public function testAC18MemoryCarriesTheBaseOnEveryRender(): void
    {
        $html = self::http('/list-memory')['body'];

        foreach (['1:on', '2:on', '3:on'] as $row => $expected) {
            self::assertStringContainsString('_rosanna_base[0]', $html, $expected);
            $post = self::postForm('/list-memory', self::press($html, $row), partial: true);

            self::assertSame(200, $post['status'], $expected);
            self::assertNull(self::header($post, 'X-Rosanna-Stale'), $expected);
            self::assertStringContainsString($expected, $post['body']);
            self::assertNull(self::cookieOf($post));
            $html = $post['body'];
        }
        self::assertSame([], self::sessionFiles());
    }

    /** @return array{string, array<string, mixed>} 1 回適用した後の Cookie と、そのページのボタンの送信の内容 */
    private static function appliedOnce(string $path): array
    {
        $cookie = self::cookieOf(self::postForm($path, self::press(self::http($path)['body'])));
        self::assertNotNull($cookie);

        return [$cookie, self::press(self::http($path, headers: ['Cookie' => $cookie])['body'])];
    }

    /** HD-6、VR-4、JS-8a 文字列の replay の Session でも、古い部分更新は通知つきの断片を返す */
    public function testHD6StringReplayTellsAStalePartialOnSession(): void
    {
        [$cookie, $fields] = self::appliedOnce('/string-replay');

        $fresh = self::postForm('/string-replay', $fields, $cookie, partial: true);
        self::assertSame(303, $fresh['status'], 'A fresh action does not need the search');

        $stale = self::postForm('/string-replay', $fields, $cookie, partial: true);
        self::assertSame(200, $stale['status']);
        self::assertSame('1', self::header($stale, 'X-Rosanna-Stale'));
        self::assertStringContainsString('count=2', $stale['body']);
        self::assertStringNotContainsString('<main>', $stale['body']);
    }

    /** HD-6 探しても見つからなければ 303 に落ちる */
    public function testHD6StringReplayFallsBackToARedirectWhenTheStaleTargetIsGone(): void
    {
        [$cookie, $fields] = self::appliedOnce('/string-replay');
        self::postForm('/string-replay', $fields, $cookie, partial: true);

        $stale = self::postForm('/string-replay?hidden=1', $fields, $cookie, partial: true);

        self::assertSame(303, $stale['status']);
    }

    /** VR-4 の例外 運ばれるスナップショットがある古い Session の通常の POST は全ページのまま。303 だと兄弟の状態を失う */
    public function testVR4StalePlainPostWithCarriedSnapshotsStaysAFullPage(): void
    {
        [$cookie, $fields] = self::appliedOnce('/mixed-storage');
        $page = self::http('/mixed-storage', headers: ['Cookie' => $cookie]);
        $fields = self::press($page['body'], 0);
        $snapshot = self::dom($page['body'])->querySelector('[data-rosanna-snapshot]');
        self::assertNotNull($snapshot);
        $fields['_rosanna_snapshots'] = [(string) $snapshot->getAttribute('data-rosanna-snapshot')];

        $applied = self::postForm('/mixed-storage', $fields, $cookie);
        self::assertSame(200, $applied['status']);
        self::assertStringContainsString('count=2', $applied['body']);

        $stale = self::postForm('/mixed-storage', $fields, $cookie);
        self::assertSame(200, $stale['status']);
        self::assertStringContainsString('count=2', $stale['body'], 'not applied a second time');
        self::assertStringContainsString('snapshot=0', $stale['body']);
        self::assertStringContainsString('<main>', $stale['body']);
    }

    /** AC-18、AC-17 改ざんした基準は適用されない。4xx にもしない */
    public function testAC18TamperedBaseIsNotApplied(): void
    {
        $fields = self::press(self::http('/list')['body'], 0);
        $fields['_rosanna_base'] = [0 => '[{"id":99}]'];

        $post = self::postForm('/list', $fields, partial: true);

        self::assertSame(200, $post['status']);
        self::assertSame('1', self::header($post, 'X-Rosanna-Stale'));
        self::assertStringContainsString('1:off', $post['body']);
    }

    /** AC-17 Session で保存値が base と違う差分は適用されず、バージョンも変わらない */
    public function testAC17SessionDiffOnAChangedValueIsNotApplied(): void
    {
        $first = self::http('/list');
        $cookie = self::cookieOf(self::postForm('/list', self::press($first['body'], 0)));
        self::assertNotNull($cookie);
        $page = self::http('/list', headers: ['Cookie' => $cookie]);
        self::postForm('/list', self::press($page['body'], 1), $cookie);
        // VR-4 の照合が先に動く
        $stale = self::postForm('/list', self::press($page['body'], 2), $cookie);
        self::assertSame(303, $stale['status']);

        $after = self::http('/list', headers: ['Cookie' => $cookie]);
        self::assertStringContainsString('3:off', $after['body']);
        self::assertStringContainsString('2:on', $after['body']);
    }

    /** RQ-2 実際のサーバーで headers_list() が増えず出力もない */
    public function testRQ2NothingIsSentByHandle(): void
    {
        $fields = self::press(self::http('/probe')['body']);

        foreach ([[$fields, true, 200], [$fields, false, 303], [['_rosanna_component' => 'x'] + $fields, false, 400]] as [$body, $partial, $status]) {
            $response = self::postForm('/probe', $body, partial: $partial);
            $probe = json_decode($response['body'], true);
            self::assertIsArray($probe, $response['body']);

            self::assertSame($status, $probe['status']);
            self::assertSame($probe['headersBefore'], $probe['headersAfter']);
            self::assertSame($probe['codeBefore'], $probe['codeAfter']);
            self::assertSame('', $probe['printed']);
        }

        // 403 も同じ
        $response = self::postForm('/probe', $fields, headers: ['Origin' => 'http://evil.example']);
        $probe = json_decode($response['body'], true);
        self::assertSame(403, $probe['status']);
        self::assertSame('', $probe['printed']);
    }

    /** SG-6、host の保護 */
    public function testSG6DeferredFormPassesOnItsPageAndTheHostGuardRunsFirst(): void
    {
        $endpoint = self::http('/_defer/counter');
        self::assertSame(200, $endpoint['status'], $endpoint['body']);
        self::assertStringContainsString('deferred=0', $endpoint['body']);
        $fields = self::press($endpoint['body']);

        // 保護されたページでは host が handle() より前に止める
        $guarded = self::postForm('/guarded', $fields);
        self::assertSame(401, $guarded['status']);
        self::assertSame([], self::sessionFiles());

        $partial = self::postForm('/guarded', $fields, 'auth=yes', partial: true);
        self::assertSame(200, $partial['status'], $partial['body']);
        self::assertStringContainsString('deferred=1', $partial['body']);
        self::assertStringNotContainsString('data-rosanna-defer-url', $partial['body']);

        // 部分更新で出すフォームはそのページで署名し直すので、別のページには送れない
        $cookie = self::cookieOf($partial);
        self::assertNotNull($cookie);
        $reissued = self::press($partial['body']);
        $elsewhere = self::postForm('/open', $reissued, 'auth=yes; ' . $cookie, partial: true);
        self::assertSame(400, $elsewhere['status']);
        self::assertSame('Invalid action signature', $elsewhere['body']);
    }

    /** HD-13 実際のリクエスト行で open redirect にならない */
    public function testHD13RealRequestLineDoesNotLeaveTheOrigin(): void
    {
        $fields = self::press(self::http('/secret')['body']);

        $post = self::http('//secret?x=1', 'POST', ['Origin' => self::origin()], http_build_query($fields));

        self::assertSame(303, $post['status'], $post['body']);
        self::assertSame('/secret?x=1', self::header($post, 'Location'));
    }

    /** HD-16 セッションを始めた部分更新にだけ付く。登録されたクラスと fc() の両方の経路 */
    public function testHD16TheFirstPartialThatStartsTheSessionAnnouncesIt(): void
    {
        foreach (['/secret', '/fc'] as $path) {
            $get = self::http($path);
            self::assertNull(self::cookieOf($get), $path);

            $first = self::postForm($path, self::press($get['body']), partial: true);
            self::assertSame(200, $first['status'], $path);
            self::assertSame('1', self::header($first, 'X-Rosanna-Session-Started'), $path);
            $cookie = self::cookieOf($first);
            self::assertNotNull($cookie, $path);

            // 2 回目の action と、Cookie のある訪問者の最初の action
            $page = self::http($path, headers: ['Cookie' => $cookie]);
            $second = self::postForm($path, self::press($page['body']), $cookie, partial: true);
            self::assertSame(200, $second['status'], $path);
            self::assertNull(self::header($second, 'X-Rosanna-Session-Started'), $path);
        }
    }

    /** HD-16 通常の POST は 303 か全ページで全フォームが新しくなる */
    public function testHD16APlainPostDoesNotAnnounceIt(): void
    {
        $post = self::postForm('/secret', self::press(self::http('/secret')['body']));

        self::assertSame(303, $post['status']);
        self::assertNotNull(self::cookieOf($post));
        self::assertNull(self::header($post, 'X-Rosanna-Session-Started'));
    }

    /** HD-16 Snapshot だけの action はセッションを始めない */
    public function testHD16ASnapshotOnlyPartialDoesNotAnnounceIt(): void
    {
        $post = self::postForm('/snapshot/isolated', self::press(self::http('/snapshot/isolated')['body']), partial: true);

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('count=1', $post['body']);
        self::assertNull(self::cookieOf($post));
        self::assertNull(self::header($post, 'X-Rosanna-Session-Started'));
    }

    /** HD-16 秘密鍵なしでは描画でセッションが始まっている (SG-3) */
    public function testHD16WithoutASecretTheFormsAlreadyBelongToTheSession(): void
    {
        $get = self::http('/nosecret');
        $cookie = self::cookieOf($get);
        self::assertNotNull($cookie);

        $post = self::postForm('/nosecret', self::press($get['body']), $cookie, partial: true);

        self::assertSame(200, $post['status']);
        self::assertNull(self::header($post, 'X-Rosanna-Session-Started'));
    }

    /** HD-16 拒否された action は適用されず、セッションも始まらない */
    public function testHD16ARejectedPartialDoesNotAnnounceIt(): void
    {
        $fields = self::press(self::http('/secret')['body']);
        $fields['_rosanna_action'] = str_replace('\\"value\\":1', '\\"value\\":9', (string) $fields['_rosanna_action']);

        $post = self::postForm('/secret', $fields, partial: true);

        self::assertSame(400, $post['status']);
        self::assertNull(self::header($post, 'X-Rosanna-Session-Started'));
    }

    /** RQ-1 と HD-1 を実際のリクエストで */
    public function testHD1RelayerTokenFallsThroughToTheHost(): void
    {
        $post = self::http('/secret', 'POST', [], '_rosanna_action=rosanna-action%3Aabc');

        self::assertSame(200, $post['status']);
        self::assertStringContainsString('count=0', $post['body']);
        self::assertNull(self::cookieOf($post));
        self::assertSame([], self::sessionFiles());
    }
}
