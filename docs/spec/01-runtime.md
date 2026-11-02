# Rosanna State 実装仕様 01 — 状態層のランタイム

Status: Draft (2026-11-02、`polidog/rosanna` の 01 から分離。振る舞いの照合は usePHP v0.10.0 `e073bff` / Relayer main `9566b0c`)

計画: [rosanna-split-and-rename.md](https://github.com/polidog/rosanna/blob/main/docs/design/rosanna-split-and-rename.md)

| 仕様 | 範囲 |
|---|---|
| 01 (この文書) | フォームの束縛と配置 (RT-4)、コンポーネント、`fc()`、`component()`、hooks、インスタンス ID と描画パス、状態層のスコープ、`ComponentRegistry`、`RosannaState` の API、`publish` |
| [02-state-and-actions.md](02-state-and-actions.md) | Storage、Snapshot、Action、署名、CSRF、バージョン照合、defer、`rosanna.js`、`actions()->handle()`、`signer()` |
| [本体の 01](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md) | `Element`、`H`、`Renderer`、`RenderExtension`、`RenderContext`、`RenderScope`、`RequestLifecycle`、`PsxRenderer`、`Rosanna` の描画 API |
| [本体の 03](https://github.com/polidog/rosanna/blob/main/docs/spec/03-compiler.md) | PSX、コンパイラ (`src/Psx/`) |

要件 ID は `polidog/rosanna` にあったときの番号のままで、節の番号も変えない。この文書にない番号 (RT-1〜3、RT-11 など) は本体の 01 にある。

根拠の `usePHP/...` は `../usePHP` のパスと行番号で、仕様の参考資料である。短く書いた `Renderer.php:515` なども usePHP の場所である。

## 0. 共通の前提

- 名前空間は `Polidog\Rosanna\State\`。`Runtime\*` にあった状態層のクラスと関数は `State\*` に平らにし、`Protocol`、`Snapshot`、`Storage`、`Component`、`Http`、`Installer` は `State\` の下で同じ名前を保つ。`Runtime\Snapshot` (値) だけは `State\Snapshot\Snapshot` に置く。
- 依存の向きは `polidog/rosanna-state` から `polidog/rosanna` への一方向。
- 初期化は `composer require polidog/rosanna-state` と `new RosannaState()` だけで、bootstrap の呼び出しはない。Composer の `autoload.files` が `src/Hooks.php` と `src/bootstrap.php` を読み、`EventResolver::register()` と `RequestLifecycle::onReset()` で本体に登録する。どちらもリクエストごとの情報を持たないので worker でも問題ない。
- ホスト側の仕事 (POST を `actions()->handle()` に渡す、リクエストの scope の中で描画する、`renderClientScript()` と `rosanna-state publish` で JS を配信する、Snapshot なら `setSnapshotSecret()` を呼ぶ) は初期化に含まれない。RT-12.7 と README に書く。
- 本体の RT-2.6 の「状態層が差し込んだ実装」は `State\resolveEventAction()` である。

## 4. Action の束縛とフォームの配置

**目的:** `wire:*` の prop を、JavaScript なしでも動く HTML にする。hidden フィールドの中身は 02 が定める。この節は配置だけを定める。

実装は `ActionFormExtension` で、本体の `RenderExtension` ([本体 01](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md) の RT-3.15) を実装する。描画ごとの可変状態を持つので、`Renderer` と一緒に描画ごとに作る。

- **RT-4.1** 束縛は次の表のとおり (`Renderer.php:515`)。`wire:` で始まる prop の値が `Action` でなければ `RuntimeException`。

| イベント | 要素 | 出力 |
|---|---|---|
| `submit` | `form` | 要素自体を action のフォームにする。`method="post"` と `data-rosanna-form` を足し、hidden フィールドを先頭の子にする |
| `click` | `button` | `type="submit"`、`form="<id>"`、`name="_rosanna_action"`、`value="<エンベロープ>"` (02 の AC-20)。共有フォームを使う (RT-4.7)。利用者の `name` か `value` を持つ `button` は、これらを付けず個別フォームを使う |
| `click` | `input` で `type` が `submit`、`image` | `form="<id>"`。個別フォームを使う。`submit` は `value` がラベルになり、`image` は `value` が送られないため |
| `click`、`change`、`input` | その他 | `data-rosanna-on-<event>="<id>"` と `data-rosanna-action-<event>="<エンベロープ>"`。共有フォームを使う。`rosanna.js` が必要 |
| その他 | | `RuntimeException` |

  この節で読む属性の名前 (`action`、`method`、`name`、`value`、`type`、`multiple`) は大文字小文字を区別しない。Rosanna が書く `type`、`form`、`method` と同じ名前の利用者の属性は、大文字でも取り除く。パーサーは同じ属性の最初を読むため。
- **RT-4.2** `submit` を `form` 以外に付けたら `RuntimeException`。`action` を持つ `form`、`method` が `post` でない `form` も `RuntimeException`。
  JavaScript の有無で送信先が変わるのを防ぐため (`Renderer.php:593`)。
- **RT-4.3** `Action::SET_FIELDS` を `submit` 以外に付ける場合、次のいずれかなら `RuntimeException`。条件を満たせば
  `data-rosanna-with-value` を付ける (`Renderer.php:570`)。`submit` の場合も同じ属性を付ける。
  - イベントが `click`。
  - 要素が `input`、`select`、`textarea` でない。
  - `multiple` か `type="file"` を持つ。
  - フィールドのマップが、要素の `name` 1 つだけでない。

- **RT-4.4** 共有フォームと個別フォームは `<form method="post" data-rosanna-form id="rosanna-f-<16桁の16進>" hidden>` とする。
  ID はページ内で一意にするため `random_bytes(8)` から作る。インスタンスから決まる固定の ID にはしない。アプリの `form` の中のコンポーネントはフォームがラッパーの外に
  置かれ (RT-4.5)、部分更新の後も古いフォームが残る。ID が同じだと、新しいボタンが古いスナップショットのフォームを指す。
- **RT-4.5** 共有フォームと個別フォームは要素の隣に出さず、溜めておいて次の場所に出す。`form`、`p`、`tbody` の中に置いても
  マークアップが崩れないようにするため (`Renderer.php:296-305`、`:461-487`)。
  1. `data-rosanna` を持つ要素の中で、開いている `form` がなく、その要素自身も `form` でないとき: その要素の閉じタグの直前。その要素より外で溜まっていたものは持ち込まず、外に戻す。
     部分更新で親のフォームが消えないようにするため。`data-rosanna` を持つ `form` は入れ子を作らないよう 2 に従う。
  2. 最も外側の `form` の閉じタグの直後。
  3. 残りは最も外側の `renderElement()` の末尾。
- **RT-4.6** CSRF トークンはフォームを出力するときに読む。Action のないページでセッションを開始しない
  (`Renderer.php:669`、`tests/Runtime/ActionFormPlacementTest.php:191`)。これにより Relayer の `LayoutRenderer::containsStateAction()` は不要になる。
- **RT-4.7** **#87 への対応。** 共有フォームは、スコープと action の対象のインスタンスの組み合わせごとに 1 つ作る。スコープは最も内側の `data-rosanna` 要素で、なければ最も外側の
  `renderElement()` とする。中身は action を除く hidden フィールドで、1 回だけ書く。action はボタンの `value` か `data-rosanna-action-<event>` が持つ。
- **RT-4.8** 書き出した後も、同じスコープの間は共有フォームの ID を使い回す。`form` 属性は文書のどの位置のフォームも指せるので、行ごとにアプリの `form` があっても増えない。
  差分の基準 (02 の AC-18) が書き出し済みのフォームに足りないときだけ、新しい共有フォームを作る。
- **RT-4.9** 個別フォームは action ごとに作り、hidden フィールドをすべて持つ。`submit` の `form` は要素自体が個別フォームになる。
- **RT-4.10** コンポーネントのラッパーを `table`、`thead`、`tbody`、`tfoot`、`tr`、`colgroup`、`select`、`optgroup` の直下に置いたら、描画のときに `RuntimeException`。
  ラッパーは `div` なのでパーサーが外に出して空にする。中のフォームも分断され、`rosanna.js` の部分更新が親のラッパーを書き換えて表が消える (Chromium で再現した)。`Fragment` は要素にならないので、その親で判定する。セルの中 (`td`) に置くか、表全体を 1 つのコンポーネントにする。

**テスト:** `tests/Runtime/ActionFormPlacementTest.php` と `ElementEventsTest.php` の項目。次を必ず含める。

- アプリの `form`、`p`、`table` の中のボタンで、入れ子のフォームや分断が起きないこと。
- 親のフォームが子コンポーネントのラッパーに入らないこと。
- JavaScript なしの送信で動く要素と、`data-rosanna-on-*` になる要素の区別。
- RT-4.1: 利用者の `name` か `value` を持つ `button` の値が上書きされず、個別フォームで動くこと。
- RT-4.4: アプリの `form` の中のコンポーネントを部分更新した後、新しいボタンが新しいフォームを指すこと。
- RT-4.7: 行のボタンが何個あっても共有フォームが 1 つで、対象のインスタンスが違えば分かれること。
- RT-4.7: 行数を 2 倍にしたとき、HTML のサイズがおよそ 2 倍に収まること (Session、Snapshot)。
- RT-4.8: 行ごとのアプリの `form` の中のボタンでも共有フォームが増えないこと。

## 5. クラスコンポーネント

**目的:** クラスでコンポーネントを書く入口。

```php
namespace Polidog\Rosanna\State\Component;

interface ComponentInterface
{
    public static function getComponentName(): string;
    public function render(): Element;
}

abstract class BaseComponent implements ComponentInterface, ResolvesItself
{
    /** @internal */ public function setComponentState(ComponentState $state): void;
    public function getComponentState(): ?ComponentState;
    protected function useState(mixed $initial): array;
    public static function getComponentName(): string;
    /** ラッパー付きの最終形。本体の `resolveRenderables()` が呼ぶ */
    final public function resolve(): Element;
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Component
{
    public StorageType $storageType;
    public ?SnapshotPersist $persist;

    public function __construct(
        public ?string $name = null,
        StorageType|string $storage = StorageType::Session,
        SnapshotPersist|string|null $persist = null,
    );
}
```

**振る舞い** (`usePHP/src/Component/`)

- **RT-5.1** `getComponentName()` は `#[Component(name:)]`、なければクラス名を返す。
- **RT-5.2** `$storage` と `$persist` は enum か、その値の文字列を受ける。`persist` を Snapshot 以外に指定したら `InvalidArgumentException`。
- **RT-5.3** `BaseComponent::useState()` は RT-7.1 と同じで、setter の Action にインスタンスの `storageType` を入れる。コンストラクタに引数が要るクラスは登録できず、
  action の処理時にレジストリで保存先を決められないため。登録の有無は setter から見えないので、登録したクラスにも入れる (値はレジストリと同じ)。
  state が未設定なら `[$initial, setter]` を返し、この setter は `storageType` を持たない。
- **RT-5.4** `children` に `BaseComponent` のインスタンスを置いた場合 (`Elements.php:1502`)。`BaseComponent` は本体の `ResolvesItself` を実装し、本体の `resolveRenderables()` が `resolve()` を呼ぶ:
  - state が未設定なら、スタックに積んで ID を作り、state を割り当てて `render()` し、`finally` で親の state とスタックを戻す。
  - 設定済みなら新しい番号を取らず、同じ state をスタックに積み、hook の位置を 0 に戻して `render()` し、`finally` で親の state とスタックを戻す。同じインスタンスを再利用しても同じ状態を読む。
  - どちらも `#[Component]` の `persist` を読み、`fc()` と同じラッパーを出す (RT-6.4、RT-6.5)。未設定のときは保存先も読む。usePHP はラッパーを出さず保存先も見ないので、HTML が変わる。

**テスト:** `tests/Component/ComponentAttributeTest.php`、`ComponentPersistTest.php`、`tests/Runtime/NestedRenderRestoreTest.php`。
子の後で親の hooks が親に戻ること。子が例外を投げても親と ID が戻ること。

## 6. fc() と関数コンポーネント

**目的:** クロージャでコンポーネントを書く入口。推奨の書き方で、PSX もこの形になる。

```php
namespace Polidog\Rosanna\State;

function fc(
    callable $component,
    ?string $key = null,
    StorageType $storageType = StorageType::Session,
    ?Defer $defer = null,
    ?SnapshotPersist $persist = null,
): FunctionComponent;

/** `callable $component, array $props = []` は移行前の `H` の静的メソッドと同じ */
function component(callable $component, array $props = []): FunctionComponentCall;

final class FunctionComponent implements AcceptsArgumentResolver
{
    public function __construct(
        public readonly \Closure $inner,
        public readonly ?string $key = null,
        public readonly StorageType $storageType = StorageType::Session,
        public readonly ?Defer $defer = null,
        public readonly ?SnapshotPersist $persist = null,
    );
    public function __invoke(array $props = []): Element;
}
```

`$defer` は `DeferredManifestCompiler` が読む ([本体 03](https://github.com/polidog/rosanna/blob/main/docs/spec/03-compiler.md) の CP-135)。

**振る舞い** (`usePHP/src/Runtime/FunctionComponent.php`)

- **RT-6.1** `$persist` を Snapshot 以外と組み合わせたら `InvalidArgumentException`。
- **RT-6.2** `__invoke` は `$defer` があり、現在のスコープが「このコンポーネントの defer エンドポイントの描画」でなければ、
  プレースホルダーを返す (`Defer::buildPlaceholder`、02)。入れ子の defer はプレースホルダーのままにする。
  スコープがない場合もプレースホルダーを返す。
- **RT-6.3** それ以外は次の順で描画する。例外でも `finally` でスタックと親の state を戻す。
  1. `key` prop を取り除き、インスタンス ID を決める (RT-8.2)。
  2. コンポーネントをスタックに積み、親の state を覚え、`ComponentState::getInstance($id, $storageType)` を現在にして hook の位置を 0 に戻す。
  3. `inner($props)` を実行する。
- **RT-6.4** 結果は `Element('div', ['data-rosanna' => $id], [$result])` で包む。
- **RT-6.5** Snapshot のとき、ラッパーに `data-rosanna-snapshot` を付ける。署名が得られれば、`persist` の有無によらず `data-rosanna-restore-sig` も付ける (02 の VR-7)。
  `persist` があれば `data-rosanna-persist` を付ける (値の形式は 02)。
- **RT-6.6** Snapshot でスコープがなければ `LogicException`。署名なしのスナップショットを出さないため (`FunctionComponent.php:93`)。
- **RT-6.7** `State\component($c, $props)` は `FunctionComponentCall` を返す。`Element` ではなく、本体の `ResolvesItself` を実装した値で、`children` の中に置くと本体の `resolveRenderables` が `resolve()` で展開する。
  `fc()` と違ってラッパーを出さず、保存先は Session になる。`key` は props から取り除いて ID に使い、int は文字列にする。string でも int でもない `key` は `InvalidArgumentException`。コンポーネントが `Element` を返さなければ `RuntimeException`。
  呼び出す先は `\Closure` にして持ち、ID の名前 (RT-8.1) は元の callable から作って別に持つ。予約の type を持つ `Element` を経由しないので、手で組んだ props の文字列を関数名として実行する経路はない。
- **RT-6.8** 同じ ID で 2 つ描画しても検出せず、state を共有する (`FunctionComponent.php:121`)。

**テスト:** `tests/Runtime/FunctionComponentTest.php`。次を必ず含める。

- `$key` なしの自動採番、固定 key、props の key の組み合わせ。
- 親の hooks が子の後も親に束縛されること。例外時の復元。
- key に配列を渡したときの `InvalidArgumentException`。
- スコープなしの Snapshot の `LogicException`。
- RT-6.7: `State\component()` がラッパーを出さず、保存先が Session になること。key の扱いと、`Element` を返さないときの例外。

## 7. hooks

**目的:** React と同じ形の状態 API。値の保存先は 02。

```php
namespace Polidog\Rosanna\State;

/** @return array{0: T, 1: callable(T): Action} */
#[\NoDiscard]
function useState(mixed $initial): array;

function useFormAction(array $fields): Action;

function useEffect(callable $callback, ?array $deps = null): void;
```

### useState

- **RT-7.1** 現在の `ComponentState` の次の hook 位置を取り、値を返す。setter は `Action::setState($index, $value, $componentId, $storageType)` を返すクロージャで、
  呼んでも状態は変わらない。値は描画時に確定する (`usePHP/src/Runtime/Hooks.php:19`)。setter は描画時の値と新しい値を比べ、使えるときは `Action::setStateDiff()` を返す
  (02 の AC-13)。利用者のコードは変わらない。
- **RT-7.2** hook は呼び出し順で識別する。条件分岐で順番を変えた場合は検出しない。
- **RT-7.3** `null` も値として保持する。初期値の再適用はしない (`tests/Runtime/HooksTest.php` の `testSetStateNullIsKeptInSessionStorage`)。
- **RT-7.4** **#120 への対応。** 読み取りだけでセッションを開始しない。保存側の要件は 02。
- **RT-7.4a** コンポーネントの外で `useState`、`useFormAction`、`useEffect` を呼ぶと `LogicException`。ページ自体の state を host が設定する場合は `ComponentState::setCurrent()` を `@internal` のまま使う。

### useFormAction

- **RT-7.5** `['field' => $setter]` を受け、`Action::setFields($map, $componentId, $storageType)` を返す (`Hooks.php:59`)。
  `$map` はフィールド名から hook 位置への対応で、署名の対象になる。
- **RT-7.6** 次の場合は `InvalidArgumentException`。
  - 配列が空。
  - フィールド名が `/^[A-Za-z_][A-Za-z0-9_\-]*$/` に合わない、または `_rosanna_` で始まる。`tags[]` や `a.b` は `$_POST` から読み戻せないため。
  - 値が `useState` の setter でない。`\Closure` に限り、`null` を渡して `setState` の Action が返るかで判定する。
  - setter が複数のコンポーネントにまたがる。
- **RT-7.7** 使える場所の制限は Renderer が検査する (RT-4.3)。値の検証は 02。

### useEffect

- **RT-7.8** 描画の中で同期的に実行する (`Hooks.php:123`)。実行の判定は次のとおり (`ComponentState.php:161`)。

| `$deps` | 実行するとき |
|---|---|
| `null` | 毎回 |
| `[]` | 前回の deps が配列でないとき (初回) だけ |
| 配列 | 前回の deps が配列でない、要素数が違う、いずれかが `!==` のとき |

- **RT-7.9** 実行の順番は、前回のクリーンアップ、callback、クリーンアップの記録、deps の保存とする。
- **RT-7.10** クリーンアップとして扱うのは callback が返した `\Closure` だけ。`fn() => 'system'` のような文字列を実行しないため、セキュリティ要件である。
- **RT-7.11** クリーンアップはリクエストのメモリにだけ置き、保存先に書かない。`$_SESSION` に Closure を書くと終了時に致命的エラーになるため
  (`ComponentState.php:29`)。次のリクエストでは実行しない。アンマウントはない。

**テスト:** `tests/Runtime/HooksTest.php` と `FormActionTest.php`。`useRouter` の項目は持ち込まない。次を必ず含める。

- deps が `null` から配列に変わるとき、値としての `null` を比べるとき。
- 多重実行しないこと、クリーンアップが保存先に書かれないこと、次のリクエストで実行されないこと。
- `useFormAction` の不正なフィールド名、setter でない値、複数コンポーネント。

## 8. インスタンス ID と描画パス

**目的:** 1 回の描画の中でコンポーネントのインスタンスを識別する。ID は state のキー、署名の対象、HTML の `data-rosanna` の値になる。

- **RT-8.1** ID の形式は `<名前>#<key>` とする。名前は次のとおり (`usePHP/src/Html/Elements.php:1563`)。

| コンポーネント | 名前 |
|---|---|
| クラス | `getComponentName()` |
| クロージャ | `FC@<ファイルの basename>:<開始行>` |
| 配列 callable | `<クラス>::<メソッド>` |
| 文字列 callable | その文字列 |

- **RT-8.2** key は次のとおり (`FunctionComponent.php:124`)。props の key は `string` か `int` だけを許し、それ以外は `InvalidArgumentException`。

| `fc()` の key | props の key | ID の key |
|---|---|---|
| なし | なし | 名前ごとの連番 (`0`、`1`、…) |
| `a` | なし | `a` |
| なし | `b` | `b` |
| `a` | `b` | `a/b` |

- **RT-8.3** 連番はパスごとに名前ごとに数える (`RenderContext.php:208`)。親の ID を含めない。例外は RT-8.6。
- **RT-8.4** usePHP の `ComponentId` の階層形式 (`Page@home/Counter@item-1`) は使われていないので作らない。`Runtime/ComponentId.php` の `parse`、`getPath`、`toString` 系は `src` からも Relayer からも呼ばれていない。
  ID の検証は 02。
- **RT-8.6** クラスコンポーネントの中の key のない子は、そのインスタンスの中で数え、key を `<クラスのインスタンス ID>/<連番>` にする (`FC@a.php:3#Reg#two/0`)。
  登録クラスの部分更新はページを描かないので、ページの中の順番に頼ると別のインスタンスの子と番号が重なり、状態が入れ替わる。`fc()` だけの木は replay でページ全体を描くので RT-8.3 のまま。
  描画のときに番号を取る子 (`children` の `BaseComponent`) も同じ範囲で数えるよう、クラスの `render()` の結果は範囲の中で展開する。
- **RT-8.5** パスの操作は次のとおり。`resumePass` は名前ごとに大きいほうの連番を残し、入れ子のパスが使った番号を外側のパスが再利用しないようにする
  (`RenderContext.php:92`)。

```php
RenderPass::beginRender(): void;        // 新しいパスを始める
RenderPass::currentPass(): array;       // いまのパスを保存する
RenderPass::resumePass(array $p): void; // 保存したパスに戻す
```

**テスト:** `tests/Runtime/NestedRenderRestoreTest.php`、`RenderContextTest.php`。入れ子の描画の前後の兄弟で ID が重ならないこと、例外時にスタックは戻り、連番は戻さないこと (`resumePass` は大きいほうを残す。usePHP も同じ)。

## 9. 状態層のスコープ

**目的:** リクエストごとの状態層の値を 1 つにまとめ、本体の `RenderScope::$state` の slot に入れる。`RenderContext`、`RenderScope`、`RequestLifecycle` は [本体の 01](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md) の 9 節にある。この節は RT-9.4、RT-9.5 と、RT-9.6 の状態層の部分を定める。

```php
namespace Polidog\Rosanna\State;

final class StateScope
{
    public readonly RendererFactory $renderers;
    public readonly PsxRenderer $psx;
    public readonly ?SnapshotSerializer $snapshotSerializer;  // 秘密鍵がなければ null
    public readonly ActionSigner $signer;                     // 02。鍵がなければ sign() が null を返す
    public readonly string $signingScope;                     // 02 の SG-5、SG-6。page:{パス} か defer:{名前}

    /** @throws \LogicException 状態層のスコープでないとき */
    public static function of(RenderScope $scope): self;
    /** 描画中でないか、状態層のスコープでなければ null */
    public static function current(): ?self;
    public function isDeferredEndpointTarget(FunctionComponent $component): bool; // 02
}

final class RendererFactory
{
    public function create(
        string $rootId,
        ?StorageType $storage = null,
        ?SnapshotPersist $persist = null,
        ?string $deferQuery = null,
        ?string $deferName = null,
    ): ComponentRenderer;
    /** `Renderer` に差し込む拡張。描画ごとに作る */
    public function extension(string $rootId, ?string $deferQuery = null, ?string $deferName = null): ActionFormExtension;
}

final class ComponentRenderer
{
    /** @param callable(): mixed $component ラッパー付き */
    public function render(callable $component): string;
    /** @param callable(): mixed $component ラッパーなし (部分更新) */
    public function renderPartial(callable $component): string;
    public function renderElement(Element|string $element): string;
}
```

**振る舞い**

- **RT-9.4** 分割設計の `renderingDeferredEndpoint: bool` では足りない。入れ子の defer をプレースホルダーにするには「対象のコンポーネントかどうか」の判定が要るので、`StateScope` のメソッドにする
  (`usePHP/src/UsePHP.php:416`、`tests/Runtime/FunctionComponentTest.php` の `testRT62AtItsEndpointTheTargetRendersAndNestedDeferStaysAPlaceholder`)。
- **RT-9.5** `RendererFactory` は serializer、defer の prefix、CSRF トークン、署名を必ず揃えて `ComponentRenderer` を作る。Relayer の `LayoutRenderer` が `deferPrefix` を渡せない問題を解消する
  (分割設計「Relayer が現在…」)。`rootId` は action の `componentId` がないときの送信先になる (`Renderer.php:633`) ので、状態層にある。本体の `Renderer` は引数なしで使え、`rootId` を知らない。Renderer は描画時のバージョンを action の JSON に入れる (02 の VR-1)。ここでは `ActionFormExtension` が入れる。
- **RT-9.6** (状態層の部分) `bootstrap.php` が `RequestLifecycle::onReset('rosanna-state', ...)` で `ComponentState` のインスタンス、Storage、描画パス、セッションの消去を登録する。呼ぶのは本体の `RequestLifecycle::reset()` だけで、状態層に別の入口を作らない。セッションは書き込んで閉じ、ID を手放す。PHP は閉じた後も ID を覚えていて次の `session_start()` で Cookie より優先するので、閉じるだけでは次の訪問者が前のセッションに入る。次の開始では `$_COOKIE` の ID を使う。host が `session_id()` で決めた ID は変えない。

**エラー:** `RosannaState` でないスコープで `StateScope::of()` を呼ぶと `LogicException`。

**テスト**

- `RequestLifecycle::reset()` だけで state、描画パス、セッションが消えること。
- 本体の `Rosanna` で作ったスコープで `StateScope::of()` が例外になり、`current()` が `null` を返すこと。

## 10. ComponentRegistry

```php
namespace Polidog\Rosanna\State\Component;

final class ComponentRegistry
{
    /** @param class-string<ComponentInterface> $className */
    public function register(string $className): self;
    public function has(string $name): bool;
    /** @return class-string<ComponentInterface>|null */
    public function get(string $name): ?string;
    public function create(string $name): ?ComponentInterface;
    public function getStorageType(string $name): StorageType;
    public function getPersist(string $name): ?SnapshotPersist;
    public function getDefer(string $name): ?Defer;
    /** @return array<string, class-string<ComponentInterface>> */
    public function all(): array;
}
```

**振る舞い** (`usePHP/src/Component/ComponentRegistry.php`)

- **RT-10.1** 名前は `getComponentName()` とし、同じ名前の再登録は上書きする。`#[Defer]` と `persist` も上書きで消す。古いクラスの設定を引き継がないため。
- **RT-10.2** `getStorageType` は未登録と `#[Component]` なしのとき `Session` を返す。
- **RT-10.3** `create` は未登録なら `null`、それ以外は引数なしで `new` する。DI はしない。
  コンストラクタに引数を要するクラスは登録せず、host が `Element` を作って埋め込む。

**テスト:** `tests/Component/ComponentRegistryTest.php`。再登録で設定が残らないこと。

## 12. RosannaState の API と host への埋め込み

**目的:** 状態層の入口を 1 つにする。内部で `Rosanna` を持ち、描画の API は委譲する。URL からページを選ぶのは host の役割で、`run()` は作らない。`renderElement()` と `scope()` の本体側の要件 (RT-12.3、RT-12.8、RT-12.9) は [本体の 01](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md) の 12 節にあり、ここでは状態層で足す分を定める。

```php
namespace Polidog\Rosanna\State;

final class RosannaState
{
    // 登録
    /** @param class-string<ComponentInterface> $className */
    public function register(string $className): self;
    public function registerComponent(string $fqcn, callable $component): self;
    public function loadComponentManifest(string $path): self;
    public function setArgumentResolver(PsxArgumentResolver $resolver): self;

    // 描画
    public function render(string $componentName, ?string $key = null): string;
    public function createElement(string $componentName, ?string $key = null): Element;
    public function renderElement(Element $element): string;
    /** $deferName があれば defer エンドポイントの描画とし、署名の scope を defer:{名前} にする */
    public function scope(Request $request, ?string $deferName = null): RenderScope;

    public static function renderClientScript(string $src = '/rosanna.js', ?string $deferPrefix = null, ?string $nonce = null): string;

    // 02: setSnapshotSecret、actions、signer、snapshotSerializer、csrf、restoreSnapshots、handleDeferred など
}
```

**振る舞い**

- **RT-12.1** `render` と `createElement` の `$componentName` は、登録名とクラス名のどちらでもよく、結果は同じインスタンス ID と state になる
  (`usePHP/src/UsePHP.php:578-596`、`RosannaTest::testRT121ClassNameAndRegisteredNameAreTheSameInstance`)。
- **RT-12.2** 登録されていないものは `LogicException`。未登録のクラスを副作用で登録しない。失敗してもスコープとパスを壊さない。
- **RT-12.3** (状態層の部分) 3 つの入口の違いは次のとおり (`UsePHP.php:2813-2930`)。いずれも自分のスコープで実行し、親の state、外側のスコープを `finally` で戻す。

| 入口 | 出力 | パス |
|---|---|---|
| `render` | ラッパーの `div` 付き HTML | 新しいパスを始め、`resumePass` で戻す |
| `createElement` | ラッパー付きの `Element` | 現在のパスを続ける |
| `renderElement` | HTML。ルート ID は `_root_` | 現在のパスを続ける |

- **RT-12.4** ラッパーの属性は RT-6.4、RT-6.5 と同じ。Snapshot で秘密鍵がなければ `LogicException` で、秘密鍵の設定を案内する (`Renderer.php:1079`)。
- **RT-12.5** `register` は `#[Defer]` があるクラスの defer エンドポイントも登録する (02)。
- **RT-12.6** `renderClientScript` は `<script src>` を `defer` 付きで出し、`$src` をエスケープする。`$nonce` があれば、出力するすべての `script` に `nonce` を付ける。defer のフォールバックと `$deferPrefix` の扱いは 02
  (`UsePHP.php:78`、`tests/Runtime/ClientScriptTest.php`)。Relayer は固定のパスで読み込むので使っていない (`relayer/src/Router/Document/HtmlDocument.php:11`)。
- **RT-12.7** host への埋め込みの形は次のとおり。action の入口は `actions()->handle()` (02) で、描画より前に呼ぶ。POST を渡すこと、scope の中で描画すること、JS を配信すること、Snapshot なら秘密鍵を設定することは host の仕事で、`new RosannaState()` では済まない。

```php
$app = new RosannaState();
$app->register(Counter::class);

$response = $app->actions()->handle($request, replay: fn (bool $partial) => /* 02 */);
// action の POST でなければ描画に進む
echo $app->render(Counter::class);
```

- **RT-12.8** (状態層の部分) `fc()` を host が直接呼ぶ場合、または host が自分のレイアウトと組み合わせる場合は、`RenderContext::run($app->scope($request), $fn)` の中で Element を作り、`$app->renderElement()` か `StateScope::of(RenderContext::scope())->renderers` で描画する。`scope()` は `StateScope` を slot に入れた本体の `RenderScope` を返す。
- **RT-12.9** (状態層の部分) `render`、`createElement`、`renderElement` は、同じアプリのスコープがあればそのまま使う。別のアプリの `StateScope` があれば外側の署名の scope を使い、鍵と serializer は自分のものを使う。なければ `Request::fromGlobals()` からスコープを作る。この呼び出しは `RosannaState` が `Rosanna` に渡す scope factory の中にある。パスを書き換える host は RT-12.8 の形で自分の `Request` を渡す。

**テスト**

- クラス名と登録名で ID と state が同じこと、解決できない名前の例外 (`RosannaTest::testRT121*`、`testRT122*`)。
- 入れ子の `render` で外側のスコープ、state、パスが戻ること (`RosannaTest::testRT123*`、`NestedRenderRestoreTest.php`)。
- README の埋め込みの例を実際に動かすこと (`tests/Integration/ReadmeExamplesTest.php`)。
- `rosanna compile` だけの成果物と、`rosanna-state compile` の後に `rosanna compile` を実行した成果物で `loadComponentManifest()` が例外になること (02 の DF-13、本体 03 の CP-136、CP-144)。

## 13. bin/rosanna-state と AssetInstaller

**目的:** `rosanna.js` をプロジェクトの `public/` に配置する。Relayer の `relayer init` が書く Composer スクリプトもこれを呼ぶ
(`relayer/src/Scaffold/Scaffold.php:100`)。`vendor/bin/rosanna` は本体の 13 節にある。Composer で同じ bin 名は共存できないので、名前を分ける。

```php
namespace Polidog\Rosanna\State\Installer;

final class AssetInstaller
{
    public static function publish(\Composer\Script\Event $event): void;
}
```

```
vendor/bin/rosanna-state publish           public/rosanna.js に配置する
vendor/bin/rosanna-state compile [...]     CompileCommand に DeferredManifestCompiler を渡して呼ぶ (本体 03 の CP-136)
vendor/bin/rosanna-state help              ヘルプを表示する
```

**振る舞い** (`usePHP/bin/usephp`、`usePHP/src/Installer/AssetInstaller.php`)

- **RT-13.1** `bin/rosanna-state` は `../vendor/autoload.php` と `../../../autoload.php` の順で読み込む。
- **RT-13.2** `publish` の配置先はプロジェクトルートの `public/rosanna.js` とする。プロジェクトルートは、パッケージの 3 階層上の `vendor` の親とする。
  ディレクトリがなければ `0755` で作る。既存のファイルは上書きする。
- **RT-13.3** パッケージのルートで実行した場合 (3 階層上が `vendor` でない) は、案内を出して何もしない。
- **RT-13.4** `publish` の失敗 (元のファイルがない、ディレクトリを作れない、コピーできない) は標準エラーに出して終了コード 1 にする。
- **RT-13.5** `AssetInstaller::publish` は Composer の `vendor-dir` 設定から配置先を求める。失敗は警告にとどめ、例外を投げない。`composer install` を止めないため。
- **RT-13.6** `Composer\Script\Event` の型は Composer の中でしか解決されない。ランタイムの `require` に `composer/composer` を足さない。静的解析のためには `require-dev` に入れる。
- **RT-13.7** `compile` は `CompileCommand` を直接呼ぶ。`polidog/rosanna` は必須の依存なので、存在の確認も案内もしない (本体 03 の CP-140)。
- **RT-13.8** `public/rosanna.js` を配布物に含める。`.gitattributes` の `export-ignore` に入れない。
- **RT-13.9** 未知のコマンドはヘルプを出して終了コード 1 にする。

**テスト:** 一時ディレクトリに `vendor/polidog/rosanna-state` の配置を作り、次を確かめる。

- 配置先の作成と上書き、パッケージのルートでの案内。
- コピー失敗で終了コードが 1 になること、`AssetInstaller` は例外を投げないこと。
- 依存として入った配置から `compile` が動くこと。

## Relayer と決定の記録

Relayer の置き換えは本体の [14 節](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md#14-relayer-が使っている箇所の置き換え)、決定の記録は本体の 01 の末尾にある。分離の前に書いたものなので、本文の名前はこの文書の名前に読み替える。
