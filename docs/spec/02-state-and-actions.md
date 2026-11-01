# 02 状態とアクションの仕様

Status: Draft (2026-11-01、usePHP v0.10.0 / Relayer main 9566b0c で照合)

Rosanna の状態管理、Action、署名、CSRF、defer の入口、クライアントの `rosanna.js` の振る舞いを定める。

前提は[計画書](../design/rosanna-split-and-rename.md)。コードはコピーせず、ここに書いた振る舞いを新しく実装する。根拠の
`usePHP/...` は参照元の場所で、`README` は `usePHP/README.ja.md` を指す。

別の仕様の範囲:

- `01-runtime.md`: `H`、Renderer、Component、hooks、描画スコープ (`RenderScope`)、本体の描画 API、publish
- `03-compiler.md`: PSX、コンパイラ (`src/Psx/`)

要件には ID を振る。テストの項目はこの ID を参照する。

## 0. 共通

### 持たないもの

ルーター、イベント、DI、PSR-7 は持たない。usePHP で次のものに頼っていた処理は、それぞれの節で置き換える。

| usePHP | Rosanna | 節 |
|---|---|---|
| ルートの `SnapshotBehavior` | host が `handle()` と復元の呼び出しで指定する | 2.5 |
| `RouterInterface::createRedirectUrl()`、`extractSnapshot()` | `SnapshotUrl` | 2.4 |
| `resolveActionPageMatch()` によるルートのリプレイ | `handle()` の `replay` | 7 |
| `guardActionPage()` のルートミドルウェア | host が `handle()` より前に確かめる | 7 |
| `/_defer/{name}` のルーティング | host が `handleDeferred()` を呼ぶ | 8 |

### リクエストと応答

HTTP の入出力は独自の値オブジェクトで受ける。グローバルの `$_POST` や `header()` を処理の途中で直接触らない。

```php
namespace Polidog\Rosanna\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $cookies
     * @param array<string, string> $headers 小文字のヘッダー名 => 値
     */
    public function __construct(
        public string $method,
        public string $uri,          // リクエスト行のまま。クライアントが決める値
        public string $path,
        public string $queryString,
        public array $query = [],
        public array $post = [],
        public array $cookies = [],
        public array $headers = [],
        public bool $https = false,
    ) {}

    public static function fromGlobals(): self;

    public function pagePath(): string; // 署名がページとして扱う単位のパス (SG-5)。host の保護はこれで比べる
}

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers = [], public string $body = '') {}

    public function send(): void;
}
```

- **RQ-1** `fromGlobals()` は壊れた `REQUEST_URI` でも例外を投げない (`SecurityHardeningTest::testFromGlobalsToleratesMalformedRequestUri`)。
- **RQ-2** `Response` を返す処理は `header()`、`echo`、`exit`、`http_response_code()` を呼ばない。送信するのは host か
  `send()` だけ。`withExitHandler`、`withHeaderEmitter` は作らない。例外は PHP の `session_start()` が送る Cookie で、
  これは PHP 自身が送る。
- **RQ-3** リクエストごとの情報を `Rosanna` のインスタンスに残さない。Relayer のワーカーは同じインスタンスで別のリクエストを
  処理する (`relayer/src/Router/ActionReplayRouter.php:23`)。

### 識別子

| 用途 | 名前 |
|---|---|
| フォーム | `_rosanna_component`、`_rosanna_action`、`_rosanna_base[{index}]`、`_rosanna_csrf`、`_rosanna_snapshot`、`_rosanna_snapshots[]`、`_rosanna_defer_query` |
| 属性 | `data-rosanna`、`data-rosanna-form`、`data-rosanna-snapshot`、`data-rosanna-snapshot-update`、`data-rosanna-persist`、`data-rosanna-restore-sig`、`data-rosanna-on-*`、`data-rosanna-action-*`、`data-rosanna-with-value`、`data-rosanna-error`、`data-rosanna-reload-defer`、`data-rosanna-defer-*` |
| ヘッダー | `X-Rosanna-Partial`、`X-Rosanna-Defer`、`X-Rosanna-Stale`、`X-Rosanna-Session-Started` |
| セッション | `_rosanna_csrf`、`_rosanna_action_key`、`_rosanna_snapshot` |
| 状態のキー | `rosanna:{instanceId}:state:{index}`、`rosanna:{instanceId}:effect_deps:{index}`、`rosanna:{instanceId}:version` |
| Web Storage | `rosanna:snapshot:`、`rosanna:defer:` |
| JS | `window.Rosanna`、イベント `rosanna:submit-error`、`rosanna:stale` |
| ログの接頭辞 | `[Rosanna]` |

クエリのパラメーターも `_rosanna_snapshot` とする。POST のフィールドと同じ名前だが、クエリと POST は別に読むので衝突しない。

### 上限

| 名前 | 値 | 用途 | 根拠 |
|---|---|---|---|
| フックの index | 0〜255 | 外部から受ける index | `usePHP/src/UsePHP.php:810` |
| セッションのスナップショット | 64 件/スロット | 2.5 | `usePHP/src/UsePHP.php:819` |
| 運ばれるスナップショット | 256 件/リクエスト | 2.3 | `usePHP/src/UsePHP.php:826` |
| フォーム欄 | 65,535 バイト/欄 | `setFields` | `usePHP/src/UsePHP.php:831` |
| インスタンス ID | 255 バイト | 3 | `usePHP/src/UsePHP.php:1032` |

## 1. Storage

### 目的

コンポーネントの状態の置き場所を 3 種類提供する。`useState` のフックの index ごとに値を持つ。

### 公開 API

名前空間は usePHP の下位を保つ (`Polidog\UsePhp` を置換するだけで移行できるようにする)。

```php
namespace Polidog\Rosanna\Storage;

enum StorageType: string { case Session = 'session'; case Memory = 'memory'; case Snapshot = 'snapshot'; }
enum SnapshotPersist: string { case SessionStorage = 'sessionStorage'; case LocalStorage = 'localStorage'; }

interface StateStorageInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function has(string $key): bool;
    public function delete(string $key): void;
    public function clearByPrefix(string $prefix): void;
}

namespace Polidog\Rosanna\Runtime;

final class ComponentState
{
    public static function getInstance(string $componentId, ?StorageType $storageType = null): self;
    public static function fromSnapshot(Snapshot $snapshot): self;
    public static function current(): ?self;
    public static function setCurrent(?self $state): void;
    public static function reset(): void;
    public static function clearInstances(): void;

    public function nextHookIndex(): int;
    public function getState(int $index, mixed $initial): mixed;
    public function setState(int $index, mixed $value): void;
    public function version(): int;
    public function createSnapshot(): Snapshot;   // Snapshot 以外は LogicException
    public function isSnapshotStorage(): bool;
    public function getStorageType(): StorageType;
}
```

`getInstance`、`reset`、`clearInstances` は Relayer が使う (`relayer/src/Router/AppRouter.php:1477`、`relayer/src/Relayer.php:142`)。
リクエストの間の掃除は `RequestLifecycle::reset()` にまとめる (`01-runtime.md` の RT-9.6)。

### 振る舞い

- **ST-1** Session は `$_SESSION`、Memory はリクエスト内の配列、Snapshot はリクエスト内の配列とページの署名済みスナップショット
  に置く (`usePHP/src/Storage/StorageType.php`)。
- **ST-2** Session の読み取りはセッションを開始しない。未開始でセッション Cookie もなければ空として扱い、Cookie があれば再開して
  読む。書き込みで初めて開始する。**usePHP #120 の修正**で、usePHP はすべての操作で開始していた
  (`usePHP/src/Storage/SessionStorage.php:21-47`)。
- **ST-3** `getState()` は初期値を保存しない。保存がなければ `$initial` を返すだけとする。usePHP は保存していて
  (`usePHP/src/Runtime/ComponentState.php:122`)、ST-2 を保つとこの書き込みが読み取りのたびにセッションを開始してしまう。
  Snapshot はスナップショットに初期値が必要なので、`createSnapshot()` のためにリクエスト内の配列には記録する。
- **ST-4** `new` や `getInstance()` だけではセッションを開始しない (`usePHP/src/Storage/SessionStorage.php:10-15`)。
- **ST-5** 同じ ID の `ComponentState` はリクエスト内で 1 つ。`fromSnapshot()` は Snapshot の内容で置き換える。
- **ST-6** 状態の値は `null`、スカラー、それらの配列だけ (AC-4)。Closure をストレージに書かない。effect の cleanup は
  インスタンスのメモリにだけ置く (`usePHP/src/Runtime/ComponentState.php:28-35`)。
- **ST-7** Snapshot のストレージはインスタンスごとに分かれ、`exportState()` は index の順に並べる
  (`usePHP/src/Storage/SnapshotStorage.php:81`)。

### エラー

- Snapshot 以外への `createSnapshot()` は `LogicException`。
- セッションを開始できないときの書き込みは `LogicException`。出力の後に Session の状態を書こうとしたバグを隠さない。
- セッションは PHP のネイティブセッションだけを使う。`SessionInterface` は作らない。

### テスト

- ST-2: Cookie のない GET で `useState` を読んでも `Set-Cookie` が出ず、セッションができない。
- ST-2: Cookie のある GET で保存済みの値が読める。
- ST-3: 匿名の訪問者の読み取りが `$_SESSION` に何も書かない。
- ST-2: action の適用で初めて開始され、次の GET で値が読める (`SessionBootstrapTest::testSessionStateSurvivesTwoFullPagePosts`)。
- ST-6: Closure を持つ effect の cleanup があってもセッションの書き込みが失敗しない。

## 2. Snapshot

### 目的

Snapshot ストレージの状態をページに置いて往復させる。サーバーに状態を持たない。HMAC は改ざんを防ぐが、読み取りと再生は防がない
(README 1195-1197 行)。

### 公開 API

