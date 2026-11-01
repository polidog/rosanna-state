# Rosanna のサンプル集

[English](README.md)

小さなサンプルを、1 つのサーバーでまとめて動かせます。各サンプルは `index.php` 1 枚 (10 だけ PSX のファイルも) で完結していて、共有しているのは HTML の外枠の [layout.php](layout.php) だけです。

## 動かし方

リポジトリのルートで実行します。PHP 8.5 以上が必要です。

```bash
composer install
bin/rosanna compile examples/10-psx/components --cache=examples/10-psx/var/cache/psx   # 10-psx を動かすときだけ
ROSANNA_SNAPSHOT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));') php -S localhost:8000 examples/router.php
```

http://localhost:8000 を開くと一覧が出ます。

## 一覧

順に読むと、基本から host への組み込みへ進みます。「JS」は `rosanna.js` なしで動くかどうかです。

| サンプル | 見せること | 主な API | JS なし |
|---|---|---|---|
| [01-counter](01-counter/index.php) | コンポーネントと host の処理の基本の形 | `fc`、`useState`、`actions()->handle` | 動く |
| [02-form](02-form/index.php) | 入力の受け取りとサーバー側の検証 | `useFormAction`、`select`、`checkbox` | 動く |
| [03-cart](03-cart/index.php) | 一覧全体を 1 つの状態で持つ。金額はサーバーの値で計算する | 配列の `useState`、行の `id` | 動く |
| [04-class-component](04-class-component/index.php) | クラスで書く。key でインスタンスを分ける | `#[Component]`、`BaseComponent`、`register`、`render` | 動く |
| [05-storage](05-storage/index.php) | 保存先ごとのリロード後の違い | `StorageType`、`SnapshotPersist` | Memory と persist 以外は動く |
| [06-shareable-url](06-shareable-url/index.php) | 状態を URL に載せて共有する | `SnapshotBehavior::Persistent`、`restoreSnapshots` | 動く |
| [07-live-search](07-live-search/index.php) | 入力に合わせて絞り込む。フォーカスは残る | `onInput`、`StorageType::Snapshot` | ボタンで送れば動く |
| [08-guard](08-guard/index.php) | host がページを保護する。自前のフォームと共存する | `pagePath`、`csrf()` | 動く |
| [09-defer](09-defer/index.php) | 共通の本体と訪問者ごとの断片を分ける | `Defer`、`H::defer`、`handleDeferred` | fallback だけが見える |
| [10-psx](10-psx/) | タグで書く。コンポーネントを入れ子にする | `.psx`、`rosanna compile`、`loadComponentManifest` | 動く |

## 読むときの注意

- **秘密鍵は環境変数から読みます。** サーバーを起動し直すと上のコマンドでは鍵が変わるので、開いたままのページの操作は失敗します。開き直してください。
- **サンプルの間でセッションを共有します。** 同じオリジンで動かしているからです。状態が混ざらないよう、`fc()` の key をサンプルごとに変えています。
- **[router.php](router.php) はサンプルを並べるためのものです。** 自分のアプリでは host のルーターかページごとの PHP ファイルが同じ役割を持ちます。`/rosanna.js` の配信も `vendor/bin/rosanna publish` で `public/` に置く形にしてください。
- **08-guard のサインインはパスワードを確かめません。** 保護の順番を見せるためのものです。
- **フォームの値を配列に追加する hook はありません。** `useFormAction` は欄の値を hook に上書きするだけです。入力した文字列を一覧に足す ToDo のような画面は、host の普通のフォームでデータベースに書き、描画のときに読み出してください。行ごとの操作は 03-cart の形で書けます。

worker モードは FrankenPHP などが要るので、ここには入れていません。[README の worker モード](../README.ja.md#worker-モード)を見てください。

## テスト

すべてのサンプルを `tests/browser/examples.spec.ts` が実際のブラウザで確かめています。主なものは JavaScript のありとなしの両方で動かします。

```bash
npm ci && npx playwright install chromium
npx playwright test tests/browser/examples.spec.ts
```
