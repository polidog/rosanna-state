<?php

declare(strict_types=1);

// `php -S` のルータースクリプト。実際の Rosanna でページを出す。パスの先頭で場面を選ぶ

use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\FunctionComponent;
use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Storage\SnapshotPersist;
use Polidog\Rosanna\Storage\StorageType;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '0');

/** JS-25 クラスの setter は action に storageType を入れない (RT-5.3) */
#[Polidog\Rosanna\Component\Component(name: 'ClassCounter')]
final class ClassCounter extends Polidog\Rosanna\Component\BaseComponent
{
    public function render(): Element
    {
        [$n, $set] = $this->useState(0);

        return H::div(children: [H::span(className: 'n', children: (string) $n), H::button(onClick: static fn() => $set($n + 1), children: '+1')]);
    }
}

/** JS-13 の「鍵の変更」は Cookie で切り替える */
function newApp(Request $request): Rosanna
{
    $secret = $request->cookies['fixture_secret'] ?? 'browser-fixture-secret';

    return new Rosanna()->setSnapshotSecret(is_string($secret) ? $secret : 'browser-fixture-secret');
}

function storageFrom(string $name): StorageType
{
    return StorageType::from($name);
}

/** @param \Closure(): Element $page */
function serve(Rosanna $app, Request $request, \Closure $page, SnapshotBehavior $behavior = SnapshotBehavior::Isolated, string $tail = ''): void
{
    $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $page(), behavior: $behavior);
    if ($response !== null) {
        $response->send();

        return;
    }
    $app->restoreSnapshots($request, $behavior);

    $nonce = is_string($_GET['nonce'] ?? null) ? $_GET['nonce'] : null;
    if ($nonce !== null) {
        header("Content-Security-Policy: script-src 'nonce-{$nonce}'");
    }
    $src = is_string($_GET['src'] ?? null) ? $_GET['src'] : '/rosanna.js';
    // セッションを読むので出力より先に描画する
    $body = RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($page()));
    echo '<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>', Rosanna::renderClientScript($src, null, $nonce), '</head><body>', $body, $tail, '</body></html>';
}

/**
 * 固定値のボタン 2 つ、button 以外の要素、自分のフォーム、入力ごとに送る欄を持つコンポーネント
 *
 * @param list<FunctionComponent> $children
 */
function widget(string $key, StorageType $storage, ?SnapshotPersist $persist = null, array $children = []): FunctionComponent
{
    return fc(static function () use ($children): Element {
        [$n, $setN] = useState(0);
        [$name, $setName] = useState('');
        [$live, $setLive] = useState('');

        return H::div(className: 'widget', children: [
            H::span(className: 'n', children: (string) $n),
            H::span(className: 'shown-name', children: $name),
            H::span(className: 'shown-live', children: $live),
            H::button(className: 'inc', onClick: static fn() => $setN($n + 1), children: '+1'),
            H::button(className: 'add10', onClick: static fn() => $setN($n + 10), children: '+10'),
            H::div(className: 'row', onClick: static fn() => $setN($n + 100), children: ['row ', H::a(className: 'inner', href: '#inner', children: 'link')]),
            H::input(className: 'free', name: 'free'),
            H::form(className: 'own', onSubmit: useFormAction(['name' => $setName]), children: [
                H::input(className: 'name-field', name: 'name', value: $name),
                H::button(className: 'save', children: 'save'),
            ]),
            H::input(className: 'live', name: 'live', value: $live, onInput: useFormAction(['live' => $setLive])),
            array_map(static fn(FunctionComponent $child): Element => $child(), $children),
        ]);
    }, key: $key, storageType: $storage, persist: $persist);
}

/** @param array<string, string> $attrs */
function tiny(string $key, StorageType $storage = StorageType::Session, array $attrs = []): FunctionComponent
{
    return fc(static function () use ($attrs): Element {
        [$n, $set] = useState(0);

        return H::span(className: 'tiny', children: [
            H::span(className: 'n', children: (string) $n),
            H::button(...$attrs, className: 'inc', onClick: static fn() => $set($n + 1), children: '+1'),
        ]);
    }, key: $key, storageType: $storage);
}