```php
namespace Polidog\Rosanna\Runtime;

final readonly class Snapshot
{
    /**
     * @param array<int, mixed> $state
     * @param array<int, array<mixed>|null> $effectDeps
     */
    public function __construct(
        public string $componentName,
        public string $key,
        public array $state = [],
        public array $effectDeps = [],
        public int $version = 0,
        public ?string $checksum = null,
    ) {}

    public function getInstanceId(): string;   // "{componentName}#{key}"
    public function toJson(): string;
    public static function fromJson(string $json): self;
}

namespace Polidog\Rosanna\Snapshot;

final class SnapshotVerificationException extends \RuntimeException {}

final class SnapshotSerializer
{
    public function __construct(string $secretKey, string $context = '');   // 空の鍵は InvalidArgumentException
    public function serialize(Snapshot $snapshot): string;
    /** @throws SnapshotVerificationException */
    public function deserialize(string $json): Snapshot;
    public function signString(string $payload): string;
    public function verifyString(string $payload, string $signature): bool;
    public function withContext(string $context): self;
}

// Rosanna 本体
public function setSnapshotSecret(string $secretKey): self;
public function snapshotSerializer(): ?SnapshotSerializer;
```

### 2.1 署名

- **SN-1** 形式は `{"memo":{"name","key","version"},"state","effectDeps","checksum"}` の JSON。usePHP の形に `version` を足す
  (`usePHP/src/Runtime/Snapshot.php:76`)。
- **SN-2** `checksum` は `name`、`key`、`version`、`state`、`effectDeps` の JSON に対する HMAC-SHA256。文脈があれば JSON の先頭に
  `ctx` を入れる。JSON のオプションは署名の前後で一定にし、float の `.0` を保つ
  (`usePHP/src/Snapshot/SnapshotSerializer.php:119-136`)。
- **SN-3** 比較は `hash_equals`。`checksum` のないものは常に失敗 (`usePHP/src/Snapshot/SnapshotSerializer.php:145`)。
- **SN-4** 16 バイト未満の鍵を受け付けない。署名なしのシリアライザーを作らない (`usePHP/src/Snapshot/SnapshotSerializer.php:57`)。署名は HTML に出るので、短い鍵は手元で総当たりでき、復元されると action もスナップショットも偽造できる。長さしか確かめられないので、乱数で作るのは利用者の役割。
- **SN-5** `deserialize()` は壊れた入力 (JSON でない、オブジェクトでない、`memo` がない、`version` が整数でない) も
  `SnapshotVerificationException` にする。呼び出し側が扱う例外はこれだけとする。**コミット `2ec7012`**
  (`usePHP/src/Snapshot/SnapshotSerializer.php:87-101`)。
- **SN-6** `withContext($ctx)` は別の文脈で検証に失敗する。空の文脈との間でも失敗する。`signString()` の文脈は長さつきで前に付け、
  区切りを曖昧にしない (`usePHP/src/Snapshot/SnapshotSerializer.php:179-186`)。
- **SN-7** 検証なしで読む公開メソッドは作らない。usePHP の `deserializeWithoutVerification()` は作らない。
- **SN-8** スナップショットに期限はない。鍵か、文脈つきならセッション ID が変わるまで有効 (`usePHP/src/Snapshot/SnapshotSerializer.php:41`)。
- **SN-9** `snapshotSerializer()` は秘密鍵がなければ `null` を返す。例外を投げない。usePHP の Relayer は
  `getSnapshotSerializer()` の `LogicException` を握りつぶしていた (`relayer/src/Router/AppRouter.php:1541-1545`)。
- **SN-10** Snapshot ストレージのコンポーネントを秘密鍵なしで描画すると `LogicException` を投げる。署名なしの状態を出力しない
  (`usePHP/src/Runtime/Renderer.php:1077-1085`、`usePHP/src/Runtime/FunctionComponent.php:90-100`)。

### 2.2 ページへの埋め込み

- **SN-11** ラッパーは `data-rosanna-snapshot` に署名済み JSON を持つ。各 action フォームは `_rosanna_snapshot` にも持つ。JavaScript なしの
  POST で必要なため (`usePHP/src/Runtime/Renderer.php:360`, `:655`)。ボタンのフォームは共有する (`01-runtime.md` の RT-4.7) ので、一覧でもインスタンスごとに 2 か所で済む。
  ラッパーの属性は省かない。action のないコンポーネントにはフォームがなく、JS-3、JS-12、SN-19 が属性を読む。
- **SN-12** 部分更新の応答は `data-rosanna-snapshot-update` の hidden で新しいスナップショットを渡し、クライアントはこれをラッパーに写して
  DOM から取り除く (`usePHP/src/Runtime/Renderer.php:390`、`usePHP/public/usephp.js:1378`)。

### 2.3 運ばれるスナップショット

`_rosanna_snapshots[]` は、再描画が届く他のインスタンスの状態を運ぶ (`usePHP/src/UsePHP.php:2395-2460`)。

- **SN-13** 全要素を検証してから 1 つでも復元する。1 つでも失敗すれば、リクエスト全体を 400 にする。
- **SN-14** 上限を超えた分は読まず、ログに出す。その分のインスタンスは状態を失うが、action は処理する。
- **SN-15** ID を別に受け取らない。署名された `memo` の指すインスタンスにだけ復元する。
- **SN-16** 操作対象のインスタンスを指すものは無視する。対象の状態は `_rosanna_snapshot` と action から作る。
- **SN-17** 秘密鍵がないのに空でない配列を受けたら 400。
- **SN-18** `fc()` の replay の部分更新では、操作対象の子孫以外を指すものがあれば 400。祖先の props を通じてクライアントが選んだ状態が出力に届くのを
  防ぐ。ツリーにない ID は問題にしない。action で隠れた子孫がこの形になるため (`usePHP/src/UsePHP.php:2562-2567`, `:2619-2627`)。
  探索が対象を見つけた後も、replay が描画したすべての根を調べる。登録クラスの部分更新では検査しない。描画するのは対象の中だけなので、祖先の状態が届かない。
  `X-Rosanna-Partial` を外せばこの検査は通らない。SN-19 が運搬を必要とするため。祖先の props でスナップショットの値を信頼しないことを README に書く。
- **SN-19** JavaScript なしの全ページ応答は、各 action フォームにページ上の検証を通るスナップショットを hidden で足す。ラッパーが 2 つ未満なら
  足さない。`script` と `style` の中の同じ形の文字列を拾わない。検証を通らない値は運ばない (`usePHP/src/UsePHP.php:2343-2382`)。

### 2.4 URL での運搬

```php
namespace Polidog\Rosanna\Snapshot;

final class SnapshotUrl
{
    public const PARAM = '_rosanna_snapshot';

    /** @throws \InvalidArgumentException 同一オリジンのパスでないとき */
    public static function with(string $url, Snapshot $snapshot, SnapshotSerializer $serializer): string;
    public static function extract(Request $request): ?string;
    public static function removeParam(string $query): string;
}
```

ルーターの `createRedirectUrl()`、`extractSnapshot()` の移し先 (計画書「ルーターを持たない」)。

- **SN-20** `with()` は `//host/...`、host つき、scheme つき (`javascript:` を含む)、解釈できない URL を拒否する
  (`usePHP/src/Router/SimpleRouter.php:237-263`)。単一の `/` で始まり、制御文字と `\` がないものは同一オリジンのパスとして受け、`parse_url()` に渡さない。`/item:42` を `parse_url()` が読めないため (HD-14)。
- **SN-21** 他のクエリはバイト単位で保つ。`parse_str()` で組み立て直さない。`%5Frosanna_snapshot`、`_rosanna_snapshot[]` も `_rosanna_snapshot` として
  取り除く (`usePHP/src/Router/SimpleRouter.php:265-295`)。
- **SN-22** `extract()` は配列の `?_rosanna_snapshot[]=1` を `null` にする (`usePHP/src/Router/SimpleRouter.php:297-303`)。

### 2.5 置き場所の指定

usePHP はルートで `SnapshotBehavior` を宣言していた。ルーターがないので、host が呼び出しで指定する。Relayer はどの挙動も宣言しておらず、
既定の Isolated だけを使っている。Shared はルートのグループの概念なので作らない。`SimpleRouter::setSnapshotParam()` の移し先も作らず、`_rosanna_snapshot` に固定する。

```php
namespace Polidog\Rosanna\Snapshot;

enum SnapshotBehavior: string { case Isolated = 'isolated'; case Persistent = 'persistent'; case Session = 'session'; }

// Rosanna 本体。GET の描画の前に呼ぶ。Isolated では何もしない
public function restoreSnapshots(Request $request, SnapshotBehavior $behavior = SnapshotBehavior::Isolated): void;
```

| 挙動 | 読む場所 | 書く場所 (action の後) | 署名の文脈 |
|---|---|---|---|
| Isolated | ページの中 | なし | なし |
| Persistent | `?_rosanna_snapshot=` | redirect の URL | なし |
| Session | `$_SESSION['_rosanna_snapshot']` | 同じ | セッション ID |

- **SN-23** Session は URL の `_rosanna_snapshot` を読まず、ログに出す。別のセッションで得た署名済みのスナップショットをリンクで
  植え付けられるのを防ぐ (`usePHP/src/UsePHP.php:1470-1488`)。POST の経路は対象外である。CSRF が効くので影響は自分のセッションだけで、AC-12 の範囲とする。README に書く。
- **SN-24** Session はインスタンス ID => 署名済み JSON の map で持つ。読むときに全件を検証し、別のセッションの署名、別の ID に
  保存されたもの、壊れたものは捨ててログに出す。その場合もページは描画する (`usePHP/src/UsePHP.php:1533-1564`)。
- **SN-25** 上限を超えると書き込みが最も古いものを捨てる。捨てたインスタンスは初期状態に戻り、ログに出す (`usePHP/src/UsePHP.php:1572-1592`)。
- **SN-26** Persistent が運ぶのは 1 インスタンスだけ。別のインスタンスへの action が置き換えたらログで警告する
  (`usePHP/src/UsePHP.php:1601-1623`)。
- **SN-27** Persistent の壊れたスナップショットは捨ててログに出し、ページを初期状態で描画する (`MalformedSnapshotRequestTest::testBrokenSnapshotOnPersistentRouteRendersThePageWithoutState`)。
- **SN-28** Isolated は `_rosanna_snapshot` を読まない。
- **SN-29** defer のエンドポイントはスロットを読まない (README 339 行)。

### 2.6 クライアントでの永続化と `restore`

- **SN-30** `persist` を指定したコンポーネントだけ、ページの読み込み時に保存済みのスナップショットで `restore` を送る。指定がなければ
  リロードで初期値に戻る (README 566 行)。バージョン照合の写しとの違いは VR-6。
- **SN-31** `restore` の条件は AC-8。

### エラー

- 検証の失敗はすべて `SnapshotVerificationException`。POST の `_rosanna_snapshot` と `_rosanna_snapshots[]` では 400、GET の復元では
  ログに出して状態なしで描画する。
- `with()` の拒否は `InvalidArgumentException`。

### テスト

- SN-3、SN-4: 空の鍵、`checksum` なし、改ざんした `state`、`version`、`memo` を拒否する。
- SN-5: 壊れた入力の表 (空文字、スカラー、配列の `memo`、整数でない `version`) がすべて同じ例外になる。
- SN-6: 文脈 A の署名が文脈 B と空の文脈で失敗する。
- SN-9、SN-10: 秘密鍵なしで `snapshotSerializer()` が `null`、Snapshot コンポーネントの描画が例外。
- SN-13〜18: `NestedSnapshotPartialTest` の項目 (検証の失敗、外のインスタンス、祖先、対象自身、ツリーにない ID、上限、秘密鍵なし)。
- SN-19: `IsolatedSnapshotFallbackTest`、`SnapshotReplayGuardsTest::testTextThatLooksLikeRendererOutputIsNotCarried`。
- SN-20〜22: 外部 URL の拒否、クエリの保持、`%5Frosanna_snapshot` の除去、配列の無視。
- SN-23: 同じセッションで署名したものでも `?_rosanna_snapshot=` が無視される (`SecurityHardeningTest::testSessionRouteIgnoresEvenASessionBoundSnapshotFromTheQueryString`)。
- SN-24: 別のセッションの署名、壊れた値がページを壊さない (usePHP の `testSharedSnapshotSignedForAnotherSessionIsRejected` を Session の挙動に書き換える、`testCorruptSnapshotInTheSessionIsIgnored`)。
- SN-25、SN-26: 上限と置き換えのログ。

## 3. Action

### 目的

サーバーが描画した状態の変更をブラウザに持たせ、そのまま送り返させる。**利用者のコードを動かさない。**

### 公開 API

```php
namespace Polidog\Rosanna\Runtime;

