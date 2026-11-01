<?php

declare(strict_types=1);

// 組み込みサーバーで動かす実際のリクエスト。Set-Cookie は CLI の中では確かめられない

use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Http\Response;
use Polidog\Rosanna\Protocol\ActionSigner;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Runtime\Action;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Storage\StorageType;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '1');

$op = is_string($_GET['op'] ?? null) ? $_GET['op'] : '';
$state = ComponentState::getInstance('Counter#0', StorageType::Session);

$summary = static fn(array $extra = []): string => (string) json_encode($extra + [
    'status' => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'none',
    'session' => session_status() === PHP_SESSION_ACTIVE ? $_SESSION : null,
]);

switch ($op) {
    case 'read':
        $value = $state->getState(0, 'initial');
        echo $summary(['value' => $value, 'version' => $state->version()]);
        break;

    case 'new':
        echo $summary();
        break;

    case 'write':
        $state->setState(0, 'written');
        $state->incrementVersion();
        echo $summary(['value' => $state->getState(0, 'initial'), 'version' => $state->version()]);
        break;

    case 'write-after-output':
        echo "output\n";
        flush();
        try {
            $state->setState(0, 'written');
            echo 'no exception';
        } catch (LogicException) {
            echo 'LogicException';
        }
        break;

    case 'effect':
        $state->runEffect(0, static fn() => static function (): void {}, ['dep']);
        echo $summary();
        break;

    case 'effect-no-deps':
        $state->runEffect(0, static fn() => null, null);
        echo $summary();
        break;

    case 'sign-without-secret':
        $signer = new ActionSigner();
        $json = Action::setState(0, 1)->toJson();
        echo json_encode(['sig' => $signer->sign('Counter#0', $json, '/page'), 'json' => $json]);
        break;

    case 'verify-without-secret':
        $signer = new ActionSigner();
        $ok = $signer->verify('Counter#0', '{}', str_repeat('0', 64), '/page');
        echo $summary(['ok' => $ok]);
        break;

    case 'csrf-after-output':
        echo "output\n";
        flush();
        echo json_encode(['reason' => new CsrfGuard()->verify(Request::fromGlobals())]);
        break;

    case 'response':
        new Response(418, ['X-Rosanna-Test' => 'yes', 'Cache-Control' => 'no-store'], 'teapot')->send();
        break;

    case 'request':
        $request = Request::fromGlobals();
        echo json_encode([
            'method' => $request->method,
            'uri' => $request->uri,
            'path' => $request->path,
            'queryString' => $request->queryString,
            'query' => $request->query,
            'post' => $request->post,
            'cookies' => $request->cookies,
            'host' => $request->headers['host'] ?? null,
            'custom' => $request->headers['x-custom-header'] ?? null,
            'contentType' => $request->headers['content-type'] ?? null,
            'https' => $request->https,
        ]);
        break;
}
