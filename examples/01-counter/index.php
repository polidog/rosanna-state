<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useState;

require_once __DIR__ . '/../layout.php';

$Counter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial']);

    // $setCount($count + 1) は「足す」ではなく、描画の時点で確定した値を「その値にする」action
    return H::div(className: 'card', children: [
        H::p(children: "Count: {$count}"),
        H::button(onClick: fn() => $setCount($count - 1), children: '-1'),
        ' ',
        H::button(onClick: fn() => $setCount($count + 1), children: '+1'),
        ' ',
        H::button(onClick: fn() => $setCount($props['initial']), children: 'Reset'),
    ]);
}, key: 'example-counter');

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => $Counter(['initial' => 0]);

// action なら Response が返る。action でなければ null なので、そのまま描画に進む
$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo example_layout('Counter', $body);
