# 引き継ぎ書（Upgrade Warden ／ freemius04）

最終更新: 2026-11-19（バージョン 1.0.0）

この文書だけを読めば、別のアカウント・別の環境で作業を再開できるように書いています。
詳細は次の文書にあります。

| 文書 | 内容 |
|---|---|
| `docs/release-guide.ja.md` | **販売開始と WordPress.org 申請の手順**、検証済み・未検証の一覧 |
| `docs/product-design.ja.md` | 商品の設計、確定事項、数字、制約 |
| `docs/market-research.ja.md` | なぜこの商品にしたか。有利・不利な事実と、その信頼度 |
| `docs/adding-rules.md` | 規則を追加するときの決まり（英語） |

---

## 1. 経緯

1. オーナーの条件「求められている／人手では作れない量／無料で完成できる」から、4候補を比べて「PHP のバージョンアップで壊れるプラグイン・テーマを検査して直す WordPress プラグイン」を選んだ。
2. オーナーから「全て任せる」と一任を受け、製品名 Upgrade Warden、価格 年額 $49／$99／$199・買い切り $149／$299／$599 で確定とした（2026-11-16）。
3. PHP 8.0〜8.6 の公式変更記録の全602項目に判定を付け、152規則を実物の PHP 7.4〜8.5 で確かめた。検査（Free）と修正・巻き戻し（Pro）が動くところまで作った（2026-11-17、別の環境）。
4. **2026-11-19（この環境・Windows）に仕上げた。** 残っていた「実行時の記録」と Pro の自動チェック・報告書を実装し、全規則に説明文を付け、日本語訳・readme・配布 ZIP の仕組み・無料版の作り分けを整え、本物の WordPress と審査ツールで確認した。内容は第3章。

## 2. 置き場所