final readonly class Action
{
    public const SET_STATE = 'setState';
    public const SET_FIELDS = 'setFields';
    public const RESTORE = 'restore';

    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $componentId = null,
        public ?StorageType $storageType = null,
        public ?int $version = null,
    ) {}

    public static function setState(int $stateIndex, mixed $value, ?string $componentId = null, ?StorageType $storageType = null): self;
    /** @param array<mixed> $patch StateDiff::create() の戻り値 */
    public static function setStateDiff(int $stateIndex, array $patch, string $base, ?string $componentId = null, ?StorageType $storageType = null): self;
    /** @param array<string, int> $fields フィールド名 => フックの index */
    public static function setFields(array $fields, ?string $componentId = null, ?StorageType $storageType = null): self;
    public static function restore(?string $componentId = null, ?StorageType $storageType = null): self;
    /** Renderer が描画時のバージョンを入れる (VR-1) */
    public function withVersion(int $version): self;

    /** @throws \InvalidArgumentException */
    public function toJson(): string;
    /** @throws \InvalidArgumentException */
    public static function fromArray(array $data): self;
    /** @throws \InvalidArgumentException */
    public static function assertJsonRoundTrippable(mixed $value, string $path = '', int $depth = 0): void;
}

final class StateDiff
{
    /** @return array<mixed>|null 差分。使えないときは null (AC-13) */
    public static function create(mixed $base, mixed $next): ?array;
    /** @param array<mixed> $patch */
    public static function apply(mixed $base, array $patch): mixed;
    public static function hash(mixed $value): string;
}

namespace Polidog\Rosanna\Protocol;

final readonly class ActionEnvelope
{
    public function __construct(public string $actionJson, public ?string $signature) {}

    public function toJson(): string;
    /** JSON オブジェクトでなければ null。形が不正なら InvalidArgumentException */
    public static function decode(string $json): ?self;
}
```

`useState` のセッターと `useFormAction()` が作る (`01-runtime.md`)。`version` は描画時に Renderer が入れる (VR-1)。

### 振る舞い

| 種類 | payload | 適用 | 署名の対象 |
|---|---|---|---|
| `setState` | `index`、`value` | フックに値を書く | JSON 全体 |
| `setState` (差分) | `index`、`patch`、`base` | 基準の値に差分を適用して書く (3.1) | JSON 全体 |
| `setFields` | `fields` (名前 => index) | POST の値をフックに書く | JSON 全体。POST の値は対象外 |
| `restore` | 空 | 変更なし。スナップショットで描き直す | 固定の入力 `restore` |

- **AC-1** 種類は `setState`、`setFields`、`restore` の 3 つだけ。署名が通っても他の種類は 400。usePHP は未知の種類を何もせず通した。
- **AC-2** `setState` の値は描画時に確定する。`$set($count + 1)` は「足す」ではなく「その値にする」。計画書のとおりこの設計は変えない
  (`usePHP/src/Runtime/Action.php:125`)。差分は運び方の違いで、適用の結果は描画時に確定している。
- **AC-3** `fromArray()` は型をすべて確かめ、`TypeError` にしない。`type` は空でない文字列、`payload` は配列、`componentId` は文字列か
  `null`、`storageType` は既知の値、`version` は整数か `null` (`usePHP/src/Runtime/Action.php:164-191`)。`setState` の `payload` は `value` か、配列の `patch` と文字列の `base` の組のどちらか一方を持つ。
- **AC-4** `toJson()` は往復で形が変わる値を拒否する。オブジェクト、`JsonSerializable`、`NAN`、`INF`、512 段を超える入れ子、循環。エラーに値の
  場所を出す (`usePHP/src/Runtime/Action.php:91-123`)。
- **AC-5** `index` は 0〜255 の整数 (`usePHP/src/UsePHP.php:2192`)。
- **AC-6** インスタンス ID は 255 バイト以下、`#` を含み、制御文字を含まない。署名が束縛するので、ここではストレージのキーとログを守るだけ
  (`usePHP/src/UsePHP.php:1030-1035`)。
- **AC-7** `setFields` は全欄を検証してから書く。名前と index は署名済みで、値はクライアント入力。送られなかった欄は空文字で、チェックを外した
  checkbox がフックを空にするため。文字列でない、65,535 バイトを超える、不正な UTF-8 の値は 400 で何も書かない
  (`usePHP/src/UsePHP.php:2468-2497`)。
- **AC-8** `restore` は `rosanna.js` が組み立てるので JSON を署名できない。代わりに次をすべて要求する (`usePHP/src/UsePHP.php:2120-2140`)。
  - `_rosanna_snapshot` がある。
  - `payload` が空。
  - `componentId` が `null` か、対象のインスタンスと同じ。
  - `data-rosanna-restore-sig` の署名が `restore` の入力で通る。ページがそのインスタンスを描画した証拠で、古いスナップショットで保護された
    コンポーネントを公開ページに呼び出すのを防ぐ。
- **AC-9** `restore` の署名は JSON にならない固定文字列 `restore` に対して計算する。action の署名と混同できない
  (`usePHP/src/UsePHP.php:833-840`)。
- **AC-10** `restore` 以外は、`componentId` が `null` か `_rosanna_component` と同じであること (`usePHP/src/UsePHP.php:2154`)。
- **AC-11** ストレージの種類は、`restore` なら Snapshot、それ以外は署名された `storageType`、`null` なら登録されたクラスの宣言、それもなければ
  Session (`usePHP/src/UsePHP.php:2160-2161`)。
- **AC-12** `useState` の値はクライアント入力である。ユーザーは自分に描画された action を何度でも送れる。認可の判定や金額をフックの状態から作らないことを
  ドキュメントに書く (README 1185 行)。

### 3.1 差分 (#87)

一覧全体を 1 つの state にすると、ボタンごとに全体の値が HTML に入り、サイズが行数の 2 乗で増える。利用者のコードを変えず、差分で運ぶ。計画書「#87 への対応」のとおり。

- **AC-13** setter は描画時のフックの値と新しい値から `StateDiff::create()` で差分を作る。適用の結果が新しい値と `===` で一致し、差分が全体より小さいときだけ `setStateDiff()` を返し、それ以外は `setState()` を返す。
  キーの順序などの細かな場合分けは一致の確認に任せる。大きさの比較は基準の値の JSON の長さで近似してよく、setter のたびに新しい値全体を JSON にしない。
- **AC-14** 差分の形式は次のとおり。リストは共通の先頭と末尾を除いて比べる。先頭行の削除で全行の添字がずれても、差分が膨らまない。

| 形式 | 意味 | 使う場面 |
|---|---|---|
| `["r", 値]` | 値を置き換える | 配列でない値、形の違う値 |
| `["l", 位置, 差分]` | リストの 1 要素に差分を適用する | 共通部分を除いて 1 要素ずつ残る場合 |
| `["s", 開始, 削除数, 要素の配列]` | `array_splice` | それ以外のリスト |
| `["m", {キー: 差分}, [削除するキー]]` | キーの設定と削除 | リストでない配列 |

- **AC-15** `base` は描画時のフックの値の `StateDiff::hash()`。AC-4 の往復を保つ JSON のオプションで作った JSON の SHA-256 とする。フックごとに描画で 1 回だけ計算する。`base` は action の JSON に入るので署名され、`ActionSigner` の引数は変わらない。
  インスタンスの `version` では足りない。未保存の初期値はリクエストごとに変わることがあり、Snapshot は 2 つのタブが同じ版から分岐すると、番号が同じで中身の違う状態ができる。
- **AC-16** 適用の基準の値は次から取る。

| 保存先 | 基準の値 |
|---|---|
| Snapshot | 検証済みの `_rosanna_snapshot` の `state` |
| Session | 保存値。なければ `_rosanna_base[{index}]` の JSON |
| Memory | `_rosanna_base[{index}]` の JSON。同じリクエストの保存値があればそれ |

