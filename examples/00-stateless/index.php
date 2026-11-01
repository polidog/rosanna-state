<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

require_once __DIR__ . '/../layout.php';

// hooks も action も使わないページ。セッションは始まらず、data-rosanna も出ない
final readonly class PriceRow implements Renderable
{
    public function __construct(
        private string $name,
        private int $price,
    ) {}

    public function render(): Element
    {
        return H::tr(children: [
            H::td(children: $this->name),
            H::td(children: number_format($this->price) . ' yen'),
        ]);
    }
}

$request = Request::fromGlobals();
$query = is_string($request->query['q'] ?? null) ? $request->query['q'] : '';

$items = ['Coffee' => 480, 'Tea' => 420, 'Toast' => 350];
$matched = array_filter($items, static fn(string $name): bool => $query === '' || stripos($name, $query) !== false, ARRAY_FILTER_USE_KEY);

// 入力は host が読み、値として渡すだけ。エスケープは Renderer が行う
$page = H::div(children: [
    H::form(method: 'get', children: [
        H::input(type: 'search', name: 'q', value: $query, placeholder: 'Search'),
        ' ',
        H::button(type: 'submit', children: 'Search'),
    ]),
    $query === '' ? null : H::p(children: "Results for: {$query}"),
    H::table(children: array_map(
        static fn(string $name, int $price): PriceRow => new PriceRow($name, $price),
        array_keys($matched),
        $matched,
    )),
]);

$app = new RosannaState();
$body = RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($page));

echo example_layout('Stateless page', $body);