| もの | 場所 |
|---|---|
| 手元の作業フォルダー | `Desktop\TY-Studio\Freemius\freemius04`（ブランチ `claude/nifty-mayer-tcwf37`） |
| GitHub | `TYStudio-JP/freemius04`、同じブランチ。**2026-11-19 の仕上げ分は手元で確定（コミット）しただけで、まだ push していません** |
| `main` ブランチ | 初期状態のまま（README のみ）。まだマージしていません |
| Freemius SDK | `vendor/freemius/`（git の対象外。03 の `checkout-warden/vendor/freemius` を複製） |
| ブラウザ確認の道具 | `Desktop\TY-Studio\Freemius\dev\upgw\`（リポジトリの外。`start.sh`、Plugin Check の結果 `pc-free-*.txt`） |
| 03（Kessai Guard） | `Desktop\TY-Studio\Freemius\checkout-warden`。参照しただけで変更していません |

## 3. 2026-11-19 に行ったこと

| 項目 | 内容 |
|---|---|
| **実行時の記録**（Free） | 記録をオンにすると、サイトが動いているあいだに PHP が出す非推奨の通知・致命的なエラーと、「あとの版でエラーになる」と分かっている PHP 7 の警告（`count( null )` など）を、ファイルと行番号つきで記録して説明する。説明の元は `data/catalog/messages.php`（実機の記録から自動生成、426メッセージ）。保存は7日・300件まで。既定はオフ |
| よくある不具合の検証 | PHP 8 で画面が真っ白になる代表例34件（数えられないものの `count()`、引数の型・個数の違い、存在しないコールバック、ゼロ除算、空の配列、`sprintf` の引数不足、文字列の添字）を検証用プログラムにして、7つの版で主張どおりと確認 |
| 記録機能そのものの検証 | `tools/verify-monitor.mjs`。全検証用プログラムを、記録機能を動かした状態で7つの版で実行し、拾えたか・説明が合っているかを照合。1,890メッセージで不一致0 |
| 全規則の説明文 | 画面で「規則 class.xxx」としか出なかった151規則に、英語と日本語の説明文を付けた（`tools/catalog-src/rule-text.json`）。説明文のない規則があるとテストが失敗する |
| Pro：自動チェック | 毎週の検査、プラグイン・テーマ更新後の検査、悪化したときのメール、記録に新しい停止エラーが入ったときのメール（1日1回）、記録の90日保存 |
| Pro：報告書 | 検査結果と記録を1つの HTML ファイルで書き出す（外部の読み込みなし、印刷可） |
| 日本語訳 | 281件すべて。`languages/src/ja.php`（手書き）と `ja-rules.php`（生成）から `.po`／`.mo` を作る |
| 販売の構成 | 無料版に Pro の痕跡を残さない構成、Freemius 経由のアンインストール、購入版フォルダー名 `upgrade-warden-premium`、ライセンス画面の日本語補完。03 の審査対応で分かった点をすべて反映 |
| readme.txt | WordPress.org 用。Freemius の通信を「External services」に記載 |
| 配布 ZIP | `node tools/build-zip.mjs`。Pro の ZIP と、Freemius が作る無料版の見本を出す |
| 審査ツール | Plugin Check 2.2.0 を無料版の見本に実行して、エラー0・警告0 |
| ブラウザ | 本物の WordPress（Playground、PHP 8.3）で、検査ボタン・進み具合・結果・記録の画面を実際に操作して確認 |
| Windows 対応 | 改行コードの固定（`.gitattributes`）、大文字小文字だけ違うファイル名3組の改名、PHP なしでも動かせる `tools/php.mjs`、Node の複製処理が落ちる問題の回避（`tools/lib/copy.mjs`） |
| 版 | 0.1.0 → **1.0.0** |
| 製品名の確認 | WordPress.org の公開 API とウェブ検索で、同じ名前のプラグイン・製品が見当たらないことを確認 |

**変えた場所に注意**：構文解析ライブラリは `lib/php-parser/` から **`vendor/php-parser/`** に移しました（第三者のコードとして審査ツールの対象外になる場所）。`uninstall.php` はなくなりました（Freemius の決まりで、`after_uninstall` から削除処理を呼ぶ）。

## 4. 動いていることの確かめ方

必要なもの：Node.js 20 以上、git。PHP は**なくても動きます**（`node tools/php.mjs <スクリプト>` が、実物の PHP 8.3 でリポジトリのスクリプトを実行します）。

```
bash tools/setup.sh --minimal   # テストと検証用プログラムに必要なものだけ
bash tools/setup.sh --wp        # 加えて、本物の WordPress と SQLite 連携（wp-check 用）
bash tools/setup.sh             # 加えて、誤報の確認に使った実在コード（有名プラグイン10本）
```

| 確認 | コマンド | 2026-11-19 の結果 |
|---|---|---|
| テスト（1つの版） | `node tools/php.mjs tests/run.php`（PHP があれば `php tests/run.php`） | 860件すべて通過 |
| テスト（7つの版） | `cd tools && node run-tests.mjs` | PHP 7.4〜8.5 の7版とも 860件すべて通過 |
| 検証用プログラム | `cd tools && node verify-fixtures.mjs` | 933件。今回足した34件は不一致0。**以前の899件は今回流し直していません**（記録 `*.observed.json` は前回のまま。約20分） |
| 実行時の記録 | `cd tools && node verify-monitor.mjs` | 1,890メッセージ、不一致0（約15分） |
| 本物の WordPress・Pro | `cd tools && node wp-check.mjs out/corpus/wp-latest out/corpus/sqlite-database-integration 7.4 8.2 8.5` | PHP 7.4：70項目、8.2：69項目、8.5：69項目、すべて通過 |
| 本物の WordPress・無料版 | 同じコマンドに `--free` を付ける | PHP 7.4：52項目、8.2：51項目、8.5：51項目、すべて通過 |
| 日本語訳 | `node tools/php.mjs bin/i18n.php check` | 281件、問題0 |
| 配布 ZIP | `node tools/build-zip.mjs` | Pro 650ファイル、無料版の見本 637ファイル。無料版に Pro への言及なし |
| 審査ツール | 下の「ブラウザと審査ツール」 | Plugin Check 2.2.0：エラー0・警告0（無料版の見本、SDK 同梱） |

### ブラウザと審査ツール

```
node tools/build-zip.mjs
bash ../dev/upgw/start.sh 9460 free        # または pro。http://127.0.0.1:9460/wp-admin/ が開く
```

- 審査ツール：ブラウザで `/bdcal-dev/install-plugin-check.php` → `/bdcal-dev/guard-pc-patch.php` を開いてから、`python -I -X utf8 ../dev/guard-plugin-check.py upgrade-warden/upgrade-warden.php http://127.0.0.1:9460`。**終わったらテストサイトを立て直す**（Plugin Check が残すファイルが Playground を壊す）。
- `build-zip.mjs` を流し直すと `build/` が作り直されるので、テストサイトも立て直します。

### 文言・規則を変えたときに作り直すもの

