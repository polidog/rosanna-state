<?php

declare(strict_types=1);

// `examples/01-counter` と同じ最小構成の host。bootstrap の呼び出しはいらない

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\State\fc;

use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '0');

$Counter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial']);

    return H::div(className: 'card', children: [
        H::p(children: "Count: {$count}"),
        H::button(onClick: fn() => $setCount($count - 1), children: '-1'),
        ' ',
        H::button(onClick: fn() => $setCount($count + 1), children: '+1'),
    ]);
}, key: 'example-counter');

$app = new RosannaState();
$app->setSnapshotSecret('round-trip-fixture-key');

$request = Request::fromGlobals();
$page = fn(): Element => $Counter(['initial' => 0]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