- **AC-17** 基準の値の `hash()` が `base` と違うか、基準の値がなければ、**適用しない**。バージョンも変えない。応答は VR-4 と同じで、最新の状態を描き直す。4xx にしない。古い状態に適用すると、誰も描画していない混ざった状態ができるため。Session では VR-4 の照合が先に動く。
- **AC-18** Renderer は、差分を持つ action の対象のフックに保存値がない Session と、常に Memory のときに、そのフォームに `_rosanna_base[{index}]` を出す。ST-3 により初期値は保存されず、匿名の訪問者のセッションを始めずに基準を渡すため (ST-2)。Memory はリクエストを越えて保存されないので、描画時に保存値があっても次の POST の基準にならない。署名しない。署名済みの `base` と比べるので、改ざんは AC-17 で適用されない。
- **AC-19** 次は 2 乗のまま残る。README の既知の制約に書く。
  - Snapshot で行ごとに `onSubmit` フォーム、`input[type=submit|image]`、利用者の `name` か `value` を持つ `button` を置く形。個別フォームごとにスナップショットが入る (`01-runtime.md` の RT-4.9)。
  - 並べ替えのように全体が変わる操作を各行に置く形。全体の値が入る。
  - Snapshot の行コンポーネントへの JavaScript なしの全ページ応答。SN-19 が各フォームに他のインスタンスのスナップショットを足す。
  - CPU 時間。`onClick` のクロージャは描画時に実行する。

### 3.2 エンベロープ

- **AC-20** `_rosanna_action` の値は `{"action":"<actionJson>","sig":"<署名>"}` の JSON とする。送信ボタンは `name` と `value` を 1 組しか送れないので、action と署名を 1 つにまとめる。`actionJson` は署名の対象のバイト列を保つため、文字列のまま入れる。
  すべてのフォームで同じ形とし、usePHP の `_usephp_action_sig` に当たる欄は作らない。
- **AC-21** `restore` では `rosanna.js` が同じ形を組み立て、`sig` に `data-rosanna-restore-sig` を入れる。
- **AC-22** エンベロープは JSON オブジェクトなので、「JSON オブジェクトでない `_rosanna_action` は無視する」という HD-1 の扱いは変わらない。

### エラー

形の問題はすべて 400 で `Cache-Control: no-store` (HD-5)。`toJson()` の拒否は描画時の `InvalidArgumentException`。基準の不一致はエラーではない (AC-17)。

### テスト

- AC-1: 署名された未知の種類が 400 で状態を変えない。
- AC-3: 不正な形の表が `TypeError` を出さず 400 になる (`testMalformedActionIsBadRequestNotTypeError`)。
- AC-4: `ActionJsonRoundTripTest` の項目。
- AC-5、AC-6: 範囲外の index、不正な ID が 400 (`testInvalidInstanceIdIsBadRequest`)。
- AC-7: 複数欄のうち 1 つが不正なら何も書かれない。送られなかった欄が空文字になる (`FormActionTest`)。
- AC-8: `RestoreRequiresASnapshotForTheSameInstance`、`RestoreOfAGuardedComponentIsRejectedOnAnOpenPage`、`RestoreWithoutItsSignatureIsRejected`。
- AC-13: `apply(base, create(base, next))` が `next` と `===` で一致する。ランダムな値で、キーの順序と JSON の往復を含む。一致しない場合と大きくなる場合は `value` になる。
- AC-14: トグル、`array_values` と `array_filter` の削除、先頭への追加で、差分が行数によらず小さい。
- AC-17: Session、Memory、Snapshot のそれぞれで、`base` が違う差分が適用されず、バージョンも変わらず、描き直される。
- AC-18: Cookie のない GET の差分のフォームに `_rosanna_base` が出て、`Set-Cookie` が出ない。その POST で適用され、次の描画には出ない。
- AC-18: 改ざんした `_rosanna_base` が適用されない。
- AC-20: JavaScript なしのボタンの送信で action が届く。`actionJson` を 1 バイト変えると署名で 400。

## 4. 署名

### 目的

ブラウザに渡した action を、サーバーが描画したものだけに限る。インスタンス、JSON、セッション、POST 先を束縛する。

### 公開 API

```php
namespace Polidog\Rosanna\Protocol;

final class ActionSigner
{
    /** 鍵がなければ null。$pagePath で別のページを指定できる */
    public function sign(string $componentId, string $actionJson, ?string $pagePath = null): ?string;
    public function verify(string $componentId, string $actionJson, string $signature): bool;
}

// Rosanna 本体
public function signer(): ActionSigner;
```

計画書の API のとおり。Relayer は `signAction(...)` を first-class callable で渡し (`relayer/src/Router/AppRouter.php:1552`)、`verifyActionSignature()` を呼ぶ
(`:1618`)。同じ形で `signer()->sign(...)` を渡せる。

### 振る舞い

- **SG-1** 署名の入力は `rosanna-action\0{scope}\0{sessionId}\0{componentId}\0{actionJson}`。アクティブでなければ `sessionId` は空
  (`usePHP/src/UsePHP.php:1009-1014`)。
- **SG-2** 鍵は秘密鍵があればそれを使う。なければ `$_SESSION['_rosanna_action_key']` のランダム鍵を使い、なければ作る (`usePHP/src/UsePHP.php:949-967`)。
- **SG-3** `sign()` はまず Cookie からセッションを再開する。秘密鍵がなければセッションの開始も試みる。開始できず鍵がなければ `null` を返す
  (`usePHP/src/UsePHP.php:867-877`)。
- **SG-4** `verify()` はセッションを開始しない。Cookie のない POST が検証用の鍵を作れないようにする (`usePHP/src/UsePHP.php:882`)。
- **SG-5** `scope` は通常 `page:{正規化したパス}`。**コミット `88399e4`**。保護されたページのフォームを、保護のない URL に送れない。正規化は前後の `/` を
  1 つにし、クエリを含めない。パーセントエンコーディングは区別する (`usePHP/src/UsePHP.php:923-933`、`usePHP/src/Router/RequestContext.php:152`)。
  host の保護は同じ単位の `Request::pagePath()` で比べる。`Request::$path` は `/admin/` をそのまま持つので、`$request->path === '/admin'` と比べる host は `/admin/` への POST で保護を避けられる。この正規化は `ActionSigner::pageScope()` と共有する。
- **SG-6** defer のエンドポイントの描画中は `defer:{name}`。受け付ける範囲はそのリクエストのページと、登録された全 defer で、どのページへの POST でも通る。
  エンドポイントには保護がなく誰でも新しいフォームを取れるので、これで渡すものはない。POST 先のページの host による保護は実行される。部分更新で出すフォームは
  そのページで署名し直す (`usePHP/src/UsePHP.php:911-928`)。
- **SG-7** 比較は `hash_equals`。リクエストのどのフィールドも scope を選ばない (`ActionPageBindingTest::testNoClientFieldChoosesTheBinding`)。
- **SG-8** 描画中の署名は `RenderScope::$signingScope` (`01-runtime.md` の 9 節) を使い、検証は host が `handle()` に渡した `Request` のパスを使う。`$pagePath` の指定があればそれを優先する。グローバルの `REQUEST_URI` を直接読まない。Relayer はロケールなどの書き換えの
  後のパスを渡す (`SecurityHardeningTest::testRunBindsAnActionToThePathItWasGivenNotTheRawUri`)。
- **SG-9** `ActionSigner` も SN-4 と同じく 16 バイト未満の鍵を受け付けない。`null` はセッションごとの鍵を意味する (SG-2)。

### エラー

`sign()` が `null` ならエンベロープの `sig` を `null` にする。その POST は 400 (HD-3)。秘密鍵がない場合、出力の後に描画するとセッションを開始できず検証できないことを
ドキュメントに書く (README 1258 行)。

### テスト

- SG-1: インスタンス、JSON、セッション、パスのどれを変えても検証に失敗する。
- SG-2: 秘密鍵なしでセッションごとの鍵に切り替わる (`testSignActionFallsBackToAPerSessionKeyWithoutSecret`)。
- SG-4: Cookie なしの POST がセッションを作らない (`SessionBootstrapTest::testPostWithoutCookieOrSecretIsRejectedAndCreatesNoSession`)。
- SG-5: `ActionPageBindingTest` のうちページの束縛の項目。host の保護は「host が `handle()` の前に確かめる」形に書き換える。
- SG-5: 末尾の `/` は同じページとして通り、別のパスは 400。
- SG-6: defer のフォームがそれを含むページで通る。保護されたページでは host の保護が先に効く。

## 5. CSRF

### 目的

別のサイトからの POST を防ぐ。Origin の比較とセッションのトークンの 2 層。

### 公開 API

```php
namespace Polidog\Rosanna\Protocol;

final class CsrfGuard
{
    public function token(): string;                 // 無効またはセッションなしなら ''
    public function verify(Request $request): ?string;   // null は成功。文字列はログ用の拒否理由
}

// Rosanna 本体
public function csrf(): CsrfGuard;
public function disableCsrfProtection(): self;
public function trustProxyHeaders(bool $trust = true): self;
```

分割設計の形に合わせる。無効化は Relayer が使う (`relayer/src/Router/AppRouter.php:304`)。Relayer はトークンを自分で検証する。

### 振る舞い

- **CS-1** `handle()` の最初の検証の前に、Cookie からセッションを再開する。**コミット `38631b0`**。再開しないと、トークンの層を飛ばすことになる
  (`usePHP/src/UsePHP.php:2069-2075`)。
- **CS-2** 第 1 層。送信元は `Origin` (`null` と空は除く)、なければ `Referer` の scheme、host、port。期待値は scheme と host と port。一致しなければ拒否。**両方ないリクエストも
  拒否する** (`usePHP/src/UsePHP.php:1064-1081`, `:1177-1196`)。
- **CS-3** 第 2 層。セッションがアクティブなら、`_rosanna_csrf` がセッションの値と `hash_equals` で一致すること (`usePHP/src/UsePHP.php:1088-1095`)。
- **CS-4** Cookie があるのにセッションを開始できなければ拒否する。クライアントが第 2 層を外せないようにする (`usePHP/src/UsePHP.php:1083-1085`)。
- **CS-5** Cookie のないリクエストはセッションが持つ状態がないので、第 2 層を要求しない。Snapshot だけのページはセッションなしで動く
  (`SessionBootstrapTest::testSnapshotPageStaysSessionlessWithoutACookie`)。
- **CS-6** トークンは 32 バイトの乱数の hex で、セッションごとに安定する。フォームを出すたびに閉じ込めず読む。セッションは描画の途中で始まることがあるため
  (`usePHP/src/UsePHP.php:783-797`)。