/** #80、#87 一覧全体を 1 つの state にする */
function rows(string $key, StorageType $storage): FunctionComponent
{
    return fc(static function (): Element {
        $initial = [];
        for ($i = 1; $i <= 12; $i++) {
            $initial[] = ['id' => $i, 'on' => false];
        }
        [$rows, $set] = useState($initial);
        $items = [];
        foreach ($rows as $i => $row) {
            $items[] = H::li(children: [
                H::button(className: 'toggle', onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    $next[$i]['on'] = !$next[$i]['on'];

                    return $set($next);
                }, children: $row['id'] . ($row['on'] ? ':on' : ':off')),
                H::button(className: 'remove', onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    array_splice($next, $i, 1);

                    return $set($next);
                }, children: 'remove ' . $row['id']),
            ]);
        }

        return H::ul(children: $items);
    }, key: $key, storageType: $storage);
}

function eventsComponent(): FunctionComponent
{
    return fc(static function (): Element {
        [$clicks, $setClicks] = useState(0);
        [$rowHits, $setRowHits] = useState(0);
        [$color, $setColor] = useState('red');
        [$agree, $setAgree] = useState('');
        [$title, $setTitle] = useState('');
        [$first, $setFirst] = useState('');
        [$second, $setSecond] = useState('');

        $bump = static fn() => $setClicks($clicks + 1);

        return H::div(children: [
            H::span(id: 'clicks', children: (string) $clicks),
            H::span(id: 'row-hits', children: (string) $rowHits),
            H::span(id: 'color', children: $color),
            H::span(id: 'agree', children: $agree === '' ? 'empty' : (string) $agree),
            H::span(id: 'title', children: $title),
            H::span(id: 'fields', children: $first . '|' . $second),
            H::button(id: 'by-button', onClick: $bump, children: 'button'),
            H::span(id: 'by-span', onClick: $bump, children: 'span'),
            H::a(id: 'by-link', href: '/other', onClick: $bump, children: 'link'),
            H::div(id: 'clickable-row', onClick: static fn() => $setRowHits($rowHits + 1), children: [
                H::button(id: 'in-row', onClick: $bump, children: 'in row'),
                H::a(id: 'plain-in-row', href: '#plain', children: 'plain'),
            ]),
            H::select(id: 'pick', name: 'color', onChange: useFormAction(['color' => $setColor]), children: [
                H::option(value: 'red', selected: $color === 'red', children: 'red'),
                H::option(value: 'blue', selected: $color === 'blue', children: 'blue'),
            ]),
            H::input(id: 'check', type: 'checkbox', name: 'agree', value: 'yes', checked: $agree !== '', onChange: useFormAction(['agree' => $setAgree])),
            H::input(id: 'text', name: 'title', value: $title, onChange: useFormAction(['title' => $setTitle])),
            H::input(id: 'next-field'),
            H::input(id: 'typed', name: 'typed', onInput: useFormAction(['typed' => $setTitle])),
            H::form(id: 'multi', onSubmit: useFormAction(['first' => $setFirst, 'second' => $setSecond]), children: [
                H::input(id: 'first', name: 'first', value: $first),
                H::input(id: 'second', name: 'second', value: $second),
                H::button(id: 'multi-send', children: 'send'),
            ]),
        ]);
    }, key: 'events', storageType: StorageType::Session);
}

function morphComponent(): FunctionComponent
{
    return fc(static function (): Element {
        [$phase, $setPhase] = useState(0);
        [$last, $setLast] = useState('');
        $order = $phase % 2 === 1 ? ['b', 'a'] : ['a', 'b'];
        $next = static fn() => $setPhase($phase + 1);

        return H::div(children: [
            H::span(id: 'phase', children: (string) $phase),
            H::span(id: 'last', children: $last),
            H::button(id: 'next', onClick: $next, children: 'next'),
            $phase % 2 === 1 ? H::p(id: 'ahead-marker', className: 'ahead', children: 'ahead') : null,
            H::input(className: 'after-ahead', name: 'after-ahead'),
            H::input(id: 'kept', name: 'kept'),
            H::input(id: 'server', name: 'server', value: 'server-' . $phase),
            H::input(id: 'unsent-check', type: 'checkbox', name: 'unsent-check'),
            H::input(id: 'server-check', type: 'checkbox', name: 'server-check', checked: $phase > 0),
            H::select(id: 'unsent-select', name: 'unsent-select', children: [
                H::option(value: 'x', children: 'x'),
                H::option(value: 'y', children: 'y'),
            ]),
            H::form(id: 'sent-form', onSubmit: useFormAction(['last' => $setLast]), children: [
                H::input(id: 'sent', name: 'last', value: ''),
                H::button(id: 'send', children: 'send'),
            ]),
            H::ul(id: 'ordered', children: array_map(
                static fn(string $id): Element => H::li(id: 'row-' . $id, children: H::input(className: 'row-input', name: 'row-' . $id)),
                $order,
            )),
            $phase === 0
                ? H::button(id: 'vanish', onClick: $next, children: 'vanish')
                : H::button(id: 'took-place', onClick: $next, children: 'took place'),
            $phase > 0 ? H::script(children: 'window.__scriptRan = true;') : null,
        ]);
    }, key: 'morph', storageType: StorageType::Session);
}

