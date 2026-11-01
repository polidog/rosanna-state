<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Storage;

/**
 * ST-2、ST-3、ST-6、U-5 を実際の HTTP で確かめる。
 */
final class SessionLifecycleTest extends SessionServerTestCase
{
    protected function setUp(): void
    {
        self::removeSessionFiles();
    }

    public function testST2ReadWithoutCookieNeitherSendsSetCookieNorCreatesASession(): void
    {
        $response = self::http('/?op=read');

        self::assertSame(200, $response['status']);
        self::assertNull(self::setCookieOf($response));
        self::assertSame([], self::sessionFiles());
        self::assertSame(['value' => 'initial', 'version' => 0, 'status' => 'none', 'session' => null], json_decode($response['body'], true));
    }

    public function testST4CreatingTheStateDoesNotStartASession(): void
    {
        $response = self::http('/?op=new');

        self::assertNull(self::setCookieOf($response));
        self::assertSame([], self::sessionFiles());
    }

    public function testST2FirstWriteStartsTheSessionAndTheNextGetReadsTheValue(): void
    {
        $write = self::http('/?op=write', 'POST');
        $cookie = self::setCookieOf($write);

        self::assertNotNull($cookie);
        self::assertCount(1, self::sessionFiles());
        self::assertSame('written', json_decode($write['body'], true)['value']);

        $read = self::http('/?op=read', 'GET', ['Cookie' => $cookie]);
        $decoded = json_decode($read['body'], true);

        self::assertSame('written', $decoded['value']);
        self::assertSame(1, $decoded['version']);
        self::assertSame('active', $decoded['status']);
    }

    public function testST3ReadWithCookieResumesButDoesNotStoreTheInitialValue(): void
    {
        $cookie = self::setCookieOf(self::http('/?op=effect', 'GET'));
        self::assertNotNull($cookie);

        $decoded = json_decode(self::http('/?op=read', 'GET', ['Cookie' => $cookie])['body'], true);

        self::assertSame('initial', $decoded['value']);
        self::assertSame('active', $decoded['status']);
        self::assertArrayNotHasKey('rosanna:Counter#0:state:0', $decoded['session']);
        self::assertArrayNotHasKey('rosanna:Counter#0:version', $decoded['session']);
    }

    public function testST2BrokenCookieOnReadIsEmptyNotAnError(): void
    {
        $response = self::http('/?op=read', 'GET', ['Cookie' => 'PHPSESSID=bad!id']);
        $decoded = json_decode($response['body'], true);

        self::assertSame(200, $response['status']);
        self::assertSame('initial', $decoded['value']);
        self::assertSame('none', $decoded['status']);
        self::assertSame([], self::sessionFiles());
    }

    public function testU5WriteAfterOutputThrowsLogicException(): void
    {
        $response = self::http('/?op=write-after-output');

        self::assertStringContainsString('LogicException', $response['body']);
        self::assertSame([], self::sessionFiles());
    }

    public function testST6EffectCleanupClosureDoesNotBreakTheSessionWrite(): void
    {
        $response = self::http('/?op=effect');

        self::assertSame(200, $response['status']);
        self::assertStringNotContainsString('Fatal', $response['body']);
        self::assertNotNull(self::setCookieOf($response));

        $files = self::sessionFiles();
        self::assertCount(1, $files);
        $stored = (string) file_get_contents($files[0]);
        self::assertStringContainsString('effect_deps', $stored);
        self::assertStringNotContainsString('Closure', $stored);
    }

    public function testST2EffectWithoutDepsDoesNotStartASession(): void
    {
        $response = self::http('/?op=effect-no-deps');

        self::assertNull(self::setCookieOf($response));
        self::assertSame([], self::sessionFiles());
    }

    public function testSG4VerifyWithoutCookieCreatesNoSession(): void
    {
        $response = self::http('/?op=verify-without-secret', 'POST');
        $decoded = json_decode($response['body'], true);

        self::assertFalse($decoded['ok']);
        self::assertSame('none', $decoded['status']);
        self::assertNull(self::setCookieOf($response));
        self::assertSame([], self::sessionFiles());
    }

    public function testSG3SignWithoutSecretStartsASession(): void
    {
        $response = self::http('/?op=sign-without-secret');

        self::assertNotNull(self::setCookieOf($response));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', json_decode($response['body'], true)['sig']);
    }

    public function testCS4CookieWhoseSessionCannotStartAfterOutputIsRejected(): void
    {
        $response = self::http('/?op=csrf-after-output', 'POST', [
            'Cookie' => 'PHPSESSID=abc123',
            'Origin' => 'http://127.0.0.1',
        ]);

        self::assertStringContainsString('could not be started', $response['body']);
    }
}