- **CS-7** プロキシのヘッダーは既定で信じない。`trustProxyHeaders()` のときだけ `X-Forwarded-Proto`、`X-Forwarded-Host` (カンマ区切りの先頭)、`X-Forwarded-Port` を使う。port は host に
  port がなく scheme の既定でないときだけ付ける (`usePHP/src/UsePHP.php:1100-1175`)。
- **CS-8** 拒否の理由はログにだけ出し、クライアントには一律の本文を返す (HD-5)。

### エラー

検証の失敗は 403 `Forbidden`。`Cache-Control: no-store`。無効化されていれば常に成功し、`token()` は `''`。

### テスト

`SecurityHardeningTest` と `SessionBootstrapTest` の項目を新しく書く。

- Origin なし、別の Origin、Referer での一致、`Origin: null`。
- トークンなし、誤ったトークン、別のセッションのトークン。
- 壊れた Cookie、開始できない Cookie で拒否 (`testMalformedSessionCookieFailsClosed`、`testCookieWithoutAStartableSessionFailsClosed`)。
- Cookie なしの正しい Origin の POST が通る。
- `X-Forwarded-*` が既定で無視され、`trustProxyHeaders()` で効く。
- CS-1: Cookie を持つ訪問者の action が、再開の前に判定されない (`testActionIsAcceptedInEveryCombination`)。

## 6. バージョン照合

### 目的

usePHP #80 への対応。古い画面から送られた action を上書きとして適用せず、最新の状態で描き直す (計画書「#80 への対応」)。連打の 2 回目を数えるものではない。

### 公開 API

追加の公開メソッドは `ComponentState::version()` と `Action::$version`、`Snapshot::$version` だけ。

### 振る舞い

- **VR-1** インスタンスごとに整数のバージョンを持つ。初期値は 0。Renderer は描画時の値を action の JSON の `version` に入れる。JSON は署名の対象なので、バージョンも署名される。
- **VR-2** `setState` と `setFields` の適用ごとに 1 増やす。`restore` は変えない。
- **VR-3** Session のバージョンは `rosanna:{instanceId}:version` に保存する。読み取りは ST-2 に従い、保存がなければ 0。状態の消去でも巻き戻さない。
- **VR-4** Session の照合はサーバーが行う。署名の検証の後、適用の前に、`action.version` と保存値を比べる。セッションがアクティブなまま比較と書き込みをするので、PHP の既定のファイルセッションでは
  同じセッションの他のリクエストと重ならない。
  - 違えば**適用しない**。バージョンも変えない。
  - 応答は次のとおり。部分更新は最新の状態の断片を 200 で返し、`X-Rosanna-Stale: 1` を付ける。通常の POST は 303 でページへ。
  - 例外: 運ばれるスナップショットがある通常の POST は、`replay` があれば古くても全ページの応答のままにする。303 だと運ばれた兄弟の状態を失うため。
  - ロックしないセッションハンドラーでは同時のリクエストを防げないことをドキュメントに書く。
- **VR-5** Snapshot の照合はクライアントが行う (JS-8)。サーバーはバージョンを比べない。バージョンは `memo.version` に署名して運び、サーバーは適用ごとに増やして返す。差分の基準 (AC-17) はサーバーが比べるが、これは差分と基準の組み合わせを守るもので、
  古いフォームは自分のスナップショットと一緒に送られるので基準が一致する。VR-9 は変わらない。
- **VR-6** `rosanna.js` は Snapshot のすべてのインスタンスの最新の署名済みスナップショットを `sessionStorage` に写す。`persist` の指定とは別の用途で、これだけではページの読み込み時に
  `restore` を送らない (SN-30)。読み込みの種類が `navigate` か `reload` なら、`persist` のない写しを消して初期値から始める。`back_forward` と bfcache の復帰 (`pageshow` の `persisted`) なら残して照合する。「リロードで初期値に戻る」(README 566 行) と JS-8 が矛盾しないようにするため。
- **VR-7** Snapshot のラッパーは `persist` の有無によらず `data-rosanna-restore-sig` を持つ。古い送信を止めた後の `restore` に必要なため。usePHP は `persist` のときだけ出力する
  (`usePHP/src/Runtime/Renderer.php:361-367`)。
- **VR-8** Memory は照合しない。リクエストを越えて状態がない。同じフォームを何度送っても、それぞれが描画時の基準から適用する。
- **VR-9** Snapshot の確認は競合の防止であって、セキュリティの境界ではない。JavaScript なしの送信と、保存済みの古いスナップショットを手で送るリクエストは止められない。1 回だけ使える権利のような値を
  Snapshot に置かないことをドキュメントに書く。
- **VR-10** 兄弟のインスタンスには影響しない。比べるのは操作対象のバージョンだけ。

### エラー

バージョンの不一致はエラーではない。4xx を返さず、`rosanna:submit-error` も出さない。普通の操作で起きるので、ログにも出さない。通知は JS-8a の `rosanna:stale` で行う。

### テスト