| 変えたもの | 流すコマンド |
|---|---|
| 画面の文言 | `node tools/php.mjs bin/i18n.php pot`、`languages/src/ja.php` に訳を足して `node tools/php.mjs bin/i18n.php ja` |
| 規則の説明文（`tools/catalog-src/rule-text.json`） | `node tools/build-ruletext.mjs` → 上の i18n の2つ |
| 検証用プログラム | `cd tools && node verify-fixtures.mjs <名前の一部>` → `node tools/php.mjs tools/build-messages.php` → `cd tools && node verify-monitor.mjs <名前の一部>` |
| 版 | `upgrade-warden.php` の Version と `UPGW_VERSION`、`readme.txt` の Stable tag と Changelog |

## 5. 残っている仕事

### 5.1 オーナーの操作・判断が要るもの

| # | 事項 | 補足 |
|---|---|---|
| 1 | **GitHub への push** | 仕上げ分は手元のコミットだけ。外部に出る操作なので確認待ち |
| 2 | **Freemius への製品登録** | 手順は `docs/release-guide.ja.md` の第2章。登録するとキー3つが決まり、`upgrade-warden.php` に入れる |
| 3 | **WordPress.org への申請** | Kessai Guard の審査が終わってから（1アカウント1件）。Freemius が生成した無料版で申請する |
| 4 | アイコン・バナー・スクリーンショット | まだ作っていません。申請の承認後に SVN に置くもの |
| 5 | Elementor の不具合を報告するか | 同梱の画像ライブラリの回転処理が PHP 8 以降で必ず失敗する（実機で再現済み、`docs/product-design.ja.md` 5.5）。外部への公開にあたるため未報告 |
| 6 | `main` へのマージ | まだしていません |

### 5.2 未検証（`docs/release-guide.ja.md` 1.2）

MySQL、Freemius が生成する無料版そのもの、SDK を有効にした状態、レンタルサーバーの PHP、PHP 8.6、エラーを扱う他のプラグインとの併用、実際のメール配信、マルチサイト、大きなサイト。

### 5.3 次の版の候補

| 内容 | 補足 |
|---|---|
| 検査の高速化 | WordPress 本体（1,900ファイル）で約16秒。管理画面では数秒ずつ分けて実行するので止まりはしない |
| 規則の精度 | 既知の限界は `tools/ledger/*.json` の各項目の reason。多くは「同じプラグインの別ファイルの定義を見ていない」こと |
| PHP 8.6 の実機検証 | php-wasm に 8.6 が入ったら、`tools/lib/runtimes.mjs` の `VERSIONS` に足して全検証を流す（2026-11-19 時点で npm に `@php-wasm/node-8-6` はない） |
| 実行時の記録の対象を広げる | いま説明できるのは、検証用プログラムに出てきたメッセージ（426）と、引数の型・個数の警告（「可能性が高い」と表示）。検証用プログラムを足せば自動で増える |
| 更新「前」の検査 | いまは更新の1分後に検査する。更新前に新しい版を調べて警告する機能は未着手 |

## 6. 作業の進め方で分かったこと

- **すべての主張を実物の PHP で確かめる**のがこのリポジトリの決まりです。公式記録や記憶と実機が食い違ったら、実機に合わせます（例は `docs/product-design.ja.md` 5.2）。
- 実行時の記録の対応表は、**検証用プログラムが「その変更そのもの」として出したメッセージだけ**を入れます。たまたま出た別の警告は入れません（`tools/build-messages.php` の先頭、`tools/catalog-src/early-signs.json` の rejected）。違う主張の検証用プログラムが同じメッセージを出したら、そのメッセージは対応表から外れます（`tools/out/messages-dropped.txt`）。
- サイト自身のコードが投げた例外（`Exception` の仲間）は記録しません。PHP の版と関係がなく、文面に顧客情報が入り得るためです。PHP 自身が投げる `Error` の仲間（`TypeError` など）だけ記録します。
- 規則を大量に足すときは、`tools/workflows/` の台本で並行作業させました（Claude Code のワークフロー機能）。区画ごとに「作る担当」と「反証する担当」を分けると、実際に誤りが見つかりました。先頭の `ROOT` を取り出した場所に書き換えてください。
- `tests/run.php` の出力を `tail` で切ると、一部の版の失敗が見えなくなります。
- Windows では、長いコマンドにバッククォートを含む内容を直接渡すと失敗することがあります。ファイルに書いてから実行します。
