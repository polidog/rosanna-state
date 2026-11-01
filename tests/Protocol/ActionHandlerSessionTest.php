<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Protocol;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\StateDiff;
use Polidog\Rosanna\Storage\StateKey;
use Polidog\Rosanna\Storage\StorageType;
use Polidog\Rosanna\Tests\Protocol\Support\ActionRequests;
use Polidog\Rosanna\Tests\Storage\SessionTestHelper;

/** バージョンが同じで中身の違う Session は HTTP からは作れないので、プロセスの中で組み立てる */
final class ActionHandlerSessionTest extends TestCase
{
    use ActionRequests;
    use SessionTestHelper;

    /** AC-17 バージョンが一致しても基準が違えば適用しない。host が状態を直接変えた場合 */
    #[RunInSeparateProcess]
    public function testAC17SessionBaseMismatchIsNotAppliedAndKeepsTheVersion(): void
    {
        self::isolateSessions();
        $id = 'List#0';
        self::visitorWithCookie('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        session_start();
        $_SESSION[StateKey::statePrefix($id) . '0'] = ['changed'];
        $_SESSION['_rosanna_csrf'] = 'token';
        session_write_close();

        $app = new Rosanna()->setSnapshotSecret('s');
        $json = (string) json_encode([
            'type' => 'setState',
            'payload' => ['index' => 0, 'patch' => ['r', 'next'], 'base' => StateDiff::hash(['original'])],
            'componentId' => $id,
            'storageType' => 'session',
            'version' => 0,
        ]);
        session_start();
        $fields = self::signed($app, $id, $json) + ['_rosanna_csrf' => 'token'];
        session_write_close();
        $request = new \Polidog\Rosanna\Http\Request(
            'POST',
            '/page',
            '/page',
            '',
            [],
            $fields,
            [(string) session_name() => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
            ['host' => 'app.test', 'origin' => 'http://app.test'],
        );

        $response = $app->actions()->handle($request);

        self::assertNotNull($response);
        self::assertSame(303, $response->status);
        $state = ComponentState::getInstance($id, StorageType::Session);
        self::assertSame(['changed'], $state->getState(0, null));
        self::assertSame(0, $state->version());
    }
}
