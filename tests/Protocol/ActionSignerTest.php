<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Snapshot\SnapshotSerializer;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

final class ActionSignerTest extends TestCase
{
    use SessionTestHelper;

    private const ID = 'Counter#0';
    private const JSON = '{"type":"setState","payload":{"index":0,"value":1}}';

    private static function request(string $path): Request
    {
        return new Request('POST', $path, $path, '');
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActionSigner('');
    }

    public function testSG9KeyShorterThanTheMinimumIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ActionSigner(str_repeat('k', SnapshotSerializer::MIN_SECRET_BYTES - 1));
    }

    public function testSG1SignatureFormatIsTheDocumentedInput(): void
    {
        self::assertSame(
            hash_hmac('sha256', "rosanna-action\0page:/p\0\0" . self::ID . "\0" . self::JSON, 'secret-fixture-key-1'),
            new ActionSigner('secret-fixture-key-1')->sign(self::ID, self::JSON, '/p'),
        );
    }

    public function testSG1ChangingInstanceJsonOrPathFailsVerification(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $signature = (string) $signer->sign(self::ID, self::JSON, '/p');

        self::assertTrue($signer->verify(self::ID, self::JSON, $signature, '/p'));
        self::assertFalse($signer->verify('Counter#1', self::JSON, $signature, '/p'));
        self::assertFalse($signer->verify(self::ID, self::JSON . ' ', $signature, '/p'));
        self::assertFalse($signer->verify(self::ID, self::JSON, $signature, '/other'));
        self::assertFalse($signer->verify(self::ID, self::JSON, $signature . '0', '/p'));
        self::assertFalse($signer->verify(self::ID, self::JSON, '', '/p'));
        self::assertFalse(new ActionSigner('other-fixture-key-22')->verify(self::ID, self::JSON, $signature, '/p'));
    }

    #[RunInSeparateProcess]
    public function testSG1ChangingTheSessionFailsVerification(): void
    {
        self::isolateSessions();
        $signer = new ActionSigner('secret-fixture-key-1');

        session_id('sessiona');
        session_start();
        $signature = (string) $signer->sign(self::ID, self::JSON, '/p');
        self::assertTrue($signer->verify(self::ID, self::JSON, $signature, '/p'));
        session_write_close();

        session_id('sessionb');
        session_start();
        self::assertFalse($signer->verify(self::ID, self::JSON, $signature, '/p'));
        session_write_close();

        // セッションなし (sessionId は空) とも別の入力
        self::assertFalse($signer->verify(self::ID, self::JSON, $signature, '/p'));
    }

    #[RunInSeparateProcess]
    public function testSG2FallsBackToAPerSessionKeyWithoutSecret(): void
    {
        self::isolateSessions();
        session_id('sessiona');
        session_start();

        $signer = new ActionSigner();
        $signature = $signer->sign(self::ID, self::JSON, '/p');

        self::assertIsString($signature);
        self::assertIsString($_SESSION['_rosanna_action_key']);
        self::assertSame(64, strlen($_SESSION['_rosanna_action_key']));
        self::assertSame(
            hash_hmac('sha256', "rosanna-action\0page:/p\0sessiona\0" . self::ID . "\0" . self::JSON, $_SESSION['_rosanna_action_key']),
            $signature,
        );
        self::assertTrue($signer->verify(self::ID, self::JSON, $signature, '/p'));
        self::assertSame($signature, $signer->sign(self::ID, self::JSON, '/p'), 'the key is stable');
    }

    #[RunInSeparateProcess]
    public function testSG2SecretDoesNotTouchTheSessionKey(): void
    {
        self::isolateSessions();
        session_start();

        new ActionSigner('secret-fixture-key-1')->sign(self::ID, self::JSON, '/p');

        self::assertArrayNotHasKey('_rosanna_action_key', $_SESSION);
    }

    #[RunInSeparateProcess]
    public function testSG3SignWithSecretAndNoCookieStaysSessionless(): void
    {
        self::isolateSessions();

        self::assertIsString(new ActionSigner('secret-fixture-key-1')->sign(self::ID, self::JSON, '/p'));
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testSG3SignResumesFromTheCookie(): void
    {
        self::isolateSessions();
        self::seedSession('abc123', ['x' => 1]);
        self::visitorWithCookie('abc123');

        $signature = new ActionSigner('secret-fixture-key-1')->sign(self::ID, self::JSON, '/p');

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame(
            hash_hmac('sha256', "rosanna-action\0page:/p\0abc123\0" . self::ID . "\0" . self::JSON, 'secret-fixture-key-1'),
            $signature,
        );
    }

    #[RunInSeparateProcess]
    public function testSG3NullWhenNoKeyIsAvailable(): void
    {
        self::isolateSessions();
        session_id('bad!id');

        self::assertNull(new ActionSigner()->sign(self::ID, self::JSON, '/p'));
    }

    #[RunInSeparateProcess]
    public function testSG4VerifyDoesNotStartASessionOrMintAKey(): void
    {
        self::isolateSessions();
        $signer = new ActionSigner();

        self::assertFalse($signer->verify(self::ID, self::JSON, str_repeat('0', 64), '/p'));
        self::assertSame(PHP_SESSION_NONE, session_status());

        session_start();
        self::assertFalse($signer->verify(self::ID, self::JSON, str_repeat('0', 64), '/p'));
        self::assertArrayNotHasKey('_rosanna_action_key', $_SESSION);
    }

    /** @return iterable<string, array{string, string}> */
    public static function samePage(): iterable
    {
        yield 'trailing slash' => ['/a/b', '/a/b/'];
        yield 'leading slashes' => ['/a/b', '//a/b'];
        yield 'no leading slash' => ['/a/b', 'a/b'];
        yield 'many trailing' => ['/a/b', '/a/b///'];
        yield 'root' => ['/', ''];
        yield 'root twice' => ['/', '//'];
    }

    #[DataProvider('samePage')]
    public function testSG5SamePage(string $signedFor, string $postedTo): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');

        self::assertTrue($signer->verify(self::ID, self::JSON, (string) $signer->sign(self::ID, self::JSON, $signedFor), $postedTo));
    }

    /** @return iterable<string, array{string, string}> */
    public static function otherPage(): iterable
    {
        yield 'other path' => ['/admin', '/public'];
        yield 'sub path' => ['/a', '/a/b'];
        yield 'percent encoding is distinct' => ['/a', '/%61'];
        yield 'encoded slash is distinct' => ['/a/b', '/a%2Fb'];
        yield 'case is distinct' => ['/a', '/A'];
        yield 'inner slashes are distinct' => ['/a/b', '/a//b'];
    }

    #[DataProvider('otherPage')]
    public function testSG5OtherPageFails(string $signedFor, string $postedTo): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');

        self::assertFalse($signer->verify(self::ID, self::JSON, (string) $signer->sign(self::ID, self::JSON, $signedFor), $postedTo));
    }

    public function testSG5ForRequestUsesThePathWithoutTheQuery(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $signature = (string) $signer->forRequest(new Request('GET', '/p?a=1', '/p', 'a=1'))->sign(self::ID, self::JSON);

        self::assertTrue($signer->forRequest(new Request('POST', '/p?b=2', '/p', 'b=2'))->verify(self::ID, self::JSON, $signature));
        self::assertFalse($signer->forRequest(self::request('/q'))->verify(self::ID, self::JSON, $signature));
    }

    public function testSG6DeferFormsPassOnAnyPageWhenRegistered(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1', ['list', 'cart']);
        $endpoint = $signer->forRequest(self::request('/_defer/cart'), 'cart');

        self::assertSame('defer:cart', $endpoint->signingScope());
        $signature = (string) $endpoint->sign(self::ID, self::JSON);

        self::assertTrue($signer->forRequest(self::request('/any/page'))->verify(self::ID, self::JSON, $signature));
        self::assertTrue($signer->forRequest(self::request('/other'))->verify(self::ID, self::JSON, $signature));
    }

    public function testSG6UnregisteredDeferIsNotAccepted(): void
    {
        $signature = (string) new ActionSigner('secret-fixture-key-1', ['cart'])
            ->forRequest(self::request('/_defer/gone'), 'gone')
            ->sign(self::ID, self::JSON);

        self::assertFalse(new ActionSigner('secret-fixture-key-1', ['cart'])->forRequest(self::request('/p'))->verify(self::ID, self::JSON, $signature));
        self::assertFalse(new ActionSigner('secret-fixture-key-1')->forRequest(self::request('/p'))->verify(self::ID, self::JSON, $signature));
    }

    public function testSG6PageSignatureIsNotADeferSignature(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1', ['cart']);
        $signature = (string) $signer->sign(self::ID, self::JSON, '/guarded');

        self::assertFalse($signer->forRequest(self::request('/public'))->verify(self::ID, self::JSON, $signature));
    }

    public function testSG7NoRequestFieldChoosesTheScope(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $signature = (string) $signer->sign(self::ID, self::JSON, '/guarded');

        $request = new Request(
            'POST',
            '/guarded',
            '/public',
            'path=/guarded&scope=page:/guarded',
            query: ['path' => '/guarded', 'scope' => 'page:/guarded'],
            post: ['_rosanna_scope' => 'page:/guarded', 'path' => '/guarded'],
            headers: ['x-original-url' => '/guarded', 'referer' => 'http://h/guarded'],
        );

        self::assertFalse($signer->forRequest($request)->verify(self::ID, self::JSON, $signature));
    }

    public function testSG8ThePathIsTheOneTheHostGaveNotTheRawUri(): void
    {
        $_SERVER['REQUEST_URI'] = '/en/page';
        try {
            $signer = new ActionSigner('secret-fixture-key-1');
            $rewritten = new Request('POST', '/en/page', '/page', '');
            $signature = (string) $signer->sign(self::ID, self::JSON, '/page');

            self::assertTrue($signer->forRequest($rewritten)->verify(self::ID, self::JSON, $signature));
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    public function testSG8PagePathWinsOverTheBoundRequest(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $bound = $signer->forRequest(self::request('/bound'));

        self::assertSame($signer->sign(self::ID, self::JSON, '/given'), $bound->sign(self::ID, self::JSON, '/given'));
        self::assertTrue($bound->verify(self::ID, self::JSON, (string) $signer->sign(self::ID, self::JSON, '/given'), '/given'));
    }

    public function testRQ3ForRequestLeavesTheOriginalUntouched(): void
    {
        $base = new ActionSigner('secret-fixture-key-1');
        $bound = $base->forRequest(self::request('/a'));

        self::assertNotSame($base, $bound);
        self::assertNull($base->signingScope());
        self::assertSame('page:/a', $bound->signingScope());

        // 同じ本体から続けて 2 つのリクエストを処理しても混ざらない
        $signature = (string) $bound->sign(self::ID, self::JSON);
        self::assertFalse($base->forRequest(self::request('/b'))->verify(self::ID, self::JSON, $signature));
        self::assertTrue($bound->verify(self::ID, self::JSON, $signature));
    }

    public function testSeparatorsCannotBeShifted(): void
    {
        $signer = new ActionSigner('secret-fixture-key-1');
        $signature = (string) $signer->sign('a#b', self::JSON, '/p');

        self::assertFalse($signer->verify("a#b\0", self::JSON, $signature, '/p'));
        self::assertFalse($signer->verify('a#b', self::JSON, $signature, "/p\0"));
    }
}
