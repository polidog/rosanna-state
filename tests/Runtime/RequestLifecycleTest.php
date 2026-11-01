<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Runtime;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\Storage\SessionStorage;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/**
 * RT-9.6 worker モードの 1 プロセスで訪問者が入れ替わる。
 */
final class RequestLifecycleTest extends TestCase
{
    use SessionTestHelper;

    private const KEY = 'rosanna:Secret#0:state:0';
    private const ALICE = 'alicealicealicealicealiceal';
    private const BOB = 'bobbobbobbobbobbobbobbobbob';

    private static function nextVisitor(?string $cookie): void
    {
        $_COOKIE = $cookie === null ? [] : [(string) session_name() => $cookie];
    }

    private static function aliceWrites(): void
    {
        self::isolateSessions();
        self::visitorWithCookie(self::ALICE);
        new SessionStorage()->set(self::KEY, 'alice');
    }

    #[RunInSeparateProcess]
    public function testResetClosesTheSessionAndForgetsItsValues(): void
    {
        self::aliceWrites();

        RequestLifecycle::reset();

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame([], $_SESSION);
    }

    #[RunInSeparateProcess]
    public function testAVisitorWithoutACookieDoesNotEnterThePreviousSession(): void
    {
        self::aliceWrites();

        RequestLifecycle::reset();
        self::nextVisitor(null);

        self::assertNull(new SessionStorage()->get(self::KEY));
        new SessionStorage()->set('rosanna:Other#0:state:0', 'anonymous');
        self::assertNotSame(self::ALICE, session_id());
        self::assertArrayNotHasKey(self::KEY, $_SESSION);
    }

    #[RunInSeparateProcess]
    public function testAVisitorWithACookieGetsTheirOwnSession(): void
    {
        self::aliceWrites();

        RequestLifecycle::reset();
        self::nextVisitor(self::BOB);

        self::assertNull(new SessionStorage()->get(self::KEY));
        self::assertSame(self::BOB, session_id());
    }

    #[RunInSeparateProcess]
    public function testTheWritesSurviveForTheVisitorsNextRequest(): void
    {
        self::aliceWrites();
        RequestLifecycle::reset();
        self::nextVisitor(self::BOB);
        new SessionStorage()->set(self::KEY, 'bob');
        RequestLifecycle::reset();

        self::nextVisitor(self::ALICE);
        self::assertSame('alice', new SessionStorage()->get(self::KEY));
        RequestLifecycle::reset();

        self::nextVisitor(self::BOB);
        self::assertSame('bob', new SessionStorage()->get(self::KEY));
    }

    #[RunInSeparateProcess]
    public function testASessionTheHostClosedIsReleasedToo(): void
    {
        self::aliceWrites();
        session_write_close();

        RequestLifecycle::reset();
        self::nextVisitor(self::BOB);

        self::assertNull(new SessionStorage()->get(self::KEY));
        self::assertSame(self::BOB, session_id());
    }

    #[RunInSeparateProcess]
    public function testAnIdTheHostChoseAfterTheResetIsKept(): void
    {
        self::aliceWrites();
        RequestLifecycle::reset();

        self::nextVisitor(self::BOB);
        session_id('hostchosenhostchosenhostcho');
        new SessionStorage()->set(self::KEY, 'host');

        self::assertSame('hostchosenhostchosenhostcho', session_id());
    }

    #[RunInSeparateProcess]
    public function testAMalformedCookieAfterTheResetGetsANewSession(): void
    {
        self::aliceWrites();
        RequestLifecycle::reset();

        self::nextVisitor("bad\r\nid");
        new SessionStorage()->set(self::KEY, 'new');

        self::assertNotSame(self::ALICE, session_id());
        self::assertMatchesRegularExpression('/^[A-Za-z0-9,-]+$/D', (string) session_id());
    }

    #[RunInSeparateProcess]
    public function testResetWithoutASessionDoesNotStartOne(): void
    {
        self::isolateSessions();

        RequestLifecycle::reset();

        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertSame('', session_id());
    }
}
