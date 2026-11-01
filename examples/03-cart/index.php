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

// 本物のアプリならデータベースから読む。金額を状態に入れないのは、状態がクライアント入力だから
const EXAMPLE_CATALOG = [
    'coffee' => ['name' => 'Coffee beans', 'price' => 1200],
    'filter' => ['name' => 'Paper filters', 'price' => 300],
    'mug' => ['name' => 'Mug', 'price' => 1500],
];

$Cart = fc(function (): Element {
    // 一覧全体を 1 つの useState に入れる。商品 ID => 個数
    [$quantities, $setQuantities] = useState([]);

    $total = 0;
    $rows = [];
    foreach (EXAMPLE_CATALOG as $id => $item) {
        $quantity = (int) ($quantities[$id] ?? 0);
        $total += $quantity * $item['price'];

        // 行に id を付けると、rosanna.js が並びの変化を追える
        $rows[] = H::tr(id: "cart-{$id}", children: [
            H::td(children: $item['name']),
            H::td(children: '¥' . number_format($item['price'])),
            H::td(className: 'quantity', children: (string) $quantity),
            H::td(children: [
                H::button(
                    disabled: $quantity === 0,
                    onClick: fn() => $setQuantities([...$quantities, $id => $quantity - 1]),
                    children: '-',
                ),
                ' ',
                H::button(onClick: fn() => $setQuantities([...$quantities, $id => $quantity + 1]), children: '+'),
            ]),
        ]);
    }

    return H::div(children: [
        H::table(children: H::tbody(children: $rows)),
        H::p(id: 'total', children: 'Total: ¥' . number_format($total)),
        H::button(onClick: fn() => $setQuantities([]), children: 'Empty the cart'),
    ]);
}, key: 'example-cart');

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Cart());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Cart()));

echo example_layout('Cart', $body);
