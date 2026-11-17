# 引き継ぎ書（Upgrade Warden ／ freemius04）

最終更新: 2026-11-17

この文書だけを読めば、別のアカウント・別の環境で作業を再開できるように書いています。
作業内容の詳細は次の3つにあります。

| 文書 | 内容 |
|---|---|
| `docs/market-research.ja.md` | なぜこの商品にしたか。有利・不利な事実と、その信頼度 |
| `docs/product-design.ja.md` | 商品の設計、確定事項、現在の数字、未検証事項、オーナーの判断事項 |
| `docs/adding-rules.md` | 規則を追加するときの決まり（英語） |

---

## 1. 経緯（3行で）

1. オーナーの条件「求められている／人手では作れない量／無料で完成できる」から、4候補を比べて「PHP のバージョンアップで壊れるプラグイン・テーマを検査して直す WordPress プラグイン」を選んだ。
2. オーナーから「全て任せる」と一任を受け、製品名 Upgrade Warden、価格 年額 $49／$99／$199・買い切り $149／$299／$599 で確定とした。
3. PHP 8.0〜8.6 の公式変更記録の全602項目に判定を付け、152規則・899件の検証用プログラムを、実物の PHP 7.4〜8.5 で確かめた。WordPress プラグインとして動く（Free の検査、Pro の修正・巻き戻し）。

## 2. 置き場所

| もの | 場所 |
|---|---|
| 04 のすべての成果 | GitHub `TYStudio-JP/freemius04`、ブランチ **`claude/nifty-mayer-tcwf37`** |
| `main` ブランチ | 初期状態のまま（README のみ）。**まだマージしていません。** プルリクエストも作っていません |
| 03（Checkout Warden） | GitHub `TYStudio-JP/Freemius03`、ブランチ `work`。今回は参照しただけで、変更していません |

再開するときは、上のブランチを取り出してください。

```
git clone https://github.com/TYStudio-JP/freemius04
cd freemius04
git checkout claude/nifty-mayer-tcwf37
```

## 3. 環境の準備

必要なもの：PHP 7.4 以上（コマンドライン）、Node.js 20 以上、git。
ネットワーク：`registry.npmjs.org`、`raw.githubusercontent.com`、`github.com`（git の取得）。

```
bash tools/setup.sh --minimal   # テストと検証用プログラムに必要なものだけ（実物の PHP 7.4〜8.5 など）
bash tools/setup.sh             # 加えて、誤報の確認に使った実在コード（WordPress、有名プラグイン10本）を同じ版で取得
```

すべて `tools/node_modules` と `tools/out/` に入り、git の管理外です。
取得する実在コードは、今回確かめた版に固定してあります。これにより、`docs/product-design.ja.md` の数字と同じ結果になるはずです。

## 4. 動いていることの確かめ方

| 確認 | コマンド | 前回の結果 |
|---|---|---|
| テスト（手元の PHP） | `php tests/run.php` | 841件すべて通過（PHP 8.3.6） |
| テスト（実物の PHP 7.4〜8.5） | `cd tools && node run-tests.mjs` | 7版とも841件すべて通過 |
| 全検証用プログラム | `cd tools && node verify-fixtures.mjs` | 899件、不一致0件（約20分） |
| 本物の WordPress | `cd tools && node wp-check.mjs out/corpus/wp-latest out/corpus/sqlite-database-integration 7.4 8.5` | 31項目すべて通過 |
| 誤報の確認 | `php bin/scan.php tools/out/corpus/wp-latest --target=8.5 --summary` | 「壊れる」「挙動が変わる」0件、非推奨23件 |

## 5. 残っている仕事（優先順）

| # | 内容 | 補足 |
|---|---|---|
| 1 | **実行時の記録**（Free は直近、Pro は長期と通知） | 値によって起きる型エラー（`count( null )` など）は検査では拾えない。PHP 8 で画面が真っ白になる主な原因。根拠となる `tools/fixtures/runtime.*`（276件）は用意済み |
| 2 | 販売の準備 | Freemius の製品登録とキー（`upgrade-warden.php` の `UPGW_FS_PRODUCT_ID`／`UPGW_FS_PUBLIC_KEY` が空）、日本語訳、`readme.txt`、無料版の作成と WordPress.org への申請、ブラウザでの画面確認、MySQL での確認。手順は 03 の `docs/release-guide.ja.md` が参考になる |
| 3 | 検査の高速化 | WordPress 本体（1,900ファイル）で約16秒。最初は約8秒 |
| 4 | 規則の精度 | 既知の限界は `tools/ledger/*.json` の各項目の reason に書いてある。多くは「同じプラグインの別ファイルの定義を見ていない」こと |
| 5 | PHP 8.6 の実機検証 | 8.6 の分は「文献のみ」のまま。`tools/build-php86.sh` は書いてあるが、前の環境では権限の判定で `./buildconf` が止められた |
| 6 | 定期検査、更新前の検査、制作会社向けの報告書（Pro） | 未着手 |

## 6. オーナーの判断待ち

| 事項 | 理由 |
|---|---|
| PHP 8.6 のビルドの許可 | 前の環境では、ビルド用のパッケージ導入は通ったが `./buildconf` の実行が止められた |
| wordpress.org・php.net の閲覧の許可 | 競合の数値・公式統計・スラッグの空きを一次情報で確かめるため。前の環境では接続が拒否された |
| Freemius への製品登録 | アカウントの操作が必要 |
| Elementor の不具合を報告するか | 同梱の画像ライブラリの回転処理が PHP 8 以降で必ず失敗する（実機で再現済み、`docs/product-design.ja.md` 5.5）。外部への公開にあたるため未報告 |
| `main` へのマージ | まだしていない |

## 7. 作業の進め方で分かったこと

- **すべての主張を実物の PHP で確かめる**のがこのリポジトリの決まりです。公式記録や記憶と実機が食い違ったら、実機に合わせます（例は `docs/product-design.ja.md` 5.2）。
- 規則を大量に足すときは、`tools/workflows/` の台本で並行作業させました（Claude Code のワークフロー機能。7.1 参照）。区画ごとに「作る担当」と「反証する担当」を分けると、実際に誤りが見つかりました。
- 使用量の上限が厳しい場合は、一度に動かす担当を2区画（4人）程度に抑えてください。最初に14区画を一斉に動かしたときは、成果が出る前に上限に達しました。
- コンテナの再起動や上限で作業が中断しても、**確定（コミット）済みの分は失われません**。区画が終わるたびに、全版で検証してから確定するようにしてください。
- `php tests/run.php` の出力を `tail` で切ると、一部の版の失敗が見えなくなります（一度見落としかけました）。

### 7.1 ワークフローの台本

| ファイル | 内容 |
|---|---|
| `tools/workflows/cover-php-upgrade-notes.js` | 公式記録を14区画に分け、区画ごとに「台帳・検証用プログラム・規則を作る担当」と「反証する担当」を動かす |
| `tools/workflows/adjudicate-real-findings.js` | 実在コードで出た報告を、規則ごとに「実機で再現する担当」と「反証する担当」が判定する。引数は `adjudicate-real-findings.args.json`（前回の入力） |

どちらも先頭の `ROOT` がリポジトリの場所です。取り出した場所に合わせて書き換えてください。
作業用の一時ファイルは `tools/out/` の下に作らせるようにしてあります。
