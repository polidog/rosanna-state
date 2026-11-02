# Rosanna State

[Rosanna](https://github.com/polidog/rosanna) のサーバー側の状態を担うパッケージです。名前は同じく TOTO の曲 "Rosanna" から取りました。Jeff Porcaro のシャッフルで有名な曲です。

[English](README.md)

`polidog/rosanna-state` は、Rosanna の描画に React Hooks の形のコンポーネントを足します。状態はサーバーが持ち、ボタンとフォームは JavaScript なしでも動きます。`rosanna.js` を読み込むと部分更新になります。

このパッケージが持つのは hooks (`fc()`、`useState` など)、action、署名、CSRF、Snapshot、Storage、`rosanna.js` です。defer の処理は依存する [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer) にあります。`H`、`Element`、`Renderer`、PSX のコンパイラは、このパッケージが依存する [`polidog/rosanna`](https://github.com/polidog/rosanna) にあります。描画だけのサイトにこのパッケージは要りません。

ルーターも DI もイベントも持ちません。URL からページを選ぶのは host (フレームワークか、ページごとの PHP ファイル) の役割です。`polidog/rosanna` と合わせて [usePHP](https://github.com/polidog/usePHP) の作り直しで、移行は[下の手順](#usephp-からの移行)のとおりです。

- PHP 8.5 以上と `session`、`mbstring` 拡張。`polidog/rosanna` は PSX のコンパイラのために `nikic/php-parser` を必要とします。描画では読み込みません。
- 現在の版は v0.0.1 です。Composer の `^0.0.1` は 0.0.1 だけに一致するので、`polidog/rosanna` とこのパッケージは一緒にリリースします。

## インストール

```bash
composer require polidog/rosanna-state
vendor/bin/rosanna-state publish   # public/rosanna.js と rosanna-defer.js を配置する
```

`composer install` のたびに配置するには `composer.json` に足します。

```json
{
    "scripts": {
        "post-install-cmd": ["Polidog\\Rosanna\\State\\Installer\\AssetInstaller::publish"],
        "post-update-cmd": ["Polidog\\Rosanna\\State\\Installer\\AssetInstaller::publish"]
    }
}
```

## 初期化

状態層の初期化は次の 2 つだけで、ほかには何も要りません。

1. `composer require polidog/rosanna-state`
2. `new RosannaState()`

bootstrap の呼び出しはありません。Composer の `autoload.files` が hooks を読み込み、状態層を `polidog/rosanna` につなぎます。

`RosannaState` は `Rosanna` の描画の API (`renderElement()`、`registerComponent()`、`loadComponentManifest()`、`setArgumentResolver()`) も持つので、アプリが持つのはこれ 1 つです。

## host に残る仕事

初期化だけでは、状態層はリクエストにつながりません。host は次の 4 つを行います。どれも[最小の例](#最小の例)に入っています。

| やること | 書き方 | ないとどうなるか |
|---|---|---|
| POST を状態層に渡す | `$app->actions()->handle($request, replay: ...)`。`null` でなければ応答を `send()` する | ボタンとフォームが何もしない |
| リクエストの scope の中で描画する | `RenderContext::run($app->scope($request), fn() => $app->renderElement(...))` | `renderElement()` は PHP のグローバルからスコープを自分で作るが、その前に呼んだ `fc()` にはスコープがない。Snapshot は `LogicException` になり、defer はプレースホルダーのままで、host が作った `Request` は使われない |
| JavaScript を配信する | ページに `RosannaState::renderClientScript()` を出し、`vendor/bin/rosanna-state publish` でファイルを置く | 部分更新にならず、defer は fallback のまま。`rosanna.js` が要る要素は何もしない |
| Snapshot を使うなら秘密鍵を設定する | `$app->setSnapshotSecret($secret)` ([鍵](#鍵)) | Snapshot のコンポーネントの描画が `LogicException` になる |

使うものによっては、次も host の仕事です。

- ページの処理より前に `$app->handleDeferred($request)` を呼び、`/_defer/*` をそこに届ける ([defer](#defer))
- `.psx` を `vendor/bin/rosanna compile` でなく `vendor/bin/rosanna-state compile` でコンパイルする ([PSX](#psx))
- worker ではリクエストの間で `RequestLifecycle::reset()` を呼ぶ ([worker モード](#worker-モード))
- `handle()` の前にページの権限を確かめる ([host が権限を確かめる](#host-が権限を確かめる))

## 最小の例

`fc()` で包んだ関数がコンポーネントで、`useState` が状態を持ちます。

<!-- readme-test: quickstart -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Counter = fc(function (array $props): Element {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return H::div(className: 'counter', children: [
        H::span(children: "Count: {$count}"),
        H::button(onClick: fn() => $setCount($count + 1), children: '+'),
    ]);
}, key: 'counter');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [$Counter(['initial' => 0])]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

$body = RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));

echo '<!doctype html><html><head><meta charset="utf-8">', RosannaState::renderClientScript(), '</head><body>';
echo $body;
echo '</body></html>';
```

```bash
ROSANNA_SNAPSHOT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 -t public
```

押すたびに `handle()` が POST を受けて状態を更新します。

- JavaScript なし: 303 で同じ URL に戻り、ページ全体を描き直します。
- `rosanna.js` あり: コンポーネントの断片だけを受け取って DOM に差分適用します。入力中の値、フォーカス、選択範囲は残ります。

`handle()` は Rosanna の action でなければ `null` を返します。ヘッダーも本文も送らないので (例外は PHP がセッション開始時に送る Cookie)、host が `send()` するかフレームワークの応答に変換します。**描画はいつも出力より前に済ませてください** ([出力の後に描画しない](#出力の後に描画しない))。

`$setCount($count + 1)` は「足す」ではなく、描画の時点で確定した値を「その値にする」action です。クロージャはクリック時でなく描画時に実行されます。

動かせるサンプルは [examples/](examples/README.ja.md) にあります。

### インスタンスの key

状態はインスタンス ID に属します。ID は `FC@<ファイル名>:<開始行>#<key>` の形で、URL は含みません。

<!-- readme-test: keys -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Task = fc(function (array $props): Element {
    [$likes, $setLikes] = useState(0);

    return H::div(children: [
        H::span(children: "{$props['title']}: {$likes}"),
        H::button(onClick: fn() => $setLikes($likes + 1), children: 'Like'),
    ]);
}, key: 'task');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [
    $Task(['key' => 'a', 'title' => 'first']),
    $Task(['key' => 'b', 'title' => 'second']),
]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- **`fc()` には key を渡すことを勧めます。** key がなければ同じ名前の中の出現順で区別するので、順番が変わると状態が入れ替わります。ID にはファイルのパスを含めないので、別のディレクトリの同名ファイルの同じ行にあるクロージャは同じ名前になり、key で区別するしかありません。
- 一覧の行やレコードごとの状態には props の `key` (文字列か int) を渡します。`fc()` の key と合わせて `task/a` になります。
- 同じ ID を 2 回描画すると状態を共有します。これは検出されません。
- クラスコンポーネントの中の key のない子は、そのインスタンスの中で数えます。ID は `FC@a.php:3#親の ID/0` の形で、親の分だけ長くなります (255 バイトの上限に注意してください)。
- コンポーネントはラッパーの `div` を持つので、`table`、`tbody`、`tr`、`select` などの直下には置けず、描画時に `RuntimeException` です。パーサーが `div` を外に出して部分更新が表を壊すためです。`td` の中に置くか、表全体を 1 つのコンポーネントにしてください。
- `fc()` を別の行に動かすと、クロージャの ID が変わって状態は最初からになります。
- 255 バイトを超える ID や制御文字を含む ID への action は 400 です。

## hooks

- `useState($initial)` は `[値, setter]` を返します。状態は null、スカラー、それらの配列だけです。オブジェクト、`NAN`、`INF`、512 段を超える入れ子は描画時に例外です。`$set(null)` は null を保存します。
- `useFormAction(['欄の名前' => $setter])` は送信された欄を hook に写します。
- `useEffect($callback, $deps)` は描画の中で同期的に実行します。`null` なら毎回、`[]` なら初回だけ、配列なら値が `!==` で変わったときです。`\Closure` を返すと cleanup になりますが、同じリクエストの中でしか動きません。

hooks をコンポーネントの外で呼ぶと `LogicException` です。hook は呼び出しの順で識別するので、条件分岐で順番を変えないでください。

### useEffect の配列 deps はセッションを始める

deps の配列は次のリクエストで比べるために保存します。Session のコンポーネントでは、**匿名の訪問者のページでも `useEffect` の配列 deps (`[]` を含む) がセッションと Cookie を始めます。** deps が `null` なら保存しないので始まりません。`useState` の読み取りだけではセッションは始まりません。CDN に載せたいページでは配列 deps を使わないか、Memory か Snapshot にしてください。

<!-- readme-test: effect -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useEffect;

require __DIR__ . '/../vendor/autoload.php';

$Article = fc(function (array $props): Element {
    useEffect(function () use ($props): void {
        error_log("viewed {$props['id']}");
    }, [$props['id']]);

    return H::p(children: "Article {$props['id']}");
}, key: 'article');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Article(['id' => 1])));
```

### フォーム入力

<!-- readme-test: form-input -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useFormAction;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$Profile = fc(function (): Element {
    [$name, $setName] = useState('');

    return H::form(onSubmit: useFormAction(['name' => $setName]), children: [
        H::input(name: 'name', value: $name),
        H::button(children: 'Save'),
        H::p(children: "Saved: {$name}"),
    ]);
}, key: 'profile');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Profile());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Profile()));
```

どの欄をどの hook に入れるかは署名されますが、**値はクライアント入力です。** 文字列でない値、65,535 バイトを超える値、不正な UTF-8 はリクエスト全体を 400 にします。送られなかった欄 (チェックを外した checkbox) は空文字になります。

`useFormAction` は form の `onSubmit` か、`name` を持つ `input`、`select`、`textarea` の `onChange`、`onInput` (自分の欄だけ) で使います。

## H と属性

`H` は `polidog/rosanna` のものです。`children` と属性は[そちらの README](https://github.com/polidog/rosanna/blob/main/README.ja.md#h-と属性) を見てください。`RosannaState` は同じ要素を描画します。このパッケージが足すのはイベントの prop です。

<!-- readme-test: elements -->
```php
<?php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/vendor/autoload.php';

$items = ['one', 'two'];

echo new RosannaState()->renderElement(H::div(className: 'card', children: [
    H::h2(children: 'Title'),
    $items !== [] ? H::ul(children: array_map(fn(string $item) => H::li(children: $item), $items)) : null,
    H::button(...['aria-label' => 'Close', 'data-id' => '1'], children: 'x'),
    H::textarea(name: 'body', value: 'text'),
]));
```

- イベントは `onClick`、`onChange`、`onInput`、`onSubmit` の 4 つです。`onFocus`、`onBlur`、`onLoad`、`onError` は例外です。値は `Action` か引数なしのクロージャだけで、関数名の文字列は実行せず拒否します。

| イベント | JavaScript なし |
|---|---|
| `button` の `onClick`、`input[type=submit\|image]` の `onClick` | 動く |
| `form` の `onSubmit` | 動く |
| その他の要素の `onClick`、`onChange`、`onInput` (`onInput` は入力が 300ms 止まってから) | `rosanna.js` が必要 |

## クラスコンポーネント

host への埋め込みの基本の形です。`render()` がラッパーの `div` 付きの HTML を返します。

<!-- readme-test: class-component -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/../vendor/autoload.php';

#[Component]
final class Counter extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(children: [
            H::span(children: "Count: {$count}"),
            H::button(onClick: fn() => $setCount($count + 1), children: '+'),
        ]);
    }
}

$app = new RosannaState();
$app->register(Counter::class);

$request = Request::fromGlobals();
$response = $app->actions()->handle($request, replay: fn() => $app->render(Counter::class));
if ($response !== null) {
    $response->send();

    return;
}

echo $app->render(Counter::class);
```

- `render()` と `createElement()` には、登録名とクラス名のどちらでも渡せます。登録していないものは `LogicException` です。
- `$app->render(Counter::class, 'item-1')` の第 2 引数が key です。
- 登録したクラスは引数なしで `new` します。コンストラクタに引数が要るクラスは登録せず、host が `Element` を作ります。`#[Component]` の保存先はそのまま効きます。
- `#[Component(name: '...', storage: 'snapshot', persist: 'sessionStorage')]` で名前と保存先を指定します。
- この例は秘密鍵がないので、署名の鍵をセッションに置きます。ページを開いただけでセッションが始まります。始めたくなければ `setSnapshotSecret()` を呼びます。

host が自分のレイアウトと組み合わせるときは、[最小の例](#最小の例)のように `RenderContext::run($app->scope($request), ...)` の中で `Element` を作り `renderElement()` します。

## Storage

| 種類 | 置き場所 | リロード | 向いている用途 |
|---|---|---|---|
| `StorageType::Session` (既定) | `$_SESSION` | 残る | フォーム、カート |
| `StorageType::Memory` | リクエストの中 | 初期値に戻る | 一時的な UI |
| `StorageType::Snapshot` | ページの中の署名済み JSON | 初期値に戻る (`persist` で残せる) | サーバーに状態を持たない |

<!-- readme-test: storage -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\SnapshotPersist;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$view = function (string $label): Element {
    [$count, $setCount] = useState(0);

    return H::div(children: [
        H::span(children: "{$label}: {$count}"),
        H::button(onClick: fn() => $setCount($count + 1), children: '+'),
    ]);
};

$InSession = fc(fn() => $view('session'), key: 'in-session');
$InMemory = fc(fn() => $view('memory'), key: 'in-memory', storageType: StorageType::Memory);
$InSnapshot = fc(fn() => $view('snapshot'), key: 'in-snapshot', storageType: StorageType::Snapshot);
$Persisted = fc(
    fn() => $view('persisted'),
    key: 'persisted',
    storageType: StorageType::Snapshot,
    persist: SnapshotPersist::SessionStorage,
);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$request = Request::fromGlobals();
$page = fn(): Element => H::main(children: [$InSession(), $InMemory(), $InSnapshot(), $Persisted()]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- **Session** は最初の書き込みでセッションを始めます。読み取りでは始めません。
- **Memory** は部分更新でしか変わらないので JavaScript が必要です。redirect の先では初期値に戻ります。action のたびに空から始まるので、複数の `useState` を持つコンポーネントでは他の値が失われます。
- **Snapshot** は `setSnapshotSecret()` が必須で、なければ描画時に `LogicException` です。JavaScript なしでは POST に全ページで答えるので (`Cache-Control: no-store`)、再読み込みでブラウザが再送を確認します。
- `persist` を付けると `rosanna.js` が `sessionStorage` (タブごと) か `localStorage` (共有) に保存し、読み込みのときに `restore` で描き直します。消すには `window.Rosanna.forgetSnapshots()` です。

### Snapshot をページの外に置く

`handle()` の `behavior` と `restoreSnapshots()` で指定します。GET の描画の前に呼びます。

<!-- readme-test: snapshot-behavior -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Snapshot\SnapshotBehavior;

require __DIR__ . '/../vendor/autoload.php';

#[Component(storage: 'snapshot')]
final class Cart extends BaseComponent
{
    public function render(): Element
    {
        [$count, $setCount] = $this->useState(0);

        return H::div(children: [
            H::span(children: "Items: {$count}"),
            H::button(onClick: fn() => $setCount($count + 1), children: 'Add'),
        ]);
    }
}

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(Cart::class);

$request = Request::fromGlobals();
$behavior = SnapshotBehavior::Persistent;

$response = $app->actions()->handle($request, replay: fn() => $app->render(Cart::class), behavior: $behavior);
if ($response !== null) {
    $response->send();

    return;
}

$app->restoreSnapshots($request, $behavior);
echo $app->render(Cart::class);
```

| 挙動 | 読む場所 | action の後に書く場所 |
|---|---|---|
| `SnapshotBehavior::Isolated` (既定) | ページの中 | なし |
| `SnapshotBehavior::Persistent` | `?_rosanna_snapshot=` | redirect の URL |
| `SnapshotBehavior::Session` | `$_SESSION` | 同じ。セッション ID に束縛する |

- Persistent が運ぶのは 1 インスタンスだけです。別のインスタンスへの action が置き換え、ログに警告が出ます。複数のインスタンスがあるページでは Session を使ってください。
- Session は 64 インスタンスまでで、超えると最も古いものが初期状態に戻ります。URL の `_rosanna_snapshot` は読みません。
- defer のエンドポイントはセッションのスロットを読みません。

### 古い画面からの送信

action には描画時のバージョンが署名されて入ります。古い画面からの送信は上書きせず、最新の状態で描き直します。連打の 2 回目も同じで、**加算されません。** 回数を数える用途には向きません。

- **Session** はサーバーで比べます。部分更新は最新の断片を 200 と `X-Rosanna-Stale: 1` で返し、通常の POST は 303 で戻します。
- **Snapshot** は `rosanna.js` が `sessionStorage` の写しと比べ、古ければ送信せず `restore` で描き直します。写しはリロードと通常の遷移で消えるので、`persist` なしのリロードは初期値に戻ります。`sessionStorage` が使えなければページの中のメモリで比べ、リロードと戻る操作の後は比べられません。
- どちらの場合も `rosanna.js` はキャンセルできる `rosanna:stale` イベントを出し、送信したフォームの入力は保ちます。
- Memory は比べません。

**ロックしないセッションハンドラーでは、同時のリクエストを防げません。** Session の照合はセッションをアクティブにしたまま比較と書き込みをするので、PHP の既定のファイルセッションではロックで直列になります。Redis や Memcached などのロックしない設定、または処理の途中で `session_write_close` を呼ぶ host では、同じセッションの同時のリクエストが両方とも照合を通り、後の書き込みが勝ちます。

**Snapshot の照合は競合の防止で、セキュリティの境界ではありません。** クライアントでしか比べないので、JavaScript なしの送信と、保存した古いスナップショットを手で送るリクエストは止められません。**1 回だけ使える権利 (クーポン、決済の完了など) を Snapshot に置かないでください。**

## PSX

`.psx` は HTML のようにタグを書ける PHP のファイルです。構文、manifest、エラーの詳細は [docs/PSX.md](https://github.com/polidog/rosanna/blob/main/docs/PSX.md) にあります。

<!-- readme-test: psx-counter -->
```php
<?php
// components/Counter.psx
declare(strict_types=1);

namespace App;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

return fc(function (array $props) {
    [$count, $setCount] = useState($props['initial'] ?? 0);

    return (
        <div className="counter">
            <span>Count: {$count}</span>
            <button onClick={fn() => $setCount($count + 1)}>+</button>
        </div>
    );
}, key: 'counter');
```

<!-- readme-test: psx-host -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

require __DIR__ . '/../vendor/autoload.php';

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->loadComponentManifest(__DIR__ . '/../var/cache/psx/' . PsxRenderer::MANIFEST_FILENAME);

$request = Request::fromGlobals();
$page = fn(): Element => RenderContext::scope()->psx->render('App\\Counter', ['initial' => 5]);

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $page());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

```bash
vendor/bin/rosanna-state compile components
```

- ファイル名が大文字で始まる `.psx` がコンポーネントで、FQCN は `namespace\ファイル名` です。別の `.psx` から `<Counter initial={5} />` で呼べます。
- 成果物は `var/cache/psx/` に書き、ソースツリーには書きません。`.gitignore` に入れてください。
- **ビルドした場所と実行する場所のパスを同じにしてください。** manifest は成果物を絶対パスで持ちます。
- **コンパイルは `vendor/bin/rosanna-state compile` で行ってください。** `RosannaState::loadComponentManifest()` は、deferred manifest がないときと、その build ID が `manifest.php` と合わないときに例外を投げます。`vendor/bin/rosanna compile` も入っていますが、これは defer を調べず、残っている deferred manifest を消します。`RosannaState` を使うアプリでは、その後に `rosanna-state compile` をやり直す必要があります。
- **`Defer` という文字列を含むコンポーネントのファイルは、コンパイルのときに実行されます。** トップレベルに副作用のある処理を書かないでください。
- キャッシュのディレクトリはビルドのロールだけが書けるようにし、HTTP のリクエストから書けないようにします。`loadComponentManifest()` にリクエスト由来のパスを渡さないでください。

## host への埋め込み

順番は次のとおりです。

1. **host がページの権限を確かめる。**
2. `$app->handleDeferred($request)` (defer を使う場合)。
3. `$app->actions()->handle($request, replay: ...)`。
4. どちらも `null` なら描画する。

`replay` は action の後の再描画に使います。`Element` か完成した HTML の文字列を返します。`fn(bool $partial)` の `$partial` は部分更新のときに `true` です。部分更新のために見つけるので、`fc()` のコンポーネントは `Element` のまま返すか `renderElement()` を通してください。見つからなければ redirect になり、Isolated の Snapshot は状態を失ってログに警告が出ます。

`replay` の例外は host に伝わります。状態はすでに適用されていることがあります。

### host が権限を確かめる

Rosanna はルーターを持たないので、ページの保護は host の役割です。**`handle()` より前に確かめてください。**

<!-- readme-test: guard -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/../vendor/autoload.php';

$request = Request::fromGlobals();

$isAdmin = ($request->cookies['role'] ?? '') === 'admin';
if ($request->pagePath() === '/admin' && !$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';

    return;
}

$Panel = fc(function (): Element {
    [$count, $setCount] = useState(0);

    return H::button(onClick: fn() => $setCount($count + 1), children: "Runs: {$count}");
}, key: 'panel');

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$response = $app->actions()->handle($request, replay: fn(bool $partial): Element => $Panel());
if ($response !== null) {
    $response->send();

    return;
}

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Panel()));
```

署名は描画したページに束縛されているので、保護されたページのフォームを別のページに送っても 400 です。ただし署名は前後の `/` の違いを同じページとして扱います。**`$request->path === '/admin'` のように生のパスで比べると、`/admin/` への POST で避けられます。** 例のように署名と同じ単位の `$request->pagePath()` で比べるか、同じ扱いをするルーターの後で判定してください。

保護の対象外の経路が 1 つあります。`/_defer/{name}` です ([セキュリティ](#セキュリティの注意))。

### worker モード

FrankenPHP や RoadRunner のように 1 つのプロセスで複数のリクエストを処理する host は、**リクエストの間で `RequestLifecycle::reset()` を呼んでください。** 呼ばないと、前の訪問者の Memory と Snapshot の状態、描画スコープ、インスタンスの連番が次のリクエストに残り、次の訪問者が前の訪問者のセッションに入ることがあります。`reset()` はセッションを書き込んで閉じ、ID を手放します。次の開始では `$_COOKIE` の ID を使います。`reset()` の後に host が自分で `session_start()` を呼ぶときは、先に `session_id()` でその ID を渡してください。

<!-- readme-test: worker -->
```php
<?php
// worker.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;
use function Polidog\Rosanna\State\useState;

require __DIR__ . '/vendor/autoload.php';

$Counter = fc(function (): Element {
    [$count] = useState(0);

    return H::span(children: "Count: {$count}");
}, key: 'counter', storageType: StorageType::Memory);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));

$handle = function () use ($app, $Counter): void {
    $request = Request::fromGlobals();
    echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($Counter()));
};

while (frankenphp_handle_request($handle)) {
    RequestLifecycle::reset();
}
```

`RosannaState` のインスタンスはリクエストをまたいで使えます。リクエストごとの情報を持ちません。

### 出力の後に描画しない

セッションの Cookie は出力より前にしか送れません。先に HTML を出力してから描画すると、セッションを開始も再開もできないので、次のことが起きます。

- **Session の状態が初期値に見えます。** Cookie を持つ訪問者でも、保存した値が読めません。エラーにはなりません。
- その状態に書こうとすると `LogicException` です。
- フォームに CSRF トークンが入らないので、Cookie を持つ訪問者の POST は 403 です。
- 秘密鍵がないと署名の鍵をセッションに置けないので、フォームに署名が入らず POST は 400 です (`setSnapshotSecret()` を呼ぶか、出力より前に描画してください)。

[最小の例](#最小の例)のように描画の結果を変数に入れてからレイアウトを出力するか、`ob_start()` を使います。先に自分で `session_start()` を呼んでもかまいません。

### フレームワークの CSRF と TLS

フレームワークが CSRF を検証しているなら `$app->disableCsrfProtection()` で外します。自前のフォームには `$app->csrf()->token()` を使えます。

TLS を終端するプロキシの後ろでは `$_SERVER['HTTPS']` が空になり、正しい POST が 403 になります。`fastcgi_param HTTPS on` などで渡すか、`$app->trustProxyHeaders()` を呼びます。後者は、**クライアントの `X-Forwarded-*` をプロキシが必ず上書きする構成でだけ**使ってください。それ以外では攻撃者が同一オリジンの判定を避けられます。

## defer

ログイン名やカートの個数のような訪問者ごとの部分をページの読み込み後に別のリクエストで取得し、ページ本体を CDN に載せられるようにします。処理は [`polidog/rosanna-defer`](https://github.com/polidog/rosanna-defer) にあり、state を使わないなら `RosannaState` なしで使えます。

<!-- readme-test: defer -->
```php
<?php
// public/index.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\RenderContext;
use Polidog\Rosanna\State\Component\BaseComponent;
use Polidog\Rosanna\State\Component\Component;
use Polidog\Rosanna\Defer\Defer;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;

require __DIR__ . '/../vendor/autoload.php';

#[Component(name: 'AnnouncementBar', storage: 'memory')]
#[Defer(name: 'announcement-bar', cacheControl: 'public, s-maxage=60', localCache: true, localCacheTtl: 60)]
final class AnnouncementBar extends BaseComponent
{
    public function render(): Element
    {
        return H::p(children: 'Sale today');
    }
}

$UserHeader = fc(
    fn(array $props): Element => H::header(children: 'Hello ' . (string) ($props['name'] ?? 'guest')),
    key: 'user-header',
    storageType: StorageType::Memory,
    defer: new Defer(name: 'user-header', cacheControl: 'private, no-store', reloadable: true),
);

$app = new RosannaState();
$app->setSnapshotSecret((string) getenv('ROSANNA_SNAPSHOT_SECRET'));
$app->register(AnnouncementBar::class);
$app->registerComponent('App\\UserHeader', $UserHeader);
$app->registerDeferred('user-header', 'App\\UserHeader', 'private, no-store');

$request = Request::fromGlobals();

$deferred = $app->handleDeferred($request);
if ($deferred !== null) {
    $deferred->send();

    return;
}

$page = fn(): Element => H::main(children: [
    H::defer('announcement-bar', fallback: H::p(children: '...'), localCache: true, localCacheTtl: 60),
    $UserHeader(['name' => 'Ann', 'fallback' => H::p(children: 'Loading')]),
]);

echo RenderContext::run($app->scope($request), fn(): string => $app->renderElement($page()));
```

- host は `{prefix}/{name}` へのリクエストをアプリに届け、ページの処理より前に `handleDeferred()` を呼びます。prefix は `/_defer` で、`setDeferPrefix('/api/_d')` で変えられます。変えたら `RosannaState::renderClientScript('/rosanna.js', '/api/_d')` にも渡します。
- `#[Defer]` のクラスは `register()` がエンドポイントも登録します。PSX は `loadComponentManifest()` が deferred manifest から登録します。
- `fallback` と `key` 以外の props はクエリになります。スカラーだけで、値は文字列で届きます。`key` は URL から受けません。
- 名前は `[A-Za-z0-9_-]+` で、同じ名前の登録は例外です。
- JavaScript がなければ fallback だけが見えます。

### ページごとの PHP ファイルで使う

組み込みのルーターがないので、ページごとの PHP ファイルに埋め込む使い方では **`/_defer/*` をエントリのファイルに届ける設定が Web サーバーに要ります。** Apache の `.htaccess` の例です。

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^_defer/ defer.php [L,QSA]
```

<!-- readme-test: defer-entry -->
```php
<?php
// public/defer.php
declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Defer\Defer;
use Polidog\Rosanna\State\Http\Request;
use Polidog\Rosanna\State\RosannaState;
use Polidog\Rosanna\State\Storage\StorageType;

use function Polidog\Rosanna\State\fc;

require __DIR__ . '/../vendor/autoload.php';

$Notice = fc(
    fn(): Element => H::p(children: 'Notice'),
    key: 'notice',
    storageType: StorageType::Memory,
    defer: new Defer(name: 'notice'),
);

$app = new RosannaState();
$app->registerComponent('App\\Notice', $Notice);
$app->registerDeferred('notice', 'App\\Notice');

$response = $app->handleDeferred(Request::fromGlobals());
if ($response === null) {
    http_response_code(404);

    return;
}

$response->send();
```

### キャッシュ

- `cacheControl` の既定は `private, max-age=0` です。`public, s-maxage=60` のように共有キャッシュを許しても、描画でセッションを使ったか Cookie を設定したなら `private, no-store` に置き換えてログに警告を出します。全リクエストで `session_start()` を呼ぶ host では、共有キャッシュは効きません。
- 訪問者ごとの内容を `$_COOKIE` から直接読むと Rosanna には見えません。その場合は自分で `private` を指定してください。
- public にキャッシュする断片に action のボタンを置かないでください。セッションを持つ訪問者がキャッシュされたフォームを送ると、署名が合わず失敗します。
- `localCache: true` は `localStorage` に保存します。`localCacheTtl` は秒数です。フォーム、CSRF トークン、スナップショットを含む断片は `localCache` があっても `localStorage` に書きません。消すには `window.Rosanna.clearDeferCache(nameOrUrl)` です。
- `reloadable: true` は取得し直せる形で残します。`window.Rosanna.reloadDefer(nameOrUrl)`、または `data-rosanna-reload-defer="名前"` (フォームなら部分更新の成功の後、それ以外はクリック) で動きます。

## rosanna.js

`RosannaState::renderClientScript($src, $deferPrefix, $nonce, $deferSrc)` は `rosanna.js` と `rosanna-defer.js` の `<script defer>` と、外部ファイルが読めないときに defer を 1 回だけ取得するインラインの fallback を出します。厳しい CSP では第 3 引数に nonce を渡します。

- 差分適用の後の欄の値は、リクエスト中にユーザーが編集した値、サーバーが前回と違う値を描画した場合はその値、送信したフォームの欄は描画された値、それ以外はユーザーの値の順で決まります。hidden と `button` の `value` はサーバーに従います。
- 並べ替えや削除のある一覧の行には `id` を付けてください。付けないと位置で対応付けます。行がコンポーネントなら、ラッパーの `data-rosanna` で対応付けるので `id` は要りません。行には props の `key` を渡してください。`key` がなければ ID が出現順で決まるので、値は行ではなく位置に残ります。
- コンポーネントと祖先、子孫は順番に更新し、兄弟は待ちません。送信中の固定値の action は捨てます。`useFormAction` の action は完了後に最新の値で送り直します。
- **失敗した部分更新は再送しません。** すでに適用されている可能性があるからです。キャンセルできる `rosanna:submit-error` (`detail.response` か `detail.error`) を出し、キャンセルされなければラッパーに `data-rosanna-error` を付けます。

```js
document.addEventListener('rosanna:submit-error', (e) => {
    e.preventDefault();
    if (e.detail.response?.status === 403) location.reload(); // CSRF トークンの失効
});
```

## CLI

```bash
vendor/bin/rosanna-state publish                 # public/rosanna.js と rosanna-defer.js を配置する
vendor/bin/rosanna-state compile [paths...]      # .psx をコンパイルし deferred manifest を書く (既定は ./components)
vendor/bin/rosanna-state compile --check         # 書き込まず、古ければ exit 1。CI 向け
vendor/bin/rosanna-state compile --watch         # 変更を見て再コンパイルする
vendor/bin/rosanna-state compile --clean         # 出力先の中身を消す
vendor/bin/rosanna-state compile --cache=PATH    # 出力先 (既定は var/cache/psx)
vendor/bin/rosanna-state help
```

存在しないパス、未知のオプション、未知のコマンドは exit 1 です。

`polidog/rosanna` の `vendor/bin/rosanna` (`compile` と `help`) も隣に入ります。その `compile` は deferred manifest を消し、`--check` は古いと報告するので、`RosannaState` を使うアプリでは `rosanna-state compile` だけを使ってください。

## セキュリティの注意

署名、CSRF、URL の遮断が保証するのは「運び方」です。次はアプリの責任です。

- **`useState` の値はクライアント入力です。** setter の値はサーバーが計算しますが、ユーザーは自分に描画された action を何度でも送れます。**認可の判定、金額、決済の完了、ユーザー ID をフックの状態から作らないでください。** 描画のときに `$_SESSION` やデータベースから読みます。
- **スナップショットの中身は HTML に平文で出ます。** HMAC が防ぐのは改ざんで、読み取りではありません。秘密や個人情報を入れないでください。
- **スナップショットは再生でき、期限がありません。** 鍵が変わるまで (セッションに束縛したものはセッション ID が変わるまで) 有効です。Persistent はリンクを知っている人が同じ状態を再現できます。`_rosanna_snapshots[]` もどれを送るかはクライアントが選びます。
- **祖先の props でスナップショットの値を信頼しないでください。** `rosanna.js` は子の部分更新で祖先のスナップショットを `_rosanna_ancestors[]` で運び、子を祖先の値で描きます。署名されていれば、クライアントはどの時点の値でも選べます。`_rosanna_snapshots[]` で操作対象の子孫以外を運ぶと 400 ですが、この確認は祖先の値を止めません。`X-Rosanna-Partial` を外した通常の POST ではこの確認を通りません。JavaScript なしの全ページ応答にはページ上のスナップショットの運搬が必要だからです。祖先の状態を、子に渡す権限の判定に使わないでください。
- **Session の挙動でも、POST の経路は別のセッションのスナップショットを受けます。** URL の `_rosanna_snapshot` は読みませんが、action の `_rosanna_snapshot` は署名の検証を通れば、セッションに束縛されていない値を自分のスロットに書けます。CSRF が効くので影響は自分のセッションだけで、フックの状態がクライアント入力であることの範囲です。
- **`/_defer/{name}` は認証なしの公開の GET で、host の保護の対象外です。** 保護されたページの中の defer でも同じです。コンポーネントの中で権限を確かめてください。**クエリは props になる信頼しない入力です。** 検証してから使い、タグ名、属性名、`onClick` などに流さないでください。
- **`H` と `Renderer` には出力の決まりがあります。** `<style>` と `<script>` の中身をエスケープしないこと、タグ名と属性名の扱いなどです。[`polidog/rosanna` の注意](https://github.com/polidog/rosanna/blob/main/README.ja.md#セキュリティの注意)を見てください。

### 鍵

```bash
php -r 'echo bin2hex(random_bytes(32));'
```

一度だけ生成して設定から読みます。**リクエストとサーバーの間で同じ値にしてください。** 変えると有効なスナップショットとフォームがすべて無効になります。git にコミットしないでください。16 バイト未満の鍵は `InvalidArgumentException` になります。署名は HTML に出るので、短い鍵は手元で総当たりできるためです。確かめるのは長さだけなので、上のように乱数で作ってください。

### 署名と CSRF

- action の署名はページ、セッション、インスタンス、action の JSON に束縛されます。署名のない action は 400 です。
- defer の中のフォームは defer の名前に束縛され、どのページへの POST でも通ります。誰でもエンドポイントから新しいフォームを取れるので、渡すものは増えません。POST 先のページの host の保護は動きます。
- 別のページに差し込んだ HTML のフォームは 400 です。defer にするか、`$app->signer()->sign($id, $json, $pagePath)` で POST 先を指定します。
- POST は `Origin` (なければ `Referer`) が同じオリジンでなければ 403 です。両方ないものも 403 です。セッションがあれば `_rosanna_csrf` も比べます。
- JSON オブジェクトでない `_rosanna_action` は Rosanna のものとして扱わず、host に素通りします。

### セッション Cookie

Rosanna は Cookie のフラグを設定しません。`php.ini` か `session_start()` で指定します。認証の直後の `session_regenerate_id(true)` もアプリの役割です。

```ini
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Lax
```

## 既知の制約

一覧全体を 1 つの `useState` に入れても、値は差分で運ぶので HTML のサイズは行数に比例します。次の形は**行数の 2 乗のまま**です。

- Snapshot で、行ごとに `onSubmit` のフォーム、`input[type=submit|image]`、利用者の `name` か `value` を持つ `button` を置く形。個別のフォームごとにスナップショットが入ります。
- 並べ替えのように全体が変わる操作を各行に置く形。全体の値が入ります。
- Snapshot の行コンポーネントへの JavaScript なしの全ページ応答。各フォームに他のインスタンスのスナップショットが入ります。
- CPU 時間。`onClick` のクロージャは描画時に実行します。

その他の制約です。

- 連打と複数タブの更新は加算されず、描き直されます ([古い画面からの送信](#古い画面からの送信))。
- セッションは PHP のネイティブセッションだけです。
- `component()` (`Polidog\Rosanna\State\component()`) はラッパーを出さず、保存先は常に Session です。action はその場の更新でなく再読み込みになります。
- PSX の成果物は絶対パスに依存します ([PSX](#psx))。
- 属性のスプレッド (`<div {...$a} />`) と名前空間付きの属性名は PSX では使えません。
- 秘密鍵があり Cookie のない訪問者では、最初の action でセッションが始まります。描き直されなかった別のフォームには CSRF トークンがないので、その応答 (`X-Rosanna-Session-Started: 1`) の後で `rosanna.js` がページを GET で取り直し、ラッパーごとに差分を当ててフォームを新しくします。入力中の欄、フォーカス、Snapshot の状態は残ります。取り直せなければ再読み込みします。この間のクリックは送信中のロックと同じ扱いで、固定値は捨て、`data-rosanna-with-value` は最新の値で送り直します。同じ理由で、トークンのないフォームの Session の action は同時に送りません。別のタブの古いフォームは 403 か 400 を受けた後に 1 回同じ更新を行い、`rosanna:stale` を出します。その action は適用されていないので、押し直すと通ります。残る制約は次のとおりです。
  - 更新の分の GET が 1 回増えます。フォームを含む解決済みの defer ごとの GET と、戻す Snapshot ごとの `restore` の POST も増えます。
  - host が JS で組み立てたラッパーの外のフォームは新しくなりません。送ると 403 になるので、`rosanna:submit-error` で再読み込みしてください。
  - JavaScript なしでは、通常の POST の応答でそのタブのフォームは新しくなりますが、開いたままの別のタブのフォームは 403 になり、再読み込みが要ります。
  - Cookie のあるリクエストに共有キャッシュが匿名のページを返すと、フォームが新しくなりません。ログイン後のページと同じく、セッション Cookie のあるリクエストをキャッシュから返さないでください。
- `Request` を手で作る host は、`Request::$cookies` と `$_COOKIE` を揃えてください。action の判定は `Request::$cookies` を読みますが、Session のフックの読み書きは `$_COOKIE` からセッションを再開します。`Request::fromGlobals()` なら揃っています。

## polidog/rosanna の状態を使っていたアプリの移行

`new` と `use` の置換だけでは済みません。状態を使うアプリは次の手順をすべて行います。

1. `composer require polidog/rosanna-state` を実行します。
2. `new Rosanna()` を `new RosannaState()` にします。`setSnapshotSecret()`、`actions()`、`scope()`、`restoreSnapshots()`、`handleDeferred()`、`renderClientScript()` は `RosannaState` のものになります。`register()`、`render()`、`createElement()` も同じです。
3. `.psx` と PHP の `use function Polidog\Rosanna\Runtime\useState;` などを、下の表のとおり `Polidog\Rosanna\State\` に替えます。`H::component()` の呼び出しは `State\component()` に替えます。
4. `vendor/bin/rosanna-state compile` で再コンパイルします。生成コードは `.psx` の `use` をそのまま写すので、状態付きの PSX は全部再コンパイルが要ります。変わらないのは、コンパイラが生成するコンポーネント呼び出し `RenderContext::scope()->psx->render` の形だけです。
5. `composer.json` の `scripts` にある `AssetInstaller::publish` の名前空間を `State\Installer\` に替えます。`publish` も移るので `vendor/bin/rosanna-state publish` を使います。

| 前 (`Polidog\Rosanna\`) | 後 (`Polidog\Rosanna\State\`) |
|---|---|
| `Runtime\fc`、`Runtime\useState`、`Runtime\useFormAction`、`Runtime\useEffect` | `fc`、`useState`、`useFormAction`、`useEffect` |
| `Html\H::component()` | `component()` |
| `Runtime\Action`、`Runtime\FunctionComponent`、`Runtime\ComponentState`、`Runtime\RendererFactory` など、`Runtime\` の状態層のクラス | 同じ名前で `State\` の直下 |
| `Runtime\Snapshot` | `Snapshot\Snapshot` |
| `Protocol\*`、`Snapshot\*`、`Storage\*`、`Component\*`、`Http\*`、`Installer\*` | 同じ名前で `State\` の下 |
| `RenderContext::scope()->renderers`、`->signer`、`->signingScope` | `StateScope::of(RenderContext::scope())->renderers` など |

`Html\H`、`Html\createElement`、`Runtime\Element`、`Runtime\Renderable`、`Runtime\RenderContext`、`Runtime\RequestLifecycle`、`Runtime\PsxRenderer`、`Psx\*` は同じ名前で `polidog/rosanna` に残ります。

## usePHP からの移行

```bash
composer remove polidog/use-php
composer require polidog/rosanna-state
```

1. `Polidog\UsePhp` と `UsePHP` を[上の表](#polidogrosanna-の状態を使っていたアプリの移行)のとおりに置き換えます。状態層は `Polidog\Rosanna\State\`、描画は `Polidog\Rosanna\` で、`UsePHP` は `RosannaState` になります。`Polidog\Rosanna` への一括置換では足りません。`SimpleRouter` や `useRouter` を使っていれば、host のルーターかページごとの PHP ファイルに置き換えます。Relayer の利用者は `useRouter` の `use` を Relayer のものに直します。
2. `composer.json` の `scripts` の `AssetInstaller` の名前空間を `Polidog\Rosanna\State\Installer\` に直します。
3. `vendor/bin/usephp` を `vendor/bin/rosanna-state` に直します。
4. `vendor/bin/rosanna-state compile` で PSX を再コンパイルします。
5. `vendor/bin/rosanna-state publish` で `rosanna.js` を配置し、HTML の `usephp.js` の読み込みを直します。
6. 自前の JS やテンプレートが `_usephp_*`、`data-usephp-*`、`X-UsePHP-Partial`、`window.usePHP` を参照していれば新しい名前 (`_rosanna_*`、`data-rosanna-*`、`X-Rosanna-Partial`、`window.Rosanna`) に直します。
7. デプロイのときに CDN のキャッシュを消します。新旧の両方を受け付ける期間はありません。古い HTML と JS は動きません。

hooks とイベントの prop を使わないサイトは `polidog/rosanna` だけに移れます。[そちらの README](https://github.com/polidog/rosanna/blob/main/README.ja.md#usephp-からの移行) を見てください。

コンパイラは `polidog/rosanna` に入っているので、`composer install --no-dev` の後でも、ビルド工程でも本番サーバーの上でも `vendor/bin/rosanna-state compile` を使えます。コンパイル済みのファイルの描画では、コンパイラも `nikic/php-parser` も読み込みません。

### API の対応

| usePHP | Rosanna |
|---|---|
| `$app->run()`、`getRouter()`、`disableRouter()`、ミドルウェア | なし。host が行う ([host への埋め込み](#host-への埋め込み)) |
| `$app->handleAction()` | `$app->actions()->handle($request, replay: ...)`。`Response` か `null` を返す |
| `$app->handleDeferred()` | `$app->handleDeferred($request)`。`Response` か `null` を返す |
| `isolatedSnapshot()`、`persistentSnapshot()`、`sessionSnapshot()` | `handle()` の `behavior` と `restoreSnapshots()` |
| `sharedSnapshot()` | なし。`SnapshotBehavior::Session` を使う |
| `$app->getCsrfToken()` | `$app->csrf()->token()` |
| `$app->signAction()`、`verifyActionSignature()` | `$app->signer()->sign()`、`verify()` |
| `$app->getSnapshotSerializer()` | `$app->snapshotSerializer()`。例外でなく `null` を返す |
| `$app->redirect()` | なし。redirect 先は常に現在の URL |
| `$app->installPsxErrorHandler()` | なし。`StackTraceRewriter::formatException()` を使う |
| `RenderContext::getApp()`、`setApp()` | `RenderContext::run($app->scope($request), ...)`、`RenderContext::scope()` |
| `H::component()` | `Polidog\Rosanna\State\component()` |
| `CompileCommand::MANIFEST_FILENAME` | `PsxRenderer::MANIFEST_FILENAME` |
| `#[Component(requireKey: ...)]` | なし。指定していれば名前付き引数のエラーになるので消す |

振る舞いの変更です。

- `children` に置いた `BaseComponent` のインスタンスが `#[Component]` の保存先を読み、`fc()` と同じラッパーを出します。HTML が変わります。
- 匿名の訪問者の `useState` の読み取りでセッションを始めません。初期値も保存しません。
- JSON でない `_rosanna_action` は 400 でなく host に素通りします。
- フォームの `_usephp_action_sig` はなくなり、`_rosanna_action` の値が action と署名の JSON になります。
- コンポーネントの外の hooks は `LogicException` です。
- `<defer localCache="true" />` のような文字列の属性はコンパイルエラーです。`{true}` と書きます。

## 開発

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/psalm --no-progress
vendor/bin/php-cs-fixer fix --dry-run --diff
node --test tests/js/*.test.mjs
npm ci && npx playwright install chromium && npx playwright test
```

## ライセンス

MIT。[LICENSE](LICENSE) を参照してください。
