<?php

declare(strict_types=1);

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

require_once __DIR__ . '/../layout.php';

#[Component(name: 'ExampleTabs')]
final class ExampleTabs extends BaseComponent
{
    private const TABS = [
        'about' => 'Rosanna keeps component state on the server.',
        'usage' => 'Register the class, then render it with an optional key.',
        'notes' => 'A registered class is created with `new` and no arguments.',
    ];

    public function render(): Element
    {
        [$active, $setActive] = $this->useState('about');

        return H::div(className: 'card', children: [
            ...array_map(
                fn(string $tab): Element => H::button(
                    disabled: $tab === $active,
                    onClick: fn() => $setActive($tab),
                    children: ucfirst($tab),
                ),
                array_keys(self::TABS),
            ),
            H::p(children: self::TABS[$active] ?? ''),
        ]);
    }
}

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(ExampleTabs::class);

$request = Request::fromGlobals();

// 第 2 引数の key で、同じクラスの 2 つのインスタンスの状態を分ける
$page = fn(): string => $app->render(ExampleTabs::class, 'first') . $app->render(ExampleTabs::class, 'second');

$response = $app->actions()->handle($request, replay: fn(bool $partial): string => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo example_layout('Class component', $page());