- VR-4: Session のカウンターをセッションを共有する 2 タブで開き、それぞれ 1 回押すと、2 回目は適用されず、描き直された値が 1 のまま (秘密鍵なしの構成と、秘密鍵ありで先にセッションを作った構成の両方)。
- VR-4: 秘密鍵ありで Cookie のない 2 タブから始めると、2 つ目のタブの最初の送信は CS-3 で 403 になり、適用されず、JS-26 の更新で最新が描かれて `rosanna:stale` が出る。押し直すと適用される。
- VR-4: 同じフォームを 2 回送ると 2 回目は適用されない。`setFields` でも同じ。
- VR-4: 古い一覧の更新が新しい一覧を巻き戻さない (#80 の例)。
- VR-1: JSON の `version` を変えると署名で 400。
- VR-2: `restore` でバージョンが変わらない。
- VR-5: Snapshot の `memo.version` の改ざんが検証で失敗する。適用ごとに増える。
- VR-8: Memory は同じフォームを 2 回送っても適用される。
- ST-2 との整合: Cookie のない訪問者の描画でバージョンの読み取りがセッションを開始しない。

## 7. `actions()->handle()`

### 目的

action の POST を受け、状態を更新して応答を決める。**応答の判断はここの 1 か所に置く** (計画書「Relayer に公開する API」)。

### 公開 API

```php
namespace Polidog\Rosanna\Protocol;

final class ActionHandler
{
    /**
     * Rosanna の action でなければ null を返す。
     *
     * @param null|\Closure(bool $partial): (Element|string) $replay
     */
    public function handle(
        Request $request,
        ?\Closure $replay = null,
        SnapshotBehavior $behavior = SnapshotBehavior::Isolated,
    ): ?Response;
}

// Rosanna 本体
public function actions(): ActionHandler;
```

```php
// Relayer
$response = $app->actions()->handle($request, replay: fn (bool $partial) => $this->renderPage(/* ... */));

// 素の PHP
$app = new Rosanna();
$app->register(Counter::class);
$request = Request::fromGlobals();
$response = $app->actions()->handle($request, replay: fn () => $app->render(Counter::class));
if ($response !== null) {
    $response->send();
    return;
}
echo $app->render(Counter::class);
```

### 判定の順番

**host は `handle()` を呼ぶ前にページの権限を確かめる。** usePHP はルートのミドルウェアを action の前に実行していた (`usePHP/src/UsePHP.php:1369-1383`)。ルーターがないので host の責任になる。
署名がページに束縛されている (SG-5) ので、保護されたページのフォームを別のページに送って避けることはできない。

| 順 | 判定 | 結果 |
|---|---|---|
| 1 | POST でない、または `_rosanna_action` がない | `null` |
| 2 | `_rosanna_action` が JSON オブジェクトでない | `null` |
| 3 | セッションの再開 (CS-1)、CSRF (5) | 403 |
| 4 | フィールドの形 (`_rosanna_component`、エンベロープなど)、Action の形 (3) | 400 |
| 5 | 署名 (`restore` は AC-8) | 400 |
| 6 | 状態の復元 (2、ST-5)、運ばれるスナップショット (2.3) | 400 |
| 7 | バージョン照合 (Session のみ)、差分の基準の照合 (AC-17) | 応答へ。適用しない |
| 8 | 適用 (3) | 400 |
| 9 | 応答の判断 | 下の表 |

- **HD-1** 2 により、Relayer の `rosanna-action:` トークンは無視されて `null` になる (計画書「Relayer のサーバーアクションとの関係」)。パースに失敗したものも同じ。オブジェクトで形が不正なものは 4 で 400。
  usePHP は JSON でない値を 400 にしていた (`usePHP/src/UsePHP.php:2104-2112`)。これは意図した変更で、`MalformedActionIsBadRequestNotTypeError` のテストのうち JSON でない項目は `null` を期待する形に書き換える。
- **HD-2** 1〜2 ではセッションに触らない。通常の POST が host に素通りする。
- **HD-3** 署名がない action は 400。署名なしで受け付ける経路を作らない (`usePHP/src/UsePHP.php:2133-2153`)。
- **HD-4** 1 つのリクエストで処理する action は 1 つ。

### 応答の判断

判断の入力は、部分更新か (`X-Rosanna-Partial`)、ストレージの種類、挙動 (`behavior`)、運ばれるスナップショットの有無 (`usePHP/src/UsePHP.php:2201-2296`)。

| 部分更新 | 状態 | 応答 |
|---|---|---|
| はい | 登録されたクラス (Session、Memory、Snapshot の Isolated) | そのインスタンスの断片 |
| はい | 登録されたクラスの `restore` (全挙動) | そのインスタンスの断片 |
| はい | `fc()` (Session、Memory、Snapshot の Isolated) | `replay(true)` からラッパーを探して断片。見つからなければ redirect |
| はい | Snapshot の Persistent、Session | 303。書き込みは 2.5 |
| いいえ | Snapshot の Isolated、または運ばれるスナップショットがある Isolated | `replay(false)` の全ページ。`no-store` |
| いいえ | それ以外 | 303。書き込みは 2.5 |
| どちらも | `replay` がなく描き直せない Snapshot の Isolated | 303。状態を失うのでログに警告 |

- **HD-5** エラーの応答は本文を一定にし、`Cache-Control: no-store` を付ける。403 は `Forbidden`。400 は `Invalid action request`、`Invalid action data`、`Invalid action signature`、`Invalid snapshot`、`Invalid form data` のいずれか
  (`usePHP/src/UsePHP.php:2076-2084`, `:1037-1043`)。
- **HD-6** `replay` の `$partial` は、部分更新のために呼ぶなら `true`。戻り値は `Element` か完成した HTML の文字列。
  - 部分更新では戻り値の `Element` と、`replay` の間に `renderElement()` に渡された `Element` の両方から探す。戻り値が文字列の Session は探さない。見つからなくても redirect で状態を失わないため
    (`usePHP/src/UsePHP.php:2603-2607`)。例外: 古い action (VR-4) では探し、`X-Rosanna-Stale` つきの断片を返す (JS-8a)。見つからなければ 303。
  - 部分更新の間に `replay` が出力したものは捨てる (`usePHP/src/UsePHP.php:2601-2611`)。
  - 全ページの応答では、`replay` が出力したものを含める (`usePHP/src/UsePHP.php:2315-2327`)。
- **HD-7** `replay` の前に描画のパスを始める。自動で振られるインスタンスのキーが GET と揃うようにする (`usePHP/src/UsePHP.php:2598-2600`)。
- **HD-8** `replay` の中の例外は握りつぶさず host に伝える。状態は適用済みのことがある。クライアントは再送しない (JS-5)。
- **HD-9** ラッパーの探索は深さ優先で `data-rosanna` が一致するものを探す。defer のプレースホルダは対象の中身を描画してさらに探す。同じ名前とパラメーターは 1 回だけで、失敗した対象は飛ばす
  (`usePHP/src/UsePHP.php:2667-2698`, `:2725-2743`)。ネストしたプレースホルダは、エンドポイントと同じくプレースホルダのまま (`SnapshotReplayGuardsTest::testAReplayKeepsANestedDeferAsAPlaceholder`)。
- **HD-10** `replay` は Relayer のページに注入されたサービスをそのまま使える。Rosanna は DI を知らない。部分更新と defer を含む再描画で DI が効くことは Relayer の CI で確かめる (計画書)。
- **HD-11** 断片は `_rosanna_defer_query` を受けて、クラスコンポーネントのフォームに同じ値を出し直す。パラメーターを `$_GET` から読むクラスコンポーネントのため `$_GET` に設定する。署名しない。この POST のクエリは元からクライアントが選べるので、渡すものが増えない
  (`usePHP/src/UsePHP.php:2936-2944`)。
- **HD-12** SN-26 の警告のために `_rosanna_snapshot` のインスタンス ID を読むときだけは検証しない。比較にしか使わず、状態には入れないため (`usePHP/src/UsePHP.php:1601-1613`)。

### Redirect

- **HD-13** 行き先は常に現在のリクエストの URL で、任意の URL を受けない。`Request::$uri` から作る (計画書)。パスだけが origin を離れる原因になる。
  - 制御文字を含むなら `/`。
  - `\` を `/` にする。先頭の `/` を 1 つにする。`//evil.example/page`、`/\evil.example` を返さない。
  - クエリは保ち、`_rosanna_snapshot` を除く。フラグメントは落とす (`usePHP/src/UsePHP.php:1709-1766`)。
- **HD-14** 状態は 303 (`usePHP/src/UsePHP.php:2292`)。Persistent なら `SnapshotUrl::with()` で付ける。Session はセッションに書き、書けないときは書かない。
- **HD-15** usePHP の `redirect($url)` の公開メソッドは作らない。分割設計の削除リストのとおり。

### セッションの開始の通知

- **HD-16** 断片の応答は、そのリクエストがセッションを始めたとき (`Request::$cookies` にセッション Cookie がなく、断片を描画した後でセッションがアクティブ) に `X-Rosanna-Session-Started: 1` を付ける。ページの他のフォームはセッションの前に描画されたので CS-3 と SG-1 を通らない。クライアントはこれでページを更新する (JS-21)。送信は RQ-2 のとおり host か `send()` が行う。303 と全ページの応答には付けない。JavaScript なしでは全フォームが新しくなり、`fetch` は 303 のヘッダーを見せないため。

### エラー

検証の失敗は 2 節、5 節、3 節のとおり応答で返し、例外にしない。`replay` の例外だけ host に伝える。

### テスト

- 判定の順番: それぞれの段が先に失敗したとき、後の段が動かない (状態が変わらない)。
- HD-1: `rosanna-action:abc`、`[1]`、`abc` で `null`。セッションが始まらない。
- HD-3: `testUnsignedActionIsRejected`、`testTamperedSignedActionIsRejected`。
- HD-16: 実際の HTTP で、Cookie のない最初の部分更新にだけ付く。2 回目の action、Cookie のある訪問者、通常の POST、Snapshot だけの action、秘密鍵なしの構成、拒否された action では付かない (`SessionActionFlowTest::testHD16*`)。
- 応答の判断の表の全行。`IsolatedSnapshotFallbackTest`、`ActionFlowRoutesTest` の項目を `behavior` の指定に書き換える。
- HD-6: 文字列の応答でも `renderElement()` からラッパーが見つかる。出力する `replay` が断片を壊さない。
- HD-9: `testADeferredComponentAnswersAPartialFromThePageItsFormPostsTo`、`testAnActionInsideANestedDeferFindsItThroughTheOuterPlaceholder`。
- HD-13: `testNormalizeRedirectPathYieldsSameOriginPath`、`testNormalizeRedirectTargetKeepsTheQueryButNotTheAuthority`。
- RQ-2: `handle()` の呼び出しの前後で `headers_list()` が増えず、出力もない。
- RQ-3: 同じインスタンスで 2 つのリクエストを続けて処理しても、前のページを返さない。

## 8. defer

### 目的

一部のコンポーネントをページの読み込み後に別のリクエストで取得する。ページを CDN に載せつつ、訪問者ごとの部分を分ける。属性の `Defer` と `H::defer()` は `01-runtime.md`。

### 公開 API

```php
// Rosanna 本体
public function registerDeferred(string $name, string $component, ?string $cacheControl = null): self;
public function setDeferPrefix(string $prefix): self;
public function handleDeferred(Request $request): ?Response;
```

### host の入口

**ルーターがないので、`{prefix}/{name}` へのリクエストをアプリに届けるのは host の役割である。** ページの処理より前に `handleDeferred()` を呼ぶ。Relayer はすでにこの形で呼んでいる (`relayer/src/Router/AppRouter.php:477`)。

```php
// 素の PHP。/_defer/* をこのファイルに書き換える設定が Web サーバーに要る
$response = $app->handleDeferred(Request::fromGlobals());
```

ページごとの PHP ファイルに埋め込む使い方では、`.htaccess` などの書き換えが要ることをドキュメントに書く。

### 振る舞い

- **DF-1** GET で、パスが prefix か prefix 配下のときだけ処理する。それ以外は `null` (`usePHP/src/UsePHP.php:1828-1835`)。
- **DF-2** 名前は `[A-Za-z0-9_-]+`。不正なら 404。未登録も 404。404 は `no-store` (`usePHP/src/UsePHP.php:1846-1856`)。
- **DF-3** 同じ名前の登録は例外。同じ内容の自動登録は何もしない (`usePHP/src/UsePHP.php:355-388`)。
- **DF-4** クエリを props にする。`key`、`fallback` は取り除く。`key` がインスタンスの ID を決めるので、URL から他人の状態を選べないようにする。スカラーでない値は 400 (`usePHP/src/UsePHP.php:1865-1884`)。
- **DF-5** 既定の `Cache-Control` は `private, max-age=0`。登録の値があれば使う (`usePHP/src/UsePHP.php:1886`)。
- **DF-6** 新しい描画のパスで描画し、対象自身はプレースホルダを飛ばす。ネストした defer はプレースホルダのまま (`usePHP/src/UsePHP.php:1889-1898`)。
- **DF-7** 描画の例外はログに詳細を出し、500 と `Failed to render deferred component.` と `no-store` を返す。例外のメッセージをクライアントに見せない (`usePHP/src/UsePHP.php:1902-1917`)。
- **DF-8** **描画の後に共有キャッシュを確かめる。コミット `4621f0f`。** ポリシーが共有キャッシュを許すとき、または送信予定のヘッダーが許すとき、次のどちらかなら `Cache-Control: private, no-store` に置き換えてログに出す (`usePHP/src/UsePHP.php:1952-1988`)。
  - セッションがアクティブ、または読んで閉じられた (`session_id()` が空でない)。
  - `Set-Cookie` を送る。

  `private` か `no-store` を含まないものを「共有キャッシュを許す」と扱う (`:1994-1999`)。リクエストに Cookie があるだけでは該当しない。`session.cache_limiter = public` でも効くように、送信予定のヘッダーを見る。
- **DF-9** セッションを開始するより前にヘッダーが送られていて置き換えられなければ、ログに出す (`usePHP/src/UsePHP.php:1971-1979`)。RQ-2 により通常は起きない。
- **DF-10** 描画のあいだ、署名の scope は `defer:{name}` (SG-6)。
- **DF-11** defer の URL は常に prefix から始める。Relayer のロケールの接頭辞は付かない (`relayer/src/Router/AppRouter.php:470-476`)。
- **DF-12** `/_defer/{name}` は認証なしの公開の GET で、host の保護の対象外である。props は信頼しない入力であることをドキュメントに書く (README 1205 行)。
- **DF-13** PSX の defer は、`loadComponentManifest()` が deferred manifest の項目を `registerDeferred()` に渡して登録する (`01-runtime.md` の RT-11.5、`03-compiler.md` の 8 節)。

### エラー

404、400、500 の本文は上記のとおり。本文に入れる名前はエスケープする。

### テスト

- DF-1: POST、別のパスで `null`。
- DF-2、DF-4: 不正な名前、未登録、配列のパラメーター、`key` の除去。
- DF-7: 例外のメッセージが本文に出ない。
- DF-8: `DeferSharedCacheTest` の項目。セッションを使う、Cookie を設定する、`session.cache_limiter = public`、セッションを使わないならポリシーを保つ、`private` は警告しない。
- DF-8: 秘密鍵があるセッションなしのフォームは全員に同じで、キャッシュできる (`testASignedFormWithoutASessionIsTheSameForEveryVisitor`)。

## 9. `rosanna.js`

### 目的

JavaScript なしでも動く形の上に、部分更新、defer、永続化を載せる。依存はない。配置は `01-runtime.md` の publish。

### 公開 API

```js
window.Rosanna.deferPrefix          // 読む。既定は '/_defer'
window.Rosanna.clearDeferCache(nameOrUrl?)
window.Rosanna.reloadDefer(nameOrUrl?)
window.Rosanna.restoreSnapshots(root?)
window.Rosanna.forgetSnapshots(root?)
```

PHP の `Rosanna::renderClientScript(string $src = '/rosanna.js', ?string $deferPrefix = null)` は `01-runtime.md`。外部ファイルが読めないときだけ defer を 1 回取得するインラインの fallback を含む
(`usePHP/src/UsePHP.php:70-115`)。

### 部分更新

- **JS-1** `data-rosanna-form` の submit を止めて `fetch` で送る。送信先は `location.href`、ヘッダーは `X-Rosanna-Partial: 1`。フォームが見つからなければ通常の送信 (`usePHP/public/usephp.js:631-699`)。送信の内容は `new FormData(form, submitter)` で作り、共有フォームのボタンの `_rosanna_action` を含める。`data-rosanna-on-<event>` では `data-rosanna-action-<event>` を `_rosanna_action` に入れる。
- **JS-2** 対象はフォームの `_rosanna_component` の ID から決める。フォームはアプリの `<form>` の中にあるとラッパーの外に置かれる (`usePHP/public/usephp.js:642-648`)。
- **JS-3** 送信に現在のスナップショットを入れ、子孫のものを `_rosanna_snapshots[]` で送る。全ページの応答が入れた欄は捨てて入れ直す (`usePHP/public/usephp.js:682-693`)。
- **JS-4** redirect の応答に従って移動する。同一オリジンでなければ移動せず、警告してリロードする。再送の待ちがあれば移動しない (`usePHP/public/usephp.js:704-726`)。
- **JS-5** **失敗を再送しない。** 2xx 以外か例外なら `form.submit()` に落とさず、キャンセルできて泡立つ `rosanna:submit-error` (`detail.response` か `detail.error`) を出す。キャンセルされなければ console に警告し、ラッパーに `data-rosanna-error` を付ける。次の送信で消す。**コミット `739714f`** (`usePHP/public/usephp.js:601-629`)。
- **JS-6** 送信中のロック。自分、祖先、子孫のどれかが送信中なら次の送信を受けない。兄弟は待たない。`disabled` と `aria-busy` はロックに使わない。固定値の action は捨てる。別の行に当たりかねないため。`data-rosanna-with-value` のものは完了後に最新の値で 1 回送り直す。同じ引き金 (送信ボタンか、イベントの要素) の同じ値は連打として捨てる。フォームは共有されるので、フォームでは区別しない (`usePHP/public/usephp.js:570-599`, `:788-868`)。兄弟を待たない例外は JS-24 と JS-25。
- **JS-7** 応答は `template` に解析して差分適用する。`<script>` は動かさない。欄の値は README 438-445 行の順で決める。hidden と `button` の `value` はサーバーに従う。フォーカスを戻す (`usePHP/public/usephp.js:1371-1394`)。

### バージョン照合

- **JS-8** Snapshot のインスタンスについて、送信の前に `_rosanna_action` のエンベロープの `action` の `version` と、`sessionStorage` の写しの `memo.version` を比べる。フォームの方が古ければ**送信せず**、写しのスナップショットで `restore` を送って描き直す。JSON にならない値は比べない (Relayer のトークン)。
- **JS-8a** 応答に `X-Rosanna-Stale: 1` があるか JS-8 で止めたとき、キャンセルできて泡立つ `rosanna:stale` を出す。送信したフォームの欄の値は JS-7 の順に従わず保つ。古い action を描き直しても入力が消えないようにするため。
- **JS-9** 写しは部分更新の適用の後に更新する。キーは `rosanna:snapshot:{pathname}#{instanceId}`。
- **JS-10** `sessionStorage` が使えないか書けないときは、ページ内のメモリで同じ確認をする。リロードと戻る操作の後は確認できない。エラーにしない。
- **JS-11** `sessionStorage` に秘密を足さない。写すのはページにすでにある署名済みのスナップショットだけ。`Rosanna.forgetSnapshots()` で消せる。

### 永続化

- **JS-12** ページの読み込み時に、`data-rosanna-persist` のインスタンスの保存値がラッパーの値と違えば `restore` を送る (`usePHP/public/usephp.js:1460-1512`)。
- **JS-13** サーバーが拒否するか redirect で答えたら保存値を捨てて、毎回の再試行を防ぐ。ネットワークの失敗では残す。
- **JS-14** CSRF トークンはそのコンポーネントのフォームか、ページの別のフォームから借りる。
- **JS-15** `persist: 'localStorage'` はタブをまたいで共有する。`persist` の種類によらず、照合に使う写しは `sessionStorage` に置く (VR-6)。

### defer

- **JS-16** プレースホルダの URL は、ページと**同一オリジン**で、defer の prefix 配下のときだけ取得する。応答の `Content-Type` が `text/html` のときだけ挿入する。それ以外は console に警告して何もしない。サニタイザーを抜けた `data-*` で任意の内容を流し込まれるのを防ぐ。**コミット `739714f`** (`usePHP/public/usephp.js:96-136`, `:1094-1101`)。
- **JS-17** フォーム、`_rosanna_csrf`、`_rosanna_snapshot`、`data-rosanna-snapshot` を含む断片は `localStorage` に書かない。`localCache` の指定があっても書かない。共有端末で次の利用者にトークンが渡るのを防ぐ (`usePHP/public/usephp.js:141-153`, `:1121-1130`)。
- **JS-18** キャッシュはページ内のメモリと、指定があるときの `localStorage` の 2 段。`localStorage` の上限は 64 件で古い順に捨てる。バージョンが変われば全部消す。`localStorage` がなければメモリだけで動く (`usePHP/public/usephp.js:79-91`, `:187-420`)。
- **JS-19** 同時の取得は 1 つ。`reloadDefer` は新しい取得で前を中断し、古い応答は捨てる (`usePHP/public/usephp.js:160-175`, `:986-1135`)。
- **JS-20** `data-rosanna-reload-defer` はフォームなら部分更新の成功の後、それ以外は click で動く。一覧の取得が他の取得より先に走るよう、defer の再走査より前に動かす (`usePHP/public/usephp.js:512-562`, `:739-755`)。

### ページの更新

秘密鍵があると描画でセッションが始まらない (ST-2) ので、Cookie のない訪問者のフォームはトークンを持たず、署名の `sessionId` は空になる。最初の action の後、描き直されなかったフォームは CS-3 と SG-1 を通らなくなる。これらの要件を緩めず、フォームを新しくする (11 節の U-7)。

- **JS-21** 応答に `X-Rosanna-Session-Started: 1` があれば、断片を適用した後でページを更新する。送信中の応答の適用をすべて待ち、`fetch(location.href)` の GET (`cache: 'no-store'`) で取り直して `template` に解析し、ライブの `[data-rosanna]` に ID で差分を当てる (JS-7)。入力中の欄とフォーカスは JS-7 の順で保ち、`hidden` と `button` の `value` はサーバーに従う。アプリの `form` の外に置かれたフォーム (RT-4.5) は入れ替える。文書全体を置き換えず、ラッパーの外と、ラッパー自身の Rosanna のもの以外の属性には触らない。最初の action が redirect で答え、再送の待ちがあるとき (JS-4) はヘッダーが見えないので、トークンのないフォームなら同じ更新を行う。
  GET が失敗したとき、2xx でないとき、redirect されたとき、HTML でないときは `location.reload()` に落とす。redirect は別のオリジンに限らない。別の URL のページは署名の scope が違うので (SG-5) 差分を当てられない。1 回の更新で 1 回だけで、読み込み直したページはセッションの Cookie を持つのでヘッダーが出ない。
- **JS-22** Snapshot のインスタンスは GET で初期状態に戻るので、更新の前のスナップショットを覚える。更新の前のバージョンが 1 以上で、更新後より新しいか同じで内容が違えば、そのインスタンスの差分は当てず (入力中の欄が初期値を経由して消えないため)、更新後のページの `data-rosanna-restore-sig` とトークンで `restore` を送り、応答を当てる。スナップショットの HMAC はセッションに束縛されないので通る。親の応答が子を描き直すので親から順に送る。更新後の方が新しければサーバーの値を正とする (`SnapshotBehavior::Session`)。写しと `persist` の保存値は最後に今の値で更新する (JS-9)。写しの方が新しければ残す (戻る操作の後のページ、通信で失敗した `restore`)。`restore` が通らなければ描画された値を当てる。サーバーが拒否したなら JS-13 のとおり保存値を捨て、通信の失敗なら残して次のクリックを JS-8 に任せる。
- **JS-23** defer はページ内のメモリのキャッシュから、セッションに結びつく断片 (JS-17 の目印) を更新の開始時に消す。セッションの前に始めた取得は中断して取り直し、結果をキャッシュに入れない。解決済みの defer は、応答のプレースホルダの位置に取得した断片を入れてから差分を当てる。中のインスタンスも ID で対応し、入力と Snapshot の状態が残る。取得に失敗した領域はプレースホルダのままで、既存の取得に任せる。JS-16 の検査を同じように通す。
- **JS-24** 更新の間の送信は行わない。JS-6 と同じく固定値の action は捨て、`data-rosanna-with-value` のものは更新の後に最新の値で新しいフォームから 1 回送る。
- **JS-25** トークンのないフォームの Session の action が送信中の間は、他の Session の action を JS-6 と同じ扱いで止める。Cookie のない 2 つの action はそれぞれセッションを作り、後の Cookie が勝って先の更新が失われるため (Chromium で再現した。表示は両方更新されるが、リロードで先の更新が消える)。エンベロープの `action.storageType` で判定する。クラスのコンポーネントは `storageType` を持たないので (RT-5.3)、ラッパーが Snapshot でなければ Session と見る (AC-11)。JSON にならない値は止めない。Snapshot と Memory の `storageType` を持つ action は止めないので、Snapshot だけのページの兄弟は同時に送る。
- **JS-26** トークンのないフォームの送信が 403 か 400 を受けたら、`rosanna:submit-error` の前に JS-21 の更新を 1 回行う。別のタブなど、HD-16 を受け取れなかった古いフォーム。取り直したページにトークンがあれば、差分適用の前に `rosanna:stale` (`detail.origin` は `server`、`detail.response` は拒否の応答) を出し、JS-8a と同じく送信したフォームの欄を保つ。キャンセルすると欄を保たず、更新は止まらない。action は適用されていないので再送しない。取り直したページにトークンがなければセッションはないので、描き直さず `rosanna:submit-error` を出す。次の成功まで同じ更新を繰り返さず、トークンのあるフォームの 403 と 400 は更新せず `rosanna:submit-error` にする (JS-5)。JS-8 で止めた `restore` の拒否も同じで、写しのスナップショットを JS-22 で戻す。

### エラー

スクリプトは例外でページを止めない。Web Storage の例外は握りつぶす。ユーザーに見える通知は出さない。利用者が `rosanna:submit-error` で扱う。

### テスト

`tests/browser/` の Playwright (usePHP の `state`、`lock`、`resend`、`morph`、`events`、`defer` の項目を新しく書く) と、スクリプトの中身の確認 (`ClientScriptTest`)。

- JS-1: 共有フォームのボタンごとに正しい action が送られる。`button` 以外の要素でも送られる。
- JS-6: 共有フォームの別のボタンが連打として捨てられない。
- JS-7: 部分更新の後、`button` の `value` が新しい action になる。
- JS-4: 別オリジンへの redirect で移動しない。
- JS-5: 500 と例外で `form.submit()` しない。イベントをキャンセルすると属性が付かない。
- JS-6: ダブルクリックで 1 回しか送らない。親と子が同時に送らない。兄弟は同時に送る。入力の欄は最後の値で 1 回送り直す。
- JS-8: 戻るボタンの後の古いフォームが送られず、`restore` で最新が描かれる。
- JS-8: 別のタブの Snapshot は互いに影響しない。
- JS-10: `sessionStorage` を止めても動く。
- JS-13: 鍵の変更の後で保存値が消え、再試行しない。
- JS-16: 別オリジン、prefix の外、`application/json` を挿入しない。
- JS-17: フォームを含む断片が `localStorage` に入らない。
- JS-21: 秘密鍵ありで Cookie なしの兄弟を A、B の順に押すと B が適用される。入力中の欄とフォーカスが保たれる。親子、アプリの `form` の中のコンポーネント。GET の失敗で `location.reload()` に落ち、ループしない。Snapshot だけのページと秘密鍵なしの構成では更新が走らない。
- JS-22: Session と Snapshot が混在するページで Snapshot の状態が新しいセッションの署名の `restore` で戻る。写しと `persist` が揃う。
- JS-23: defer の中のフォームが新しくなる。メモリのキャッシュと取得中の応答から古いフォームが戻らない。
- JS-24: 更新の間の固定値のクリックは捨て、with-value は新しいフォームで送り直す。
- JS-25: Cookie のない兄弟の固定値は捨て、with-value は待って送る。両方の更新がリロードの後に残る。Snapshot は止めない。
- JS-26: 2 タブの古いフォームが更新で回復し `rosanna:stale` が出る。キャンセルの意味が JS-8a と同じ。更新の後でも同じ失敗なら `rosanna:submit-error`。トークンのあるフォームの本物の 403 では更新が走らない。

## 10. セキュリティ要件の対応表

計画書の「作り直しても引き継ぐもの」の全項目。公開前に全項目のテストが通ることを確かめる。

| 振る舞い | コミット | 要件 |
|---|---|---|
| スナップショットの HMAC | `434527d` | SN-2、SN-3、SN-4、SN-10 |
| CSRF | `434527d` | CS-1〜CS-8 |
| open redirect の遮断 | `434527d` | SN-20、HD-13、HD-15、JS-4 |
| `javascript:` URL の遮断 | `434527d` | SN-20 (redirect)。属性は `01-runtime.md` の Renderer |
| 壊れたスナップショットを検証失敗として扱う | `2ec7012` | SN-5、SN-24、SN-27 |
| アクションの署名を描画元のページに結びつける | `88399e4` | SG-5、SG-6、SG-7、SG-8、AC-8 |
| アクションの検証前に Cookie からセッションを再開する | `38631b0` | CS-1、CS-4、SG-3 |
| タグ名と属性名の検証、インラインの `on*` ハンドラの拒否 | `07b7777` | `01-runtime.md` の Renderer |
| 不正な UTF-8 で出力を空にせず置換する | `fa3a8ef` | `01-runtime.md` の Renderer。JSON の置換は SN-2 |
| `script` と `style` の中身をエスケープせずに出力する | `34b1f1a` | `01-runtime.md` の Renderer |
| 描画できない子要素を拒否する | `a5c5018` | `01-runtime.md` の Renderer |
| 訪問者ごとの応答を共有キャッシュに載せない | `4621f0f` | DF-8、DF-9 |
| クライアント JS の defer の fetch | `739714f` | JS-16、JS-19 |
| localStorage の永続化 | `739714f` | JS-17、JS-18 |
| 部分送信の失敗の扱い | `739714f` | JS-5 |

計画書の表はコミットメッセージの検索から作ったものなので、上記に加えて今回の照合で次の防御も要件にした。

| 振る舞い | 要件 | 根拠 |
|---|---|---|
| Session の URL スナップショットの無視 | SN-23 | `usePHP/src/UsePHP.php:1470-1488` |
| セッションへの束縛 (文脈) | SN-6、SN-24 | `usePHP/src/UsePHP.php:668-695` |
| 運ばれるスナップショットの検証と範囲 | SN-13〜18 | `usePHP/src/UsePHP.php:2395-2460` |
| `restore` の署名 | AC-8、VR-7 | `usePHP/src/UsePHP.php:2120-2140` |
| defer の `key` の除去 | DF-4 | `usePHP/src/UsePHP.php:1865-1876` |
| defer の例外のメッセージを隠す | DF-7 | `usePHP/src/UsePHP.php:1902-1917` |
| Cookie のない POST が鍵を作らない | SG-4 | `usePHP/src/UsePHP.php:882` |

## 11. 決定の記録

計画書から決められなかった点の検討の記録。2026-11-01 に、いずれも A (推奨) に決めた。この文書の本文は決定に合わせてある。

### U-1. Snapshot の置き場所の指定

ルーターがなくなると、`SnapshotBehavior` を宣言する場所がない。計画書は URL の運搬 (Persistent) を残すとだけ書いている。Relayer はどの挙動も使っていない。

| 選択肢 | 内容 |
|---|---|
| A (推奨) | Isolated、Persistent、Session を残す。Shared はルートのグループの概念なので落とす。host が `handle()` と `restoreSnapshots()` に指定する |
| B | 4 つとも残す。この文書の案。Shared は `$group` を host が渡す |
| C | Isolated と `persist` だけにする。一番小さいが、JavaScript なしで状態を URL やセッションに残す手段がなくなる |

関連して `SimpleRouter::setSnapshotParam()` の移し先も決める。推奨は作らず `_rosanna_snapshot` に固定する。

### U-2. Snapshot の写しがある状態のリロード

VR-6 ですべての Snapshot を写すと、`persist` のないコンポーネントをリロードしたとき、ページは初期値 (バージョン 0) で写しは新しい値を持つ。JS-8 をそのまま使うと、最初のクリックが止められて古い状態が現れる。「リロードで初期値に戻る」(README 566 行) と矛盾する。

| 選択肢 | 内容 |
|---|---|
| A (推奨) | 読み込みの種類を見る。`navigate` と `reload` なら `persist` のない写しを消して初期値から始める。`back_forward` と bfcache の復帰 (`pageshow` の `persisted`) なら残して照合する |
| B | `persist` のないコンポーネントは照合しない。戻るボタンの競合が防げず、計画書の「`sessionStorage` に写す」が `persist` だけに狭まる |
| C | リロードでも写しから戻す。`persist` の区別がなくなる |

### U-3. 古い action を描き直したことの通知

VR-4 と JS-8 は何も通知せずに描き直す。`setFields` では、JS-7 の順により送信したフォームの欄が描画された値に戻るので、**入力した内容が消える**。別のタブで更新された後に送った編集が該当する。

| 選択肢 | 内容 |
|---|---|
| A (推奨) | 応答に `X-Rosanna-Stale: 1` を付け、`rosanna.js` はフォームの欄を保ち、キャンセルできる `rosanna:stale` イベントを出す |
| B | 通知なし。ドキュメントに制約として書く |

### U-7. 秘密鍵ありの構成で、最初の action の後に残るトークンのないフォーム

Cookie のない訪問者のフォームはトークンを持たず、署名の `sessionId` は空になる。最初の action でセッションが始まると、部分更新で描き直されなかったフォームは CS-3 で 403、トークンがあっても SG-1 で 400 になる。

| 選択肢 | 内容 |
|---|---|
| A (決定) | ST-2、SG-1、CS-3 を緩めない。セッションを始めた応答に `X-Rosanna-Session-Started: 1` を付け (HD-16)、`rosanna.js` がページを取り直して差分を当てる (JS-21〜JS-26) |
| B | 描画でセッションを始める。ST-2 に反し、匿名のページをキャッシュできなくなる |
| C | 匿名の署名をセッションの後も受け付ける、またはトークンなしを通す。SG-1 と CS-3 を緩める |

追加のリクエストと引き換えに、ST-2 と匿名のページのキャッシュを保つ。

### U-4. #87 の扱い

決定済み。v0.0.1 で解決する (3.1、3.2、`01-runtime.md` の RT-4.7〜RT-4.9)。理由と採らない案は計画書「#87 への対応」。残る制約は AC-19。

### U-5. セッションを開始できないときの書き込み

usePHP の `SessionStorage` は `session_start()` の警告を止めず、失敗しても `$_SESSION` に書いて捨てられる (`usePHP/src/Storage/SessionStorage.php:55-60`)。

| 選択肢 | 内容 |
|---|---|
| A (推奨) | `LogicException` を投げる。出力の後に Session の状態を書こうとしたバグを隠さない |
| B | ログに出して続ける |

### U-6. セッションの抽象

分割設計の step 0 は `SessionInterface` を足すが、計画書は触れていない。この文書は PHP のネイティブセッションを前提にしている。Relayer も自前の `NativeSession` を持つ (`relayer/src/Auth/NativeSession.php`)。

| 選択肢 | 内容 |
|---|---|
| A (推奨) | v0.0.1 はネイティブセッションだけ。VR-4 のロックの説明も単純になる |
| B | `SessionInterface` を入れる。ロックの保証を実装ごとに書く必要がある |