function deferApp(Request $request): Rosanna
{
    $app = newApp($request);
    $plain = fc(static fn(): Element => H::p(id: 'plain', children: 'plain'), key: 'plain', storageType: StorageType::Memory, defer: new Defer('plain'));
    $withForm = fc(static function (): Element {
        [$n, $set] = useState(0);

        return H::button(id: 'with-form', onClick: static fn() => $set($n + 1), children: 'n=' . $n);
    }, key: 'with-form', storageType: StorageType::Memory, defer: new Defer('with-form'));
    $counter = fc(static function (): Element {
        [$n, $set] = useState(0);

        return H::div(id: 'deferred-counter', children: [
            H::span(className: 'n', children: (string) $n),
            H::button(className: 'inc', onClick: static fn() => $set($n + 1), children: '+1'),
            H::input(id: 'deferred-input', name: 'deferred-input'),
        ]);
    }, key: 'counter', defer: new Defer('counter'));
    $snapshot = fc(static function (): Element {
        [$n, $set] = useState(0);

        return H::div(id: 'deferred-snapshot', children: [
            H::span(className: 'n', children: (string) $n),
            H::button(className: 'inc', onClick: static fn() => $set($n + 1), children: '+1'),
        ]);
    }, key: 'snap', storageType: StorageType::Snapshot, defer: new Defer('snap'));

    $plain2 = fc(static function (): Element {
        [$n, $set] = useState(0);

        return H::button(id: 'nested-defer', onClick: static fn() => $set($n + 1), children: 'nested=' . $n);
    }, key: 'plain2', defer: new Defer('plain2'));

    foreach (['plain' => $plain, 'with-form' => $withForm, 'counter' => $counter, 'snap' => $snapshot, 'plain2' => $plain2] as $name => $component) {
        $app->registerComponent('Fixture\\' . $name, $component);
        $app->registerDeferred($name, 'Fixture\\' . $name);
    }

    return $app;
}

/** @return array<string, Element> 01 の決定の記録の 8 */
function svgCases(): array
{
    $js = 'javascript:window.__pwned=true';
    $link = static fn(Element $animation, array $svg = [], array $a = []): Element => H::svg(...[...$svg, 'width' => '300', 'height' => '60'], children: H::a(...$a, children: [
        H::text(...['id' => 'target', 'x' => '10', 'y' => '30', 'font-size' => '24', 'children' => 'click']),
        $animation,
    ]));
    $xlink = ['xmlns:xlink' => 'http://www.w3.org/1999/xlink'];

    return [
        'set-to' => $link(H::set(attributeName: 'href', to: $js)),
        'set-to-tab' => $link(H::set(attributeName: 'href', to: "java\tscript:window.__pwned=true")),
        'set-uppercase-tag' => $link(H::SET(attributeName: 'href', to: $js)),
        'set-lowercase-name' => $link(H::set(attributename: 'href', to: $js)),
        'set-xlink' => $link(H::set(attributeName: 'xlink:href', to: $js), $xlink),
        'set-over-safe-href' => $link(H::set(attributeName: 'href', to: $js), [], ['href' => '/other']),
        'animate-values' => $link(H::animate(attributeName: 'href', values: $js, dur: '100s')),
        'animate-second-value' => $link(H::animate(attributeName: 'href', values: '/other;' . $js, keyTimes: '0;0.0001', dur: '1s', calcMode: 'discrete', fill: 'freeze')),
        'animate-to' => $link(H::animate(attributeName: 'href', to: $js, dur: '100s', fill: 'freeze')),
        'animate-from' => $link(H::animate(attributeName: 'href', from: $js, to: '/other', dur: '1000s')),
        'animate-by' => $link(H::animate(attributeName: 'href', by: $js, dur: '100s', fill: 'freeze')),
        'animate-xlink' => $link(H::animate(attributeName: 'xlink:href', values: $js, dur: '100s'), $xlink),
        'set-targets-by-href' => H::svg(...['width' => '300', 'height' => '60'], children: [
            H::a(id: 'l', children: H::text(...['id' => 'target', 'x' => '10', 'y' => '30', 'font-size' => '24', 'children' => 'click'])),
            H::set(href: '#l', attributeName: 'href', to: $js),
        ]),
        // 対照: 無害な値のアニメーションは残り、クリックで移動する
        'safe' => $link(H::set(attributeName: 'href', to: '/other')),
    ];
}

