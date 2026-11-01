<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Protocol\CsrfGuard;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useState;

require_once __DIR__ . '/../layout.php';

const EXAMPLE_ADMIN_PATH = '/08-guard/admin';

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();

// 権限は状態からではなく、host が持つセッションから読む
if (isset($request->cookies[session_name()]) && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$isAdmin = ($_SESSION['example_role'] ?? '') === 'admin';

// 1. Rosanna の action より先に、host が権限を確かめる。
//    pagePath() は署名と同じ単位なので、`/08-guard/admin/` への POST でも避けられない
if ($request->pagePath() === EXAMPLE_ADMIN_PATH && !$isAdmin) {
    http_response_code(403);
    echo example_layout('Forbidden', '<p>Sign in first. <a href="/08-guard">Back</a></p>');

    return;
}

// 2. ログインとログアウトは host の普通のフォーム。`_rosanna_action` がないので handle() は null を返す
$hostAction = $request->method === 'POST' ? ($request->post['host_action'] ?? null) : null;
if ($hostAction !== null) {
    if ($app->csrf()->verify($request) !== null) {
        http_response_code(403);
        echo example_layout('Forbidden', '<p>Open the page again and retry. <a href="/08-guard">Back</a></p>');

        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    // 実際のアプリではここでパスワードを確かめる
    if ($hostAction === 'sign-in') {
        $_SESSION['example_role'] = 'admin';
    } else {
        unset($_SESSION['example_role']);
    }
    header('Location: /08-guard', true, 303);

    return;
}

$Panel = fc(function (): Element {
    [$runs, $setRuns] = useState(0);

    return H::div(className: 'card', children: [
        H::p(id: 'runs', children: "Job runs: {$runs}"),
        H::button(onClick: fn() => $setRuns($runs + 1), children: 'Run job'),
    ]);
}, key: 'example-admin-panel');

// 3. 残りは他のサンプルと同じ。ここに来た時点で権限は確かめてある
$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Panel());
if ($response !== null) {
    $response->send();

    return;
}

if ($request->pagePath() === EXAMPLE_ADMIN_PATH) {
    $body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Panel()));

    echo example_layout('Admin', $body . '<p><a href="/08-guard">Back</a></p>');

    return;
}

$field = static fn(string $name, string $value): Element => H::input(type: 'hidden', name: $name, value: $value);
$hostForm = H::form(method: 'post', action: '/08-guard', children: [
    $field(CsrfGuard::FIELD, $app->csrf()->token()),
    $field('host_action', $isAdmin ? 'sign-out' : 'sign-in'),
    H::button(children: $isAdmin ? 'Sign out' : 'Sign in as admin'),
]);

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement(H::div(children: [
    H::p(id: 'status', children: $isAdmin ? 'Signed in.' : 'Signed out.'),
    H::p(children: H::a(href: EXAMPLE_ADMIN_PATH, children: 'Open the admin page')),
    $hostForm,
])));

echo example_layout('Page guard', $body);
