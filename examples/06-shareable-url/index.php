<?php

declare(strict_types=1);

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Snapshot\SnapshotBehavior;

require_once __DIR__ . '/../layout.php';

/** 状態を URL で運ぶので、URL を送るだけで同じページを開ける */
#[Component(name: 'ExamplePager', storage: 'snapshot')]
final class ExamplePager extends BaseComponent
{
    private const PER_PAGE = 3;
    private const ITEMS = ['Africa', 'Hold the Line', 'Rosanna', 'Georgy Porgy', 'Pamela', 'Stop Loving You', 'I Won\'t Hold You Back'];

    public function render(): Element
    {
        [$page, $setPage] = $this->useState(1);

        $last = (int) ceil(count(self::ITEMS) / self::PER_PAGE);
        // 状態はクライアント入力なので、範囲に収めてから使う
        $current = max(1, min($last, is_int($page) ? $page : 1));
        $items = array_slice(self::ITEMS, ($current - 1) * self::PER_PAGE, self::PER_PAGE);

        return H::div(children: [
            H::ul(children: array_map(fn(string $item): Element => H::li(children: $item), $items)),
            H::button(disabled: $current === 1, onClick: fn() => $setPage($current - 1), children: 'Previous'),
            ' ',
            H::span(id: 'page', children: "Page {$current} of {$last}"),
            ' ',
            H::button(disabled: $current === $last, onClick: fn() => $setPage($current + 1), children: 'Next'),
        ]);
    }
}

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(ExamplePager::class);

$request = Request::fromGlobals();
$behavior = SnapshotBehavior::Persistent;

$response = $app->actions()->handle($request, replay: fn(bool $partial): string => $app->render(ExamplePager::class), behavior: $behavior);
if ($response !== null) {
    $response->send();

    return;
}

// GET の描画の前に、URL のスナップショットを読み込む
$app->restoreSnapshots($request, $behavior);

echo example_layout('Shareable URL', $app->render(ExamplePager::class));
