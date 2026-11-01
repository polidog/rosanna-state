<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

require_once __DIR__ . '/../layout.php';

// どの保存先でもコンポーネントの中身は同じ。fc() の引数だけが違う。リロードして残り方を比べる
$view = function (string $label, string $reload): Element {
    [$count, $setCount] = useState(0);

    return H::div(className: 'card', children: [
        H::p(children: [H::strong(children: $label), " (after a reload: {$reload})"]),
        H::span(className: 'count', children: (string) $count),
        ' ',
        H::button(onClick: fn() => $setCount($count + 1), children: '+1'),
    ]);
};

$InSession = fc(fn() => $view('Session', 'kept'), key: 'example-session');
$InMemory = fc(fn() => $view('Memory', 'reset'), key: 'example-memory', storageType: StorageType::Memory);
$InSnapshot = fc(fn() => $view('Snapshot', 'reset'), key: 'example-snapshot', storageType: StorageType::Snapshot);
$Persisted = fc(
    fn() => $view('Snapshot + sessionStorage', 'kept in this tab'),
    key: 'example-persisted',
    storageType: StorageType::Snapshot,
    persist: SnapshotPersist::SessionStorage,
);

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::div(children: [$InSession(), $InMemory(), $InSnapshot(), $Persisted()]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo example_layout('Storage types', $body);
