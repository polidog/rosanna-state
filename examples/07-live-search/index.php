<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Storage\StorageType;

require_once __DIR__ . '/../layout.php';

const EXAMPLE_FRUITS = ['Apple', 'Apricot', 'Banana', 'Blueberry', 'Cherry', 'Grape', 'Lemon', 'Mango', 'Orange', 'Peach'];

// 検索語をサーバーに残す必要がないので Snapshot にする。セッションも始まらない
$Search = fc(function (): Element {
    [$query, $setQuery] = useState('');

    $query = (string) $query;
    $matches = array_values(array_filter(
        EXAMPLE_FRUITS,
        fn(string $fruit): bool => $query === '' || mb_stripos($fruit, $query) !== false,
    ));

    // onInput は入力が 300ms 止まってから送る。JavaScript なしでは onSubmit のボタンで送る
    return H::form(onSubmit: useFormAction(['q' => $setQuery]), children: [
        H::label(children: [
            'Search ',
            H::input(name: 'q', value: $query, autocomplete: 'off', onInput: useFormAction(['q' => $setQuery])),
            ' ',
            H::button(children: 'Go'),
        ]),
        H::p(id: 'count', children: count($matches) . ' found'),
        H::ul(children: array_map(fn(string $fruit): Element => H::li(id: 'fruit-' . strtolower($fruit), children: $fruit), $matches)),
    ]);
}, key: 'example-search', storageType: StorageType::Snapshot);

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Search());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Search()));

echo example_layout('Live search', $body);