$request = Request::fromGlobals();
$segments = explode('/', ltrim($request->path, '/'));
$scene = $segments[0];
$arg = $segments[1] ?? '';

switch ($scene) {
    case 'rosanna.js':
        header('Content-Type: text/javascript');
        readfile(__DIR__ . '/../../../public/rosanna.js');

        return;

    case 'other':
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><body><p id="other">other</p></body></html>';

        return;

    case 'widget':
        $app = newApp($request);
        $widget = widget('w', storageFrom($arg));
        serve($app, $request, static fn(): Element => H::main(children: $widget()));

        return;

    case 'siblings':
        $app = newApp($request);
        $a = widget('a', storageFrom($arg));
        $b = widget('b', storageFrom($arg));
        serve($app, $request, static fn(): Element => H::main(children: [$a(), $b()]));

        return;

        // 秘密鍵なし。署名の鍵のために描画でセッションが始まる (SG-3)
    case 'nosecret':
        $app = new Rosanna();
        $a = widget('a', StorageType::Session);
        $b = widget('b', StorageType::Session);
        serve($app, $request, static fn(): Element => H::main(children: [$a(), $b()]));

        return;

    case 'nest':
        $app = newApp($request);
        $parent = widget('parent', storageFrom($arg), null, [widget('child', storageFrom($arg))]);
        serve($app, $request, static fn(): Element => H::main(children: $parent()));

        return;

    case 'list':
        $app = newApp($request);
        $list = rows('rows', storageFrom($arg));
        serve($app, $request, static fn(): Element => H::main(children: $list()));

        return;

    case 'persist':
        $app = newApp($request);
        $widget = widget('p', StorageType::Snapshot, SnapshotPersist::from($arg));
        serve($app, $request, static fn(): Element => H::main(children: $widget()));

        return;

        // HD-16 Session と Snapshot が混在するページ。snap の persist を選べる
    case 'mixed':
        $app = newApp($request);
        $session = widget('s', StorageType::Session);
        $snap = widget('snap', StorageType::Snapshot, $arg === '' ? null : SnapshotPersist::from($arg));
        $parent = widget('parent', StorageType::Snapshot, null, [widget('child', StorageType::Snapshot)]);
        serve($app, $request, static fn(): Element => H::main(children: [$session(), $snap(), $parent()]));

        return;

    case 'class-session':
        $app = newApp($request)->register(ClassCounter::class);
        $response = $app->actions()->handle($request, replay: static fn(): string => '');
        if ($response !== null) {
            $response->send();

            return;
        }
        $body = $app->render(ClassCounter::class, key: 'a') . $app->render(ClassCounter::class, key: 'b');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>fixture</title>', Rosanna::renderClientScript('/rosanna.js'), '</head><body>', $body, '</body></html>';

        return;

        // JS-23 defer の中のフォーム。外の Session のボタンで始める
    case 'defer-start':
        $app = deferApp($request);
        $starter = tiny('starter');
        $host = fc(static function (): Element {
            [$n, $set] = useState(0);

            return H::div(className: 'host-widget', children: [
                H::span(className: 'n', children: (string) $n),
                H::button(className: 'inc', onClick: static fn() => $set($n + 1), children: '+1'),
                H::defer('plain2', fallback: H::i(children: 'fallback')),
            ]);
        }, key: 'host-widget');
        serve($app, $request, static fn(): Element => H::main(children: [
            $starter(),
            H::defer('with-form'),
            H::defer('counter', reloadable: true),
            H::defer('snap'),
            H::defer('plain'),
            $host(),
        ]));

        return;

        // JS-4 サーバーの 303 に従う部分更新
    case 'behavior':
        $app = newApp($request);
        $widget = widget('w', StorageType::Snapshot);
        serve($app, $request, static fn(): Element => H::main(children: $widget()), SnapshotBehavior::from($arg));

        return;

        // JS-4 host かプロキシが action に別の場所への redirect で答える
    case 'away':
        if ($request->method === 'POST') {
            $port = $_SERVER['SERVER_PORT'];
            header(match ($arg) {
                'cross' => "Location: http://localhost:{$port}/away-target",
                'cross-cors' => "Location: http://localhost:{$port}/away-target/cors",
                default => 'Location: /other',
            }, true, 303);

            return;
        }
        $app = newApp($request);
        $widget = widget('w', StorageType::Session);
        serve($app, $request, static fn(): Element => H::main(children: $widget()));

        return;

    case 'away-target':
        // cors のときだけ CORS を通して、fetch に別オリジンの response.redirected を見せる
        if ($arg === 'cors') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: X-Rosanna-Partial');
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<p id="stolen">stolen</p>';

        return;

        // JS-5 HD-8 replay の例外は host に伝わり、500 になる
    case 'boom':
        $app = newApp($request);
        $widget = widget('w', StorageType::Session);
        serve($app, $request, static function () use ($widget, $request): Element {
            if ($request->method === 'POST' && ($request->cookies['fixture_explode'] ?? '') === '1') {
                throw new RuntimeException('fixture-boom');
            }

            return H::main(children: $widget());
        });

        return;

    case 'events':
        $app = newApp($request);
        $component = eventsComponent();
        serve($app, $request, static fn(): Element => H::main(children: $component()));

        return;

    case 'morph':
        $app = newApp($request);
        $component = morphComponent();
        serve($app, $request, static fn(): Element => H::main(children: $component()));

        return;

        // RT-4.5 アプリの form、p、table の中のコンポーネント
    case 'markup':
        $app = newApp($request);
        $inForm = tiny('in-form', storageFrom($arg));
        $cells = fc(static function (): Element {
            [$n, $set] = useState(0);
            $bump = static fn() => $set($n + 1);

            return H::div(className: 'cells', children: [
                H::span(className: 'n', children: (string) $n),
                H::p(id: 'para', children: H::button(id: 'in-para', onClick: $bump, children: '+1')),
                H::table(children: H::tbody(id: 'body', children: H::tr(id: 'row', children: H::td(children: H::button(id: 'in-cell', onClick: $bump, children: '+1'))))),
            ]);
        }, key: 'cells', storageType: storageFrom($arg));
        serve($app, $request, static fn(): Element => H::main(children: [
            H::form(id: 'host', method: 'get', action: '/other', children: [
                H::input(id: 'host-field', name: 'q'),
                $inForm(),
                H::button(id: 'host-submit', children: 'search'),
            ]),
            $cells(),
        ]));

        return;

    case 'defer':
        $app = deferApp($request);
        $reloader = tiny('reloader', StorageType::Session, ['data-rosanna-reload-defer' => 'counter']);
        serve($app, $request, static fn(): Element => H::main(children: [
            H::defer('plain', localCache: true),
            H::defer('with-form', localCache: true),
            H::defer('counter', reloadable: true),
            H::defer('snap'),
            H::button(...['data-rosanna-reload-defer' => 'counter'], id: 'reload-counter', type: 'button', children: 'reload'),
            $reloader(),
        ]), tail: '<div id="cross" data-rosanna-defer-url="http://localhost:' . $_SERVER['SERVER_PORT'] . '/_defer/plain"><i>fallback</i></div>'
            . '<div id="outside" data-rosanna-defer-url="/other"><i>fallback</i></div>'
            . '<div id="json" data-rosanna-defer-url="/_defer/json"><i>fallback</i></div>');

        return;

    case '_defer':
        if ($arg === 'json') {
            header('Content-Type: application/json');
            echo '<b class="evil">json</b>';

            return;
        }
        // 別オリジンの読み込み (JS-16) を確かめるのに CORS の拒否で止まらないようにする
        header('Access-Control-Allow-Origin: *');
        $response = deferApp($request)->handleDeferred($request);
        if ($response === null) {
            http_response_code(599);

            return;
        }
        $response->send();

        return;

    case 'svg':
        $cases = svgCases();
        if ($arg === 'control') {
            // 対照: DOM で href を書き換えると同じクリックでスクリプトが動く。観察の方法が正しい証拠
            echo '<!doctype html><html><body><svg width="300" height="60"><a id="l"><text id="target" x="10" y="30" font-size="24">click</text></a></svg>'
                . '<script>document.getElementById("l").setAttribute("href", "javascript:window.__pwned=true")</script></body></html>';

            return;
        }
        if ($arg === 'list') {
            header('Content-Type: application/json');
            echo json_encode(array_keys($cases));

            return;
        }
        $app = newApp($request);
        echo '<!doctype html><html><body>', $app->renderElement($cases[$arg]), '</body></html>';

        return;
}

http_response_code(404);
echo 'no scene';
