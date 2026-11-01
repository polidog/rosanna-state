<?php

declare(strict_types=1);

// 組み込みサーバーで動かす host。パスの先頭で場面を選ぶ

use Polidog\Rosanna\Component\BaseComponent;
use Polidog\Rosanna\Component\Component;
use Polidog\Rosanna\Component\Defer;
use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Http\Request;
use Polidog\Rosanna\Rosanna;
use Polidog\Rosanna\Runtime\ComponentState;
use Polidog\Rosanna\Runtime\Element;

use function Polidog\Rosanna\Runtime\fc;

use Polidog\Rosanna\Runtime\RenderContext;

use function Polidog\Rosanna\Runtime\useFormAction;
use function Polidog\Rosanna\Runtime\useState;

use Polidog\Rosanna\Snapshot\SnapshotBehavior;
use Polidog\Rosanna\Storage\StorageType;

require __DIR__ . '/../../../vendor/autoload.php';

ini_set('display_errors', '0');

const FIXTURE_SECRET = 'integration-secret';

#[Component(name: 'Counter')]
final class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);

        return H::div(children: [H::span(children: 'count=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
    }
}

#[Component(name: 'SnapshotCounter', storage: StorageType::Snapshot)]
final class SnapshotCounter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);

        return H::div(children: [H::span(children: 'count=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
    }
}

#[Component(name: 'Deferred')]
#[Defer('class-panel', cacheControl: 'public, max-age=60')]
final class DeferredClass extends BaseComponent
{
    public function render(): Element
    {
        [$count, $set] = $this->useState(0);

        return H::div(children: [H::span(children: 'class=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
    }
}

$request = Request::fromGlobals();
$segments = explode('/', ltrim($request->path, '/'));
$scene = $segments[0];

/** fc() のカウンター。ID はファイルと行で決まるのでクロージャごとに分ける */
$sessionCounter = static fn(string $key) => fc(static function (array $props): Element {
    [$count, $set] = useState(0);

    return H::div(children: [H::span(children: 'count=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
}, key: $key);

switch ($scene) {
    // RT-12.7、02 の 7 節の素の PHP の例そのまま
    case 'readme':
        $app = new Rosanna();
        $app->register(Counter::class);
        $request = Request::fromGlobals();
        $response = $app->actions()->handle($request, replay: fn() => $app->render(Counter::class));
        if ($response !== null) {
            $response->send();

            return;
        }
        echo $app->render(Counter::class);

        return;

    case 'secret':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $app->register(Counter::class);
        $response = $app->actions()->handle($request, replay: fn() => $app->render(Counter::class));
        if ($response !== null) {
            $response->send();

            return;
        }
        echo $app->render(Counter::class);

        return;

        // RT-12.8。fc() を host が自分のレイアウトと組み合わせる形
    case 'fc':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $left = $sessionCounter('left');
        $right = $sessionCounter('right');
        $page = static fn(): Element => H::main(children: [H::h1(children: 'layout'), $left(), $right()]);
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $page());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($page()));

        return;

    case 'form':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $form = fc(static function (): Element {
            [$title, $setTitle] = useState('initial');

            return H::form(onSubmit: useFormAction(['title' => $setTitle]), children: [H::input(name: 'title', value: $title), H::p(children: 'title=' . $title)]);
        }, key: 'form');
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $form());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($form()));

        return;

        // #80 と #87。一覧全体を 1 つの state にする
    case 'list':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $list = fc(static function (): Element {
            $initial = [];
            for ($i = 1; $i <= 12; $i++) {
                $initial[] = ['id' => $i, 'title' => 'row number ' . $i, 'on' => false];
            }
            [$rows, $set] = useState($initial);
            $buttons = [];
            foreach ($rows as $i => $row) {
                $buttons[] = H::button(onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    $next[$i]['on'] = !$next[$i]['on'];

                    return $set($next);
                }, children: $row['id'] . ($row['on'] ? ':on' : ':off'));
            }

            return H::div(children: $buttons);
        }, key: 'rows');
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $list());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($list()));

        return;

        // AC-18 Memory の差分は基準を毎回運ぶ
    case 'list-memory':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $list = fc(static function (): Element {
            $initial = [];
            for ($i = 1; $i <= 12; $i++) {
                $initial[] = ['id' => $i, 'title' => 'row number ' . $i, 'on' => false];
            }
            [$rows, $set] = useState($initial);
            $buttons = [];
            foreach ($rows as $i => $row) {
                $buttons[] = H::button(onClick: static function () use ($rows, $i, $set) {
                    $next = $rows;
                    $next[$i]['on'] = !$next[$i]['on'];

                    return $set($next);
                }, children: $row['id'] . ($row['on'] ? ':on' : ':off'));
            }

            return H::div(children: $buttons);
        }, key: 'rows', storageType: StorageType::Memory);
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $list());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($list()));

        return;

        // HD-6 完成した HTML を返す replay の Session
    case 'string-replay':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $counter = $sessionCounter('string-replay');
        $hidden = ($_GET['hidden'] ?? '') === '1';
        $page = static fn(): string => '<main>' . ($hidden ? 'hidden' : $app->renderElement($counter())) . '</main>';
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): string => $page());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), $page);

        return;

        // VR-4 の例外 Session と Snapshot が同じページにある
    case 'mixed-storage':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $session = $sessionCounter('mixed-session');
        $snapshot = fc(static function (): Element {
            [$count, $set] = useState(0);

            return H::div(children: [H::span(children: 'snapshot=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
        }, key: 'mixed-snapshot', storageType: StorageType::Snapshot);
        $page = static fn(): Element => H::main(children: [$session(), $snapshot()]);
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $page());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($page()));

        return;

        // 秘密鍵なし (SG-2〜SG-4)
    case 'nosecret':
        $app = new Rosanna();
        $counter = $sessionCounter('c');
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $counter());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($counter()));

        return;

        // SG-5、SG-6 host がページの権限を handle() の前に確かめる
    case 'guarded':
        if (($request->cookies['auth'] ?? '') !== 'yes') {
            http_response_code(401);
            echo 'login required';

            return;
        }
        // no break
    case 'open':
        $app = deferredApp($sessionCounter);
        $page = static fn(): Element => H::section(children: ['page', H::defer('counter')]);
        $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $page());
        if ($response !== null) {
            $response->send();

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($page()));

        return;

    case 'snapshot':
        // /snapshot/{isolated|persistent|session}
        $behavior = SnapshotBehavior::from($segments[1] ?? 'isolated');
        // HD-14 host がすでに出力していてセッションを始められない場合
        if (isset($_GET['early'])) {
            echo 'early';
            flush();
        }
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET)->register(SnapshotCounter::class);
        $response = $app->actions()->handle($request, replay: fn() => $app->render(SnapshotCounter::class), behavior: $behavior);
        if ($response !== null) {
            $response->send();

            return;
        }
        $app->restoreSnapshots($request, $behavior);
        echo $app->render(SnapshotCounter::class);

        return;

        // SN-24 別のセッションの署名や壊れた値を実際のセッションに置く
    case 'plant':
        session_start();
        $value = $_GET['value'] ?? '';
        $_SESSION['_rosanna_snapshot'] = ['SnapshotCounter#0' => $value];
        echo 'planted';

        return;

    case 'sign-for-session':
        // このセッションの文脈で署名した値を返す
        session_start();
        $serializer = new Polidog\Rosanna\Snapshot\SnapshotSerializer(FIXTURE_SECRET, (string) session_id());
        echo $serializer->serialize(new Polidog\Rosanna\Runtime\Snapshot('SnapshotCounter', '0', [50], version: 3));

        return;

        // RQ-2 実際のサーバーで handle() の前後を測る
    case 'probe':
        $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
        $counter = fc(static function (): Element {
            [$count, $set] = useState(0);

            return H::button(onClick: static fn() => $set($count + 1), children: 'n=' . $count);
        }, key: 'probe', storageType: StorageType::Memory);
        if ($request->method === 'POST') {
            $before = headers_list();
            $code = http_response_code();
            ob_start();
            $response = $app->actions()->handle($request, replay: static fn(bool $partial): Element => $counter());
            $printed = (string) ob_get_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status' => $response?->status,
                'headersBefore' => $before,
                'headersAfter' => array_values(array_filter(headers_list(), static fn(string $h): bool => stripos($h, 'content-type') !== 0)),
                'codeBefore' => $code,
                'codeAfter' => http_response_code(),
                'printed' => $printed,
                'body' => $response?->body,
            ]);

            return;
        }
        echo RenderContext::run($app->scope($request), static fn(): string => $app->renderElement($counter()));

        return;

        // DF-9 host がすでに出力した後のエンドポイント
    case 'defer-after-output':
        session_start();
        echo 'early';
        flush();
        $response = deferredApp($sessionCounter)->handleDeferred(new Request('GET', '/_defer/public-plain', '/_defer/public-plain', ''));
        echo '|' . ($response?->headers['Cache-Control'] ?? 'none');

        return;

    case '_defer':
        $limiter = $_GET['limiter'] ?? null;
        if (is_string($limiter)) {
            ini_set('session.cache_limiter', $limiter);
        }
        $response = deferredApp($sessionCounter)->handleDeferred($request);
        if ($response === null) {
            http_response_code(599);
            echo 'not handled';

            return;
        }
        $response->send();

        return;
}

