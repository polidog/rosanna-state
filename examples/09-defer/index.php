<?php

declare(strict_types=1);

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useState;

require_once __DIR__ . '/../layout.php';

// 全員に同じ内容なので、CDN とブラウザにキャッシュさせる
#[Component(name: 'ExampleAnnouncement', storage: 'memory')]
#[Defer(name: 'example-announcement', cacheControl: 'public, s-maxage=60', localCache: true, localCacheTtl: 60)]
final class ExampleAnnouncement extends BaseComponent
{
    public function render(): Element
    {
        return H::p(id: 'announcement', children: 'Free shipping this week.');
    }
}

// 訪問者ごとの内容はキャッシュさせない。/_defer/{名前} は host の保護の対象外の公開の GET で、
// クエリも信頼しない入力なので、中で確かめてから使う
$Likes = fc(function (array $props): Element {
    [$likes, $setLikes] = useState(0);
    $title = mb_substr((string) ($props['title'] ?? ''), 0, 40);

    return H::div(className: 'card', children: [
        H::p(id: 'likes', children: "{$title}: {$likes} likes"),
        H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
    ]);
}, key: 'example-likes', defer: new Defer(name: 'example-likes', cacheControl: 'private, no-store'));

$app = new Rosanna();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(ExampleAnnouncement::class);
$app->registerComponent('App\\ExampleLikes', $Likes);
$app->registerDeferred('example-likes', 'App\\ExampleLikes', 'private, no-store');

$request = Request::fromGlobals();

$deferred = $app->handleDeferred($request);
if ($deferred !== null) {
    $deferred->send();

    return;
}

// fallback と key 以外の props は、クエリとしてエンドポイントに届く
$page = fn(): Element => H::div(children: [
    H::defer('example-announcement', fallback: H::p(children: 'Loading the announcement...'), localCache: true, localCacheTtl: 60),
    H::p(children: 'This part of the page is the same for every visitor.'),
    $Likes(['title' => 'Rosanna', 'fallback' => H::p(children: 'Loading likes...')]),
]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo example_layout('Defer', $body);
