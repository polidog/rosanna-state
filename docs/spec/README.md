# Rosanna State 実装仕様

状態層の仕様。`polidog/rosanna` から分離した (polidog/rosanna#7)。要件 ID は分離の前の番号のままである。

計画書は [`polidog/rosanna` の rosanna-split-and-rename.md](https://github.com/polidog/rosanna/blob/main/docs/design/rosanna-split-and-rename.md)、本体を含めた文書の一覧と決定事項は [`polidog/rosanna` の docs/spec/README.md](https://github.com/polidog/rosanna/blob/main/docs/spec/README.md) にある。

| 文書 | 範囲 | 要件 ID |
|---|---|---|
| [01-runtime.md](01-runtime.md) | フォームの束縛と配置、Component、hooks、インスタンス ID、状態層のスコープ、`RosannaState` の API、publish | `RT-` (RT-4〜8、RT-9.4、RT-9.5、RT-10、RT-12、RT-13) |
| [02-state-and-actions.md](02-state-and-actions.md) | Storage、Snapshot、Action、署名、CSRF、バージョン照合、defer、`rosanna.js` | `RQ-` `ST-` `SN-` `AC-` `SG-` `CS-` `VR-` `HD-` `DF-` `JS-` |
| [本体の 01-runtime.md](https://github.com/polidog/rosanna/blob/main/docs/spec/01-runtime.md) | `H`、Renderer、`RenderExtension`、描画スコープ、PSX の読み込み、`Rosanna` の描画 API | `RT-` (RT-0〜3、RT-9、RT-11、RT-12 の本体の部分、RT-13 の `compile`、RT-14) |
| [本体の 03-compiler.md](https://github.com/polidog/rosanna/blob/main/docs/spec/03-compiler.md) | PSX、コンパイラ | `CP-` |

## 本体との契約

文書をまたぐ契約の一覧は [本体の docs/spec/README.md の「文書をまたぐ契約」](https://github.com/polidog/rosanna/blob/main/docs/spec/README.md#文書をまたぐ契約) にある。状態層から本体に差し込むものは次のとおり。

| 差し込むもの | 本体の口 | 登録の場所 |
|---|---|---|
| `ActionFormExtension` | `RenderExtension` (RT-3.15) | `RosannaState` が `new Rosanna()` に渡す factory |
| `StateScope` を slot に入れた `RenderScope` | `$scopeFactory`、`RenderScope::$state` (RT-9、RT-12.9) | 同上 |
| `resolveEventAction()` | `EventResolver` (RT-2.6) | `src/bootstrap.php` (Composer の `autoload.files`) |
| 状態、描画パス、セッションの消去 | `RequestLifecycle::onReset()` (RT-9.6) | `src/bootstrap.php` |
| `BaseComponent`、`FunctionComponentCall` | `ResolvesItself` (RT-2.8) | 実装するだけ |
| `FunctionComponent` | `AcceptsArgumentResolver` (RT-11.3) | 実装するだけ |
| `DeferredManifestCompiler::compile()` | `CompileCommand` の `\Closure` (CP-135、CP-136) | `bin/rosanna-state` |