http_response_code(404);
echo 'no scene';

/** @param callable(string): Polidog\Rosanna\Runtime\FunctionComponent $sessionCounter */
function deferredApp(callable $sessionCounter): Rosanna
{
    $app = new Rosanna()->setSnapshotSecret(FIXTURE_SECRET);
    $app->register(DeferredClass::class);

    $publicWithSession = fc(static function (): Element {
        [$count] = useState(0);
        ComponentState::current()?->setState(0, $count);

        return H::p(children: 'with-session');
    }, key: 'public-session', defer: new Defer('public-session'));
    $publicReadsOnly = fc(static function (): Element {
        [$count] = useState(7);

        return H::p(children: 'count=' . $count);
    }, key: 'public-read', defer: new Defer('public-read'));
    $publicWithCookie = fc(static function (): Element {
        setcookie('flavor', 'x');

        return H::p(children: 'with-cookie');
    }, key: 'public-cookie', storageType: StorageType::Memory, defer: new Defer('public-cookie'));
    $publicPlain = fc(static fn(): Element => H::p(children: 'plain'), key: 'public-plain', storageType: StorageType::Memory, defer: new Defer('public-plain'));
    $publicForm = fc(static function (): Element {
        [$count, $set] = useState(0);

        return H::button(onClick: static fn() => $set($count + 1), children: 'n=' . $count);
    }, key: 'public-form', storageType: StorageType::Memory, defer: new Defer('public-form'));
    $counter = fc(static function (): Element {
        [$count, $set] = useState(0);

        return H::div(children: [H::span(children: 'deferred=' . $count), H::button(onClick: static fn() => $set($count + 1), children: '+1')]);
    }, key: 'counter', defer: new Defer('counter'));

    foreach ([
        'public-session' => [$publicWithSession, 'public, max-age=60'],
        'public-read' => [$publicReadsOnly, 'public, max-age=60'],
        'public-cookie' => [$publicWithCookie, 'public, max-age=60'],
        'public-plain' => [$publicPlain, 'public, max-age=60'],
        'public-form' => [$publicForm, 'public, max-age=60'],
        'private-session' => [fc(static function (): Element {
            ComponentState::current()?->setState(0, 1);

            return H::p(children: 'private');
        }, key: 'private-session', defer: new Defer('private-session')), 'private, max-age=0'],
        'counter' => [$counter, null],
    ] as $name => [$component, $cacheControl]) {
        $app->registerComponent('App\\' . $name, $component);
        $app->registerDeferred($name, 'App\\' . $name, $cacheControl);
    }

    return $app;
}
